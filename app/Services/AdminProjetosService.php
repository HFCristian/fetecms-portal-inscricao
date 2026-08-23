<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Models\Projeto;
use Illuminate\Support\Collection;

class AdminProjetosService
{
    /**
     * Tela "Projetos por área": os cards por categoria da feira, no topo, e os
     * grupos por área do conhecimento (compactáveis) embaixo.
     *
     * @return array{categorias: list<array<string, mixed>>, areas: array<int, array<string, mixed>>}
     */
    public function painel(): array
    {
        return [
            'categorias' => $this->porCategoria(),
            'areas' => $this->porArea(),
        ];
    }

    /**
     * Submetidos e rascunhos de cada categoria da feira. Sai sempre com todas as
     * categorias, na ordem do enum, mesmo as zeradas. Rascunho ainda sem
     * categoria escolhida não entra em nenhuma — por isso a soma pode ficar
     * abaixo do total de projetos.
     *
     * @return list<array{value: string, label: string, submetidos: int, rascunho: int, total: int}>
     */
    public function porCategoria(): array
    {
        $totais = Projeto::query()
            ->whereNotNull('categoria')
            ->groupBy('categoria', 'status')
            ->selectRaw('categoria, status, count(*) as total')
            ->get()
            ->groupBy('categoria');

        return array_map(function (Categoria $c) use ($totais) {
            $linhas = $totais[$c->value] ?? collect();
            $de = fn (ProjetoStatus $s) => (int) ($linhas->firstWhere('status', $s->value)->total ?? 0);

            return [
                'value' => $c->value,
                'label' => $c->label(),
                'submetidos' => $de(ProjetoStatus::Submetido),
                'rascunho' => $de(ProjetoStatus::Rascunho),
                'total' => (int) $linhas->sum('total'),
            ];
        }, Categoria::cases());
    }

    /**
     * Projetos agrupados pela área do conhecimento (inclui rascunhos).
     * Projetos sem área caem no grupo "Área ainda não informada", que é
     * sempre listado por último. Dentro de cada grupo, o mais recente vem antes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function porArea(): array
    {
        return Projeto::query()
            ->with(['area:id,nome'])
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy(fn (Projeto $p) => $p->area_id ?? 0)
            ->map(fn (Collection $itens) => $this->montarGrupo($itens))
            ->sortBy(fn (array $g) => $g['area_id'] === null ? "\u{FFFF}" : mb_strtolower($g['area']))
            ->values()
            ->all();
    }

    /** @param  Collection<int, Projeto>  $itens */
    private function montarGrupo(Collection $itens): array
    {
        $area = $itens->first()->area;

        return [
            'area_id' => $area?->id,
            'area' => $area?->nome ?? 'Área ainda não informada',
            'total' => $itens->count(),
            'projetos' => $itens->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'categoria_label' => $p->categoria?->label(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
