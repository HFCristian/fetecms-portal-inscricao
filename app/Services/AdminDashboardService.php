<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AdminDashboardService
{
    /** As métricas do painel do admin (+ recorte por gênero de pessoas). */
    public function metricas(): array
    {
        // Escolas/cidades/estados: contam apenas entre projetos SUBMETIDOS.
        $submetidos = fn () => Projeto::where('status', ProjetoStatus::Submetido->value);

        $orientadores = User::where('role', Role::Orientador->value)->count();
        $alunos = Aluno::count();
        $coorientadores = Coorientador::count();

        return [
            'projetos_total' => Projeto::count(),
            'projetos_submetidos' => Projeto::where('status', ProjetoStatus::Submetido->value)->count(),
            'projetos_rascunho' => Projeto::where('status', ProjetoStatus::Rascunho->value)->count(),
            'orientadores' => $orientadores,
            'alunos' => $alunos,
            'coorientadores' => $coorientadores,
            // Recorte por gênero: F (mulheres), M (homens) e "outros" (NB/O/P/nulo),
            // calculado como total − F − M para a soma sempre fechar com o total.
            'orientadores_genero' => $this->porGenero(OrientadorProfile::query(), $orientadores),
            'alunos_genero' => $this->porGenero(Aluno::query(), $alunos),
            'coorientadores_genero' => $this->porGenero(Coorientador::query(), $coorientadores),
            'escolas_com_projeto' => $submetidos()->whereNotNull('instituicao_id')->distinct()->count('instituicao_id'),
            'cidades_com_projeto' => $submetidos()->whereNotNull('cidade_id')->distinct()->count('cidade_id'),
            'estados_com_projeto' => $submetidos()->whereNotNull('estado_id')->distinct()->count('estado_id'),
        ];
    }

    /**
     * Contagem por gênero a partir da coluna `genero`. "outros" agrupa NB, Outro,
     * Prefiro não informar e nulos (total − F − M), garantindo soma = total.
     *
     * @return array{f:int, m:int, outros:int}
     */
    private function porGenero(Builder $query, int $total): array
    {
        $f = (clone $query)->where('genero', 'F')->count();
        $m = (clone $query)->where('genero', 'M')->count();

        return ['f' => $f, 'm' => $m, 'outros' => max(0, $total - $f - $m)];
    }
}
