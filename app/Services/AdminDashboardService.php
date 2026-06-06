<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\Projeto;
use App\Models\User;

class AdminDashboardService
{
    /** As 9 métricas do painel do admin. */
    public function metricas(): array
    {
        // Escolas/cidades/estados: contam apenas entre projetos SUBMETIDOS.
        $submetidos = fn () => Projeto::where('status', ProjetoStatus::Submetido->value);

        return [
            'projetos_total' => Projeto::count(),
            'projetos_submetidos' => Projeto::where('status', ProjetoStatus::Submetido->value)->count(),
            'projetos_rascunho' => Projeto::where('status', ProjetoStatus::Rascunho->value)->count(),
            'orientadores' => User::where('role', Role::Orientador->value)->count(),
            'alunos' => Aluno::count(),
            'coorientadores' => Coorientador::count(),
            'escolas_com_projeto' => $submetidos()->whereNotNull('instituicao_id')->distinct()->count('instituicao_id'),
            'cidades_com_projeto' => $submetidos()->whereNotNull('cidade_id')->distinct()->count('cidade_id'),
            'estados_com_projeto' => $submetidos()->whereNotNull('estado_id')->distinct()->count('estado_id'),
        ];
    }
}
