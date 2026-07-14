<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AvaliadorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de avaliadores para o painel do admin (seção "Avaliadores"):
 * totais e distribuição por área do conhecimento.
 */
class AdminAvaliadoresService
{
    /**
     * @return array{total:int, ativos:int, inativos:int, por_area: array<int, array{area_id:int, area:string, total:int}>}
     */
    public function metricas(): array
    {
        $total = User::where('role', Role::Avaliador->value)->count();
        $ativos = User::where('role', Role::Avaliador->value)->where('is_active', true)->count();

        // Todo avaliador tem uma área (obrigatória no cadastro): a soma de por_area = total.
        $porArea = AvaliadorProfile::query()
            ->join('areas', 'areas.id', '=', 'avaliador_profiles.area_id')
            ->select('areas.id', 'areas.nome', DB::raw('count(*) as total'))
            ->groupBy('areas.id', 'areas.nome')
            ->orderByDesc('total')
            ->orderBy('areas.nome')
            ->get()
            ->map(fn ($linha) => [
                'area_id' => (int) $linha->id,
                'area' => $linha->nome,
                'total' => (int) $linha->total,
            ])
            ->all();

        return [
            'total' => $total,
            'ativos' => $ativos,
            'inativos' => $total - $ativos,
            'por_area' => $porArea,
        ];
    }
}
