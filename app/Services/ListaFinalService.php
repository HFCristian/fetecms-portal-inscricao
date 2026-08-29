<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Projeto;
use App\Support\Cota;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lista final da feira (Avaliação Online → Ranking dos projetos): o recorte dos
 * projetos que vão para a programação, em TXT.
 *
 * Duas etapas independentes:
 *
 * 1. SELEÇÃO — desce o ranking (média das notas finais, do melhor para o pior)
 *    e vai pegando quem cabe nas cotas que o admin definiu. As cotas são
 *    ANINHADAS: um total geral, dentro dele a cota de cada **categoria**,
 *    dentro dela a cota de cada **área** e, dentro da área, quantas vagas ficam
 *    reservadas ao **interior** (cidade que não é a capital do estado). Cada
 *    uma pode ser número fixo ou porcentagem do recorte que a contém — é o
 *    "100 da FUNDECT, 20 para agrárias, 70% desses para o interior".
 *
 *    Cota em branco não limita nada; cota 0 deixa o recorte de fora. A reserva
 *    do interior é PISO, não teto: se não houver projeto do interior suficiente,
 *    as vagas que sobram voltam para os demais numa segunda passada — a área
 *    nunca entrega menos do que sua cota por causa da reserva.
 *
 * 2. ORDENAÇÃO — o arquivo NÃO sai na ordem da nota: sai por categoria
 *    (FETECMS, FETEC Jr, FETECMS FUNDECT), depois por área em ordem alfabética
 *    e, dentro de cada categoria+área, por título. A numeração 001, 002…
 *    reinicia a cada par categoria+área e é gerada na hora da exportação.
 */
class ListaFinalService
{
    /** Sigla de projeto sem categoria ou sem área — visível de propósito, para o admin corrigir. */
    private const SIGLA_AUSENTE = 'SEM';

    /**
     * Projetos elegíveis e quantos há em cada recorte, para a tela sugerir
     * cotas que existem.
     *
     * @return array<string, mixed>
     */
    public function opcoes(): array
    {
        $projetos = $this->avaliados();
        $areas = Area::query()->orderBy('nome')->get(['id', 'nome', 'sigla']);

        $porCategoria = $projetos->groupBy(fn (Projeto $p) => $p->categoria?->value ?? '');

        return [
            'total_disponivel' => $projetos->count(),
            'interior_disponivel' => $projetos->filter(fn (Projeto $p) => $this->doInterior($p))->count(),
            // As áreas saem DENTRO de cada categoria: a cota da área é uma
            // fatia da cota da categoria, e a do interior, uma fatia da área.
            'categorias' => array_map(function (Categoria $c) use ($porCategoria, $areas) {
                $daCategoria = $porCategoria->get($c->value, collect());
                $porArea = $daCategoria->groupBy(fn (Projeto $p) => $p->area_id ?? 0);

                return [
                    'value' => $c->value,
                    'label' => $c->label(),
                    'sigla' => $c->sigla(),
                    'disponiveis' => $daCategoria->count(),
                    'areas' => $areas->map(function (Area $a) use ($porArea) {
                        $daArea = $porArea->get($a->id, collect());

                        return [
                            'id' => $a->id,
                            'nome' => $a->nome,
                            'sigla' => $a->siglaDaLista(),
                            'disponiveis' => $daArea->count(),
                            'interior_disponiveis' => $daArea->filter(fn (Projeto $p) => $this->doInterior($p))->count(),
                        ];
                    })->values()->all(),
                ];
            }, Categoria::ordemDaLista()),
        ];
    }

    /**
     * Os projetos da lista, já ordenados e numerados.
     *
     * @param  array<string, mixed>  $cotas  ver selecionar()
     * @return list<array<string, mixed>>
     */
    public function gerar(array $cotas): array
    {
        $selecionados = $this->selecionar($this->avaliados(), $cotas);

        return $this->numerar($this->ordenar($selecionados));
    }

