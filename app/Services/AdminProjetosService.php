<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Models\Projeto;
use Illuminate\Support\Collection;

class AdminProjetosService
{
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

    /**
     * Cada grupo já traz os números do card da área: quantos submetidos e
     * quantos ainda em rascunho (aprovado/rejeitado entram só no total).
     *
     * @param  Collection<int, Projeto>  $itens
     */
    private function montarGrupo(Collection $itens): array
    {
        $area = $itens->first()->area;
        $comStatus = fn (ProjetoStatus $s) => $itens->where('status', $s)->count();

        return [
            'area_id' => $area?->id,
            'area' => $area?->nome ?? 'Área ainda não informada',
            'total' => $itens->count(),
            'submetidos' => $comStatus(ProjetoStatus::Submetido),
            'rascunho' => $comStatus(ProjetoStatus::Rascunho),
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
