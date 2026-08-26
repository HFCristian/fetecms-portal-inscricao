<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Projeto;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lista final da feira (Avaliação Online → Ranking dos projetos): o recorte dos
 * projetos que vão para a programação, em TXT.
 *
 * Duas etapas independentes:
 *
 * 1. SELEÇÃO — desce o ranking (média das notas finais, do melhor para o pior)
 *    e vai pegando quem cabe nas cotas que o admin definiu: um total geral, um
 *    teto por categoria e um teto por área. Cota em branco é cota sem limite,
 *    então "só os 30 melhores" e "5 de cada área" convivem no mesmo pedido.
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

        $porCategoria = $projetos->groupBy(fn (Projeto $p) => $p->categoria?->value ?? '');
        $porArea = $projetos->groupBy(fn (Projeto $p) => $p->area_id ?? 0);

        return [
            'total_disponivel' => $projetos->count(),
            'categorias' => array_map(fn (Categoria $c) => [
                'value' => $c->value,
                'label' => $c->label(),
                'sigla' => $c->sigla(),
                'disponiveis' => $porCategoria->get($c->value, collect())->count(),
            ], Categoria::ordemDaLista()),
            'areas' => Area::query()
                ->orderBy('nome')
                ->get(['id', 'nome', 'sigla'])
                ->map(fn (Area $a) => [
                    'id' => $a->id,
                    'nome' => $a->nome,
                    'sigla' => $a->siglaDaLista(),
                    'disponiveis' => $porArea->get($a->id, collect())->count(),
                ])->all(),
        ];
    }

    /**
     * Os projetos da lista, já ordenados e numerados.
     *
     * @param  array{total?:int|null, categorias?:array<string,int|null>, areas?:array<int|string,int|null>}  $cotas
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
     * @param  array{total?:int|null, categorias?:array<string,int|null>, areas?:array<int|string,int|null>}  $cotas
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
                'instituicao.cidade:id,nome,estado_id',
                'instituicao.cidade.estado:id,uf',
                'cidade:id,nome',
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
     * Desce o ranking pegando quem ainda cabe em todas as cotas. Cota ausente
     * (ou nula) não limita nada.
     *
     * @param  Collection<int, Projeto>  $projetos
     * @param  array{total?:int|null, categorias?:array<string,int|null>, areas?:array<int|string,int|null>}  $cotas
     * @return list<Projeto>
     */
    private function selecionar(Collection $projetos, array $cotas): array
    {
        $restaTotal = $cotas['total'] ?? null;
        $porCategoria = array_filter($cotas['categorias'] ?? [], fn ($v) => $v !== null);
        $porArea = array_filter($cotas['areas'] ?? [], fn ($v) => $v !== null);

        $escolhidos = [];

        foreach ($projetos as $projeto) {
            if ($restaTotal !== null && $restaTotal <= 0) {
                break;
            }

            $categoria = $projeto->categoria?->value;
            $area = $projeto->area_id;

            if ($categoria !== null && array_key_exists($categoria, $porCategoria) && $porCategoria[$categoria] <= 0) {
                continue;
            }

            if ($area !== null && array_key_exists($area, $porArea) && $porArea[$area] <= 0) {
                continue;
            }

            $escolhidos[] = $projeto;

            if ($restaTotal !== null) {
                $restaTotal--;
            }
            if ($categoria !== null && array_key_exists($categoria, $porCategoria)) {
                $porCategoria[$categoria]--;
            }
            if ($area !== null && array_key_exists($area, $porArea)) {
                $porArea[$area]--;
            }
        }

        return $escolhidos;
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
