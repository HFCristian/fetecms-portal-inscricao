<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\VagaPresencial;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Support\JanelaTurnos;
use App\Support\RubricaPresencial;

/**
 * Avaliação presencial → **Ranking presencial** (Sprint 222).
 *
 * A nota do estande é separada da online e serve à premiação. Cada projeto
 * recebe avaliações em dois tipos de vaga — comuns e da comissão especial —, e
 * a da comissão **pesa mais** na média (padrão 2, {@see JanelaTurnos}):
 *
 *     média = Σ (nota × peso) / Σ peso
 *
 * O peso sai da vaga gravada em cada avaliação, e não de quem avaliou hoje: o
 * avaliador da comissão que entrou numa vaga comum (a da comissão já estava
 * tomada) pesa 1.
 *
 * Entram os finalistas da lista que vale (a demo no modo de teste) com ao
 * menos uma avaliação presencial enviada. "Parcial" é quem ainda não tem todas
 * as vagas preenchidas: a média existe, mas pode mudar. Empate na média divide
 * a posição, como no ranking online.
 */
class RankingPresencialService
{
    public function __construct(private readonly ListaFinalService $listas) {}

    /**
     * @param  array{area_id?: int|null, categoria?: string|null}  $filtros
     * @return array<string, mixed>
     */
    public function ranking(array $filtros = [], bool $demo = false): array
    {
        $edicao = Edicao::atual();
        $janela = JanelaTurnos::daEdicao($edicao);
        $lista = ListaFinal::vigente($edicao, $demo);

        return [
            'lista' => $lista === null ? null : ['id' => $lista->id, 'nome' => $lista->nome, 'demo' => (bool) $lista->demo],
            'vagas' => [
                'comuns' => $janela->comunsPorProjeto(),
                'comite' => $janela->comitePorProjeto(),
                'peso_comite' => $janela->pesoComite(),
            ],
            'projetos' => $lista === null ? [] : $this->linhas($lista, $janela, $filtros),
            'opcoes' => [
                'areas' => Area::orderBy('nome')->get(['id', 'nome'])->map(fn (Area $a) => ['id' => $a->id, 'nome' => $a->nome])->all(),
                'categorias' => Categoria::opcoes(),
            ],
        ];
    }

    /**
     * @param  array{area_id?: int|null, categoria?: string|null}  $filtros
     * @return list<array<string, mixed>>
     */
    private function linhas(ListaFinal $lista, JanelaTurnos $janela, array $filtros): array
    {
        $projetos = Projeto::whereIn('id', $lista->projetos()->pluck('projetos.id'))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($filtros['categoria'] ?? null, fn ($q, $categoria) => $q->where('categoria', $categoria))
            ->with('area:id,nome')
            ->get(['id', 'titulo', 'area_id', 'categoria'])
            ->keyBy('id');

        $avaliacoes = AvaliacaoPresencial::whereIn('projeto_id', $projetos->keys())
            ->where('status', StatusAvaliacao::Concluida->value)
            ->whereNotNull('nota')
            ->orderBy('concluida_em')
            ->get(['id', 'projeto_id', 'nota', 'vaga'])
            ->groupBy('projeto_id');

        $codigos = $this->listas->codigosDaLista($lista);
        $estandes = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)
            ->whereIn('projeto_id', $avaliacoes->keys())
            ->get(['projeto_id', 'numero', 'turno'])
            ->keyBy('projeto_id');

        $linhas = [];

        foreach ($avaliacoes as $projetoId => $doProjeto) {
            $projeto = $projetos[$projetoId] ?? null;

            if ($projeto === null) {
                continue;
            }

            $soma = 0.0;
            $pesos = 0.0;
            $notas = [];

            foreach ($doProjeto as $a) {
                $vaga = $a->vaga ?? VagaPresencial::Comum;
                $peso = $janela->peso($vaga);
                $soma += $a->nota * $peso;
                $pesos += $peso;
                $notas[] = ['nota' => $a->nota, 'vaga' => $vaga->value, 'peso' => $peso];
            }

            $comuns = count(array_filter($notas, fn (array $n) => $n['vaga'] === VagaPresencial::Comum->value));
            $comite = count($notas) - $comuns;
            $estande = $estandes[$projetoId] ?? null;

            $linhas[] = [
                'projeto_id' => $projeto->id,
                'codigo' => $codigos[$projeto->id] ?? null,
                'titulo' => $projeto->titulo,
                'area' => $projeto->area?->nome,
                'categoria' => $projeto->categoria?->label(),
                'estande' => $estande?->numero,
                'turno_label' => $estande?->turno?->label(),
                'media' => round($soma / $pesos, RubricaPresencial::CASAS),
                'avaliacoes' => count($notas),
                'comuns' => $comuns,
                'comite' => $comite,
                'notas' => $notas,
                'parcial' => $comuns < $janela->comunsPorProjeto() || $comite < $janela->comitePorProjeto(),
            ];
        }

        usort($linhas, fn (array $a, array $b) => [$b['media'], $b['avaliacoes'], $a['titulo']]
            <=> [$a['media'], $a['avaliacoes'], $b['titulo']]);

        // Posição atribuída depois da ordenação; empate na média divide o lugar.
        $posicao = 0;
        $anterior = null;
        foreach ($linhas as $i => &$linha) {
            if ($linha['media'] !== $anterior) {
                $posicao = $i + 1;
                $anterior = $linha['media'];
            }
            $linha['posicao'] = $posicao;
        }
        unset($linha);

        return $linhas;
    }
}
