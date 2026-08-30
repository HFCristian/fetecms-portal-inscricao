<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Camisetas;
use App\Support\ClassesEscolares;
use Illuminate\Database\Eloquent\Builder;

class AdminDashboardService
{
    /** As métricas do painel do admin (+ recorte por gênero de pessoas). */
    public function metricas(): array
    {
        // Quase todo card do painel olha apenas para projetos SUBMETIDOS: quem
        // está em rascunho ainda pode desistir, então não entra na contagem de
        // categoria, de pessoas nem de escolas/cidades/estados. As exceções são
        // os dois primeiros cards ("Projetos (total)" e "Projetos por status"),
        // que existem justamente para mostrar o rascunho.
        $submetido = fn (Builder $q) => $q->where('status', ProjetoStatus::Submetido->value);
        $submetidos = fn () => Projeto::where('status', ProjetoStatus::Submetido->value);

        // Orientador conta uma vez só, tenha ele um ou vários projetos submetidos.
        $orientadoresSubmetidos = User::where('role', Role::Orientador->value)
            ->whereHas('projetos', $submetido);

        $orientadores = (clone $orientadoresSubmetidos)->count();
        $alunos = Aluno::whereHas('projeto', $submetido)->count();
        $coorientadores = Coorientador::whereHas('projeto', $submetido)->count();

        return [
            'projetos_total' => Projeto::count(),
            'projetos_submetidos' => $submetidos()->count(),
            'projetos_rascunho' => Projeto::where('status', ProjetoStatus::Rascunho->value)->count(),
            'projetos_categoria' => $this->porCategoria(),
            'orientadores' => $orientadores,
            'alunos' => $alunos,
            'coorientadores' => $coorientadores,
            // Recorte por gênero: F (mulheres), M (homens) e "outros" (NB/O/P/nulo),
            // calculado como total − F − M para a soma sempre fechar com o total.
            // Cada query cobre exatamente o mesmo conjunto contado acima.
            'orientadores_genero' => $this->porGenero(
                OrientadorProfile::whereIn('user_id', (clone $orientadoresSubmetidos)->select('id')),
                $orientadores
            ),
            'alunos_genero' => $this->porGenero(Aluno::whereHas('projeto', $submetido), $alunos),
            'coorientadores_genero' => $this->porGenero(Coorientador::whereHas('projeto', $submetido), $coorientadores),
            // Camisetas: mesmo conjunto de pessoas dos cards acima, quebrado por
            // tamanho — é o número que a organização usa para encomendar.
            'orientadores_camisetas' => Camisetas::contar(
                OrientadorProfile::whereIn('user_id', (clone $orientadoresSubmetidos)->select('id')),
                $orientadores
            ),
            'alunos_camisetas' => Camisetas::contar(Aluno::whereHas('projeto', $submetido), $alunos),
            // Alunos por classe escolar (Fundamental I, Fundamental II e Médio,
            // com o técnico integrado dentro do médio), quebrados por série.
            // Mesmo recorte dos demais cards: só projetos submetidos.
            'alunos_classes' => ClassesEscolares::contar(Aluno::whereHas('projeto', $submetido)),
            'coorientadores_camisetas' => Camisetas::contar(Coorientador::whereHas('projeto', $submetido), $coorientadores),
            'escolas_com_projeto' => $submetidos()->whereNotNull('instituicao_id')->distinct()->count('instituicao_id'),
            'cidades_com_projeto' => $submetidos()->whereNotNull('cidade_id')->distinct()->count('cidade_id'),
            'estados_com_projeto' => $submetidos()->whereNotNull('estado_id')->distinct()->count('estado_id'),
        ];
    }

    /**
     * Projetos SUBMETIDOS por categoria da feira. Sai sempre com todas as
     * categorias, na ordem do enum, mesmo as zeradas. Projeto submetido sempre
     * tem categoria (é item do checklist), então a soma fecha com
     * `projetos_submetidos`.
     *
     * @return list<array{value: string, label: string, total: int}>
     */
    private function porCategoria(): array
    {
        $totais = Projeto::query()
            ->where('status', ProjetoStatus::Submetido->value)
            ->whereNotNull('categoria')
            ->groupBy('categoria')
            ->selectRaw('categoria, count(*) as total')
            ->pluck('total', 'categoria');

        return array_map(fn (Categoria $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'total' => (int) ($totais[$c->value] ?? 0),
        ], Categoria::cases());
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
