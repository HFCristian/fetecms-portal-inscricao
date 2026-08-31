<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Cota;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
 *    "100 da FUNDECT, 20 para agrárias, 70% desses para o interior". A reserva
 *    do interior só existe na **FETECMS FUNDECT** — é exigência do fomento
 *    dela; nas demais categorias a lista é só por nota.
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

    public function __construct(private readonly RegistroAtividadeService $registros) {}

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
                    // Só a FUNDECT reserva vaga para o interior (regra do fomento).
                    'permite_interior' => $c->permiteCotaInterior(),
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
        return $this->blocos($this->gerar($cotas));
    }

    /**
     * O texto do arquivo a partir dos itens já numerados: um bloco por projeto,
     * separados por linha em branco.
     *
     * @param  list<array<string, mixed>>  $itens
     */
    private function blocos(array $itens): string
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
        }, $itens);

        return implode("\n\n", $blocos)."\n";
    }

    /**
     * Torna o recorte uma **lista final oficial**: grava a composição, marca-a
     * como vigente da edição (encerrando a anterior) e devolve a lista criada.
     *
     * A partir daí os projetos dela — e, por tabela, alunos, orientador e
     * coorientador — são os **finalistas** da feira.
     *
     * @param  array<string, mixed>  $cotas
     */
    public function oficializar(array $cotas, User $admin, ?string $nome = null): ListaFinal
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'oficial' => 'Nenhuma edição em curso para registrar a lista final.',
            ]);
        }

        $itens = $this->gerar($cotas);

        return DB::transaction(function () use ($cotas, $admin, $nome, $edicao, $itens) {
            // Uma vigente por edição: publicar a nova encerra a anterior. A
            // trilha demo (Sprint 88) é independente — ensaiar o balcão não
            // pode derrubar a lista oficial nem o contrário.
            ListaFinal::where('edicao_id', $edicao->id)
                ->where('demo', false)
                ->update(['vigente' => false]);

            $lista = ListaFinal::create([
                'edicao_id' => $edicao->id,
                'nome' => trim((string) $nome) !== '' ? trim((string) $nome) : $this->nomePadrao($edicao),
                'vigente' => true,
                'versao' => 1,
                'cotas' => $cotas,
                'gerada_por' => $admin->id,
            ]);

            $lista->projetos()->attach(
                collect($itens)->mapWithKeys(fn (array $i) => [$i['projeto_id'] => ['manual' => false]])->all(),
            );

            $this->registros->listaFinal(
                TipoRegistro::ListaFinalOficializada, $admin, $lista->nome, null, null,
                count($itens).' projeto(s)',
            );

            return $lista->fresh();
        });
    }

    /**
     * As listas oficiais já registradas na edição em curso, da mais nova para a
     * mais antiga.
     *
     * @return list<array<string, mixed>>
     */
    public function listasOficiais(): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        return ListaFinal::where('edicao_id', $edicao->id)
            // A lista de demonstração não é histórico oficial: ela existe só
            // para o balcão em modo de teste.
            ->where('demo', false)
            ->with('autor:id,name')
            ->withCount('projetos')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ListaFinal $l) => [
                'id' => $l->id,
                'nome' => $l->nome,
                'vigente' => $l->vigente,
                'versao' => $l->versao,
                'projetos' => $l->projetos_count,
                'gerada_por' => $l->autor?->name,
                'criada_em' => $l->created_at?->toIso8601String(),
                'atualizada_em' => $l->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Os itens de uma lista **já registrada** — ordenados e numerados na hora,
     * a partir da composição gravada. É o que o TXT de uma lista oficial
     * exporta, e o que muda de conteúdo quando o admin altera a composição.
     *
     * @return list<array<string, mixed>>
     */
    public function itensDaLista(ListaFinal $lista): array
    {
        $ids = $lista->projetos()->pluck('projetos.id')->all();

        if ($ids === []) {
            return [];
        }

        $projetos = $this->avaliados()->whereIn('id', $ids);

        // Projeto que entrou à mão pode não ter avaliação concluída: ele não
        // aparece em avaliados(), então é buscado à parte.
        $faltantes = array_diff($ids, $projetos->pluck('id')->all());

        if ($faltantes !== []) {
            $projetos = $projetos->concat($this->porIds($faltantes));
        }

        return $this->numerar($this->ordenar($projetos->values()->all()));
    }

    /**
     * Acrescenta um projeto à lista oficial. Sobe a versão (o arquivo baixado
     * depois é outro) e entra na trilha com a justificativa — é uma decisão
     * fora do recorte por nota, então precisa ficar explicada.
     */
    public function adicionarProjeto(ListaFinal $lista, Projeto $projeto, User $admin, string $justificativa): ListaFinal
    {
        if ($lista->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto já está na lista.',
            ]);
        }

        return DB::transaction(function () use ($lista, $projeto, $admin, $justificativa) {
            $lista->projetos()->attach($projeto->id, ['manual' => true]);
            $lista->increment('versao');

            $this->registros->listaFinal(
                TipoRegistro::ListaFinalProjetoAdicionado, $admin, $lista->nome, $projeto, trim($justificativa),
            );

            return $lista->fresh();
        });
    }

    /** Retira um projeto da lista oficial, com justificativa, e sobe a versão. */
    public function removerProjeto(ListaFinal $lista, Projeto $projeto, User $admin, string $justificativa): ListaFinal
    {
        if (! $lista->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto não está na lista.',
            ]);
        }

        return DB::transaction(function () use ($lista, $projeto, $admin, $justificativa) {
            $lista->projetos()->detach($projeto->id);
            $lista->increment('versao');

            $this->registros->listaFinal(
                TipoRegistro::ListaFinalProjetoRemovido, $admin, $lista->nome, $projeto, trim($justificativa),
            );

            return $lista->fresh();
        });
    }

    /**
     * A lista aberta para edição: a composição atual e os projetos avaliados
     * que ainda podem entrar.
     *
     * @return array<string, mixed>
     */
    public function detalhar(ListaFinal $lista): array
    {
        $itens = $this->itensDaLista($lista);
        $dentro = array_column($itens, 'projeto_id');
        $manuais = $lista->projetos()->pluck('lista_final_projetos.manual', 'projetos.id');

        return [
            'lista' => [
                'id' => $lista->id,
                'nome' => $lista->nome,
                'vigente' => $lista->vigente,
                'versao' => $lista->versao,
                'projetos' => count($itens),
            ],
            'itens' => array_map(fn (array $i) => $i + ['manual' => (bool) ($manuais[$i['projeto_id']] ?? false)], $itens),
            // Candidatos: quem foi avaliado e ainda está de fora.
            'candidatos' => $this->avaliados()
                ->reject(fn (Projeto $p) => in_array($p->id, $dentro, true))
                ->map(fn (Projeto $p) => [
                    'id' => $p->id,
                    'titulo' => $p->titulo,
                    'categoria' => $p->categoria?->label(),
                    'area' => $p->area?->nome,
                    'media' => $p->media_nota === null ? null : round((float) $p->media_nota, 2),
                ])
                ->values()
                ->all(),
        ];
    }

    /** O TXT de uma lista oficial já registrada. */
    public function exportarTxtDaLista(ListaFinal $lista): string
    {
        return $this->blocos($this->itensDaLista($lista));
    }

    /** Nome sugerido quando o admin não informa um. */
    private function nomePadrao(Edicao $edicao): string
    {
        return 'Lista final · '.$edicao->nome;
    }

    /**
     * Projetos por id, com as mesmas relações de avaliados() — para os que
     * entraram na lista sem avaliação concluída.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Projeto>
     */
    private function porIds(array $ids): Collection
    {
        return Projeto::query()
            ->whereIn('id', $ids)
            ->withAvg(
                ['avaliacoes as media_nota' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value)],
                'nota',
            )
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
            ->get();
    }

    /**
     * Projetos com ao menos uma avaliação concluída, do melhor para o pior —
     * o mesmo universo e a mesma ordem do Ranking dos projetos.
     *
     * @return Collection<int, Projeto>
     */
    private function avaliados(): Collection
    {
        // Projeto de orientador demo não disputa vaga na feira.
        return Projeto::semDemo()
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

                // A reserva do interior só existe onde a categoria a permite —
                // hoje, a FETECMS FUNDECT. Nas outras, o campo é ignorado.
                if (! (Categoria::tryFrom((string) $categoria)?->permiteCotaInterior() ?? false)) {
                    continue;
                }

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