    /**
     * O TXT da lista: um bloco por projeto, separados por linha em branco.
     *
     * @param  array<string, mixed>  $cotas  ver selecionar()
     */
    public function exportarTxt(array $cotas): string
    {
        $blocos = array_map(function (array $item) {
            $linhas = [
                "{$item['codigo']} - {$item['titulo']}",
                $item['escola'],
                ...$item['alunos'],
            ];

            if ($item['orientador'] !== null) {
                $linhas[] = $item['orientador'].' - Orientador(a)';
            }

            return implode("\n", $linhas);
        }, $this->gerar($cotas));

        return implode("\n\n", $blocos)."\n";
    }

    /**
     * Projetos com ao menos uma avaliação concluída, do melhor para o pior —
     * o mesmo universo e a mesma ordem do Ranking dos projetos.
     *
     * @return Collection<int, Projeto>
     */
    private function avaliados(): Collection
    {
        return Projeto::query()
            ->whereHas('avaliacoes', fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value))
            ->withAvg(
                ['avaliacoes as media_nota' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value)],
                'nota',
            )
            ->withCount(['avaliacoes as concluidas_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value)])
            ->with([
                'area:id,nome,sigla',
                'user:id,name',
                'alunos:id,projeto_id,nome',
                'instituicao:id,nome,cidade_id',
                'instituicao.cidade:id,nome,estado_id,capital',
                'instituicao.cidade.estado:id,uf',
                'cidade:id,nome,capital',
                'estado:id,uf',
            ])
            ->get()
            // Empate na média cai para quem tem mais avaliações e depois título.
            ->sortBy([
                fn (Projeto $a, Projeto $b) => (float) $b->media_nota <=> (float) $a->media_nota,
                fn (Projeto $a, Projeto $b) => $b->concluidas_count <=> $a->concluidas_count,
                fn (Projeto $a, Projeto $b) => strcmp($this->chave($a->titulo), $this->chave($b->titulo)),
            ])
            ->values();
    }

    /**
     * Desce o ranking pegando quem ainda cabe nas cotas aninhadas.
     *
     * O payload é
     * `['total' => cota, 'categorias' => ['fetecms' => ['cota' => cota,
     * 'areas' => [7 => ['cota' => cota, 'interior' => cota]]]]]`, onde cada
     * `cota` é `['tipo' => 'fixo'|'percentual', 'valor' => n]` ou null.
     *
     * São duas passadas: a primeira respeita a reserva do interior (um projeto
     * da capital não ocupa vaga reservada); a segunda devolve aos demais as
     * vagas que o interior não preencheu, para a cota da área nunca render
     * menos do que foi pedido.
     *
     * @param  Collection<int, Projeto>  $projetos
     * @param  array<string, mixed>  $cotas
     * @return list<Projeto>
     */
    private function selecionar(Collection $projetos, array $cotas): array
    {
        $limites = $this->resolverCotas($projetos, $cotas);

        $usado = ['total' => 0, 'categoria' => [], 'area' => [], 'capital' => []];
        $escolhidos = [];
        $adiados = [];

        foreach ($projetos as $projeto) {
            $encaixe = $this->encaixe($projeto, $limites, $usado, true);

            if ($encaixe === 'cabe') {
                $this->contabilizar($projeto, $usado);
                $escolhidos[] = $projeto;
            } elseif ($encaixe === 'reservado') {
                // Vaga existe, mas está guardada para o interior. Se sobrar, ele
                // volta na segunda passada.
                $adiados[] = $projeto;
            }
        }

        foreach ($adiados as $projeto) {
            if ($this->encaixe($projeto, $limites, $usado, false) === 'cabe') {
                $this->contabilizar($projeto, $usado);
                $escolhidos[] = $projeto;
            }
        }

        return $escolhidos;
    }

    /**
     * Traduz as cotas do formulário em número de vagas, de fora para dentro:
     * a porcentagem da categoria é sobre o total, a da área sobre a categoria e
     * a do interior sobre a área. Sem cota acima, a base é quantos projetos
     * elegíveis existem naquele recorte.
     *
     * @param  Collection<int, Projeto>  $projetos
     * @param  array<string, mixed>  $cotas
     * @return array{total:?int, categoria:array<string,int>, area:array<string,int>, interior:array<string,int>}
     */
    private function resolverCotas(Collection $projetos, array $cotas): array
    {
        $total = Cota::de($cotas['total'] ?? null)?->resolver($projetos->count());
        $baseCategoria = $total ?? $projetos->count();

        $limites = ['total' => $total, 'categoria' => [], 'area' => [], 'interior' => []];

        foreach ((array) ($cotas['categorias'] ?? []) as $categoria => $config) {
            $config = (array) $config;
            $daCategoria = $projetos->filter(fn (Projeto $p) => $p->categoria?->value === $categoria);

            $cotaCategoria = Cota::de($config['cota'] ?? null)?->resolver($baseCategoria);
            if ($cotaCategoria !== null) {
                $limites['categoria'][$categoria] = $cotaCategoria;
            }

            foreach ((array) ($config['areas'] ?? []) as $areaId => $areaConfig) {
                $areaConfig = (array) $areaConfig;
                $chave = $categoria.'|'.$areaId;

                $cotaArea = Cota::de($areaConfig['cota'] ?? null)
                    ?->resolver($cotaCategoria ?? $daCategoria->count());

                if ($cotaArea === null) {
                    // Sem cota na área não há vaga para reservar: a cota do
                    // interior só faz sentido dentro de um número fechado.
                    continue;
                }

                $limites['area'][$chave] = $cotaArea;

                $reserva = Cota::de($areaConfig['interior'] ?? null)?->resolver($cotaArea);
                if ($reserva !== null) {
                    $limites['interior'][$chave] = min($reserva, $cotaArea);
                }
            }
        }

        return $limites;
    }

    /**
     * O projeto cabe agora? Devolve 'cabe', 'reservado' (só a reserva do
     * interior barrou — pode voltar na segunda passada) ou 'nao'.
     *
     * @param  array{total:?int, categoria:array<string,int>, area:array<string,int>, interior:array<string,int>}  $limites
     * @param  array{total:int, categoria:array<string,int>, area:array<string,int>, capital:array<string,int>}  $usado
     */
    private function encaixe(Projeto $projeto, array $limites, array $usado, bool $respeitarReserva): string
    {
        if ($limites['total'] !== null && $usado['total'] >= $limites['total']) {
            return 'nao';
        }

        $categoria = $projeto->categoria?->value;
        if ($categoria !== null && isset($limites['categoria'][$categoria])
            && ($usado['categoria'][$categoria] ?? 0) >= $limites['categoria'][$categoria]) {
            return 'nao';
        }

        $chave = $categoria.'|'.(int) $projeto->area_id;
        if (! isset($limites['area'][$chave])) {
            return 'cabe';
        }

        if (($usado['area'][$chave] ?? 0) >= $limites['area'][$chave]) {
            return 'nao';
        }

        // Reserva do interior: um projeto da capital só ocupa as vagas que
        // sobram depois de guardadas as do interior.
        $reserva = $limites['interior'][$chave] ?? 0;
        if ($respeitarReserva && $reserva > 0 && ! $this->doInterior($projeto)) {
            $paraCapital = $limites['area'][$chave] - $reserva;

            if (($usado['capital'][$chave] ?? 0) >= $paraCapital) {
                return 'reservado';
            }
        }

        return 'cabe';
    }

    /**
     * @param  array{total:int, categoria:array<string,int>, area:array<string,int>, capital:array<string,int>}  $usado
     */
    private function contabilizar(Projeto $projeto, array &$usado): void
    {
        $categoria = $projeto->categoria?->value;
        $chave = $categoria.'|'.(int) $projeto->area_id;

        $usado['total']++;
        $usado['categoria'][$categoria] = ($usado['categoria'][$categoria] ?? 0) + 1;
        $usado['area'][$chave] = ($usado['area'][$chave] ?? 0) + 1;

        if (! $this->doInterior($projeto)) {
            $usado['capital'][$chave] = ($usado['capital'][$chave] ?? 0) + 1;
        }
    }

    /**
     * Projeto do interior: a cidade dele não é a capital do próprio estado.
     * Vale a cidade da escola, que é a que aparece na lista; sem escola, a do
     * projeto. Cidade desconhecida não conta como interior.
     */
    private function doInterior(Projeto $projeto): bool
    {
        $cidade = $projeto->instituicao?->cidade ?? $projeto->cidade;

        return $cidade !== null && ! $cidade->capital;
    }

    /**
     * Ordem do arquivo: categoria (FETECMS → FETEC Jr → FUNDECT), área em ordem
     * alfabética e título. Projeto sem categoria ou sem área vai para o fim do
     * seu grupo.
     *
     * @param  list<Projeto>  $projetos
     * @return list<Projeto>
     */
    private function ordenar(array $projetos): array
    {
        $ordemCategoria = array_flip(array_map(fn (Categoria $c) => $c->value, Categoria::ordemDaLista()));

        usort($projetos, function (Projeto $a, Projeto $b) use ($ordemCategoria) {
            $ca = $ordemCategoria[$a->categoria?->value] ?? PHP_INT_MAX;
            $cb = $ordemCategoria[$b->categoria?->value] ?? PHP_INT_MAX;

            return [$ca, $this->chave($a->area?->nome ?? 'zzz'), $this->chave($a->titulo)]
                <=> [$cb, $this->chave($b->area?->nome ?? 'zzz'), $this->chave($b->titulo)];
        });

        return $projetos;
    }

    /**
     * Monta a linha de cada projeto. O sequencial reinicia a cada par
     * categoria+área e é gerado agora, na criação da lista.
     *
     * @param  list<Projeto>  $projetos
     * @return list<array<string, mixed>>
     */
    private function numerar(array $projetos): array
    {
        $sequencias = [];

        return array_map(function (Projeto $projeto) use (&$sequencias) {
            $categoria = $projeto->categoria?->sigla() ?? self::SIGLA_AUSENTE;
            $area = $projeto->area?->siglaDaLista() ?? self::SIGLA_AUSENTE;
            $par = $categoria.'.'.$area;

            $sequencias[$par] = ($sequencias[$par] ?? 0) + 1;
            $numero = str_pad((string) $sequencias[$par], 3, '0', STR_PAD_LEFT);

            return [
                'projeto_id' => $projeto->id,
                'codigo' => $par.'-'.$numero,
                'titulo' => $projeto->titulo,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                'escola' => $this->escola($projeto),
                'alunos' => $projeto->alunos
                    ->sortBy(fn ($aluno) => $this->chave($aluno->nome))
                    ->pluck('nome')
                    ->values()
                    ->all(),
                'orientador' => $projeto->user?->name,
                'media' => $projeto->media_nota === null ? null : round((float) $projeto->media_nota, 2),
            ];
        }, $projetos);
    }

    /**
     * A linha da escola: "Escola / Cidade - UF". Sem instituição cadastrada,
     * vale a localidade do próprio projeto.
     */
    private function escola(Projeto $projeto): string
    {
        $instituicao = $projeto->instituicao;

        $nome = $instituicao?->nome;
        $cidade = $instituicao?->cidade?->nome ?? $projeto->cidade?->nome ?? $projeto->cidade_nome;
        $uf = $instituicao?->cidade?->estado?->uf ?? $projeto->estado?->uf ?? $projeto->estado_nome;

        $local = trim(implode(' - ', array_filter([$cidade, $uf])));
        $partes = array_filter([$nome, $local === '' ? null : $local]);

        return $partes === [] ? '—' : implode(' / ', $partes);
    }

    /** Chave de ordenação tolerante a acento e caixa (pt-BR sem depender de collation). */
    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
