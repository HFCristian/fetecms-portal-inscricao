<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Subarea;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestão do catálogo de áreas/subáreas pelo admin (tela "Parametrização"):
 * renomear, mesclar (reatribuindo todas as referências) e excluir. É a contrapartida
 * da criação livre de subáreas — permite limpar duplicatas/ruído com segurança.
 */
class CatalogoAdminService
{
    /** Tabelas que referenciam subarea_id. */
    private const TABELAS_SUBAREA = ['projetos', 'avaliador_profiles', 'orientador_profiles'];

    /** Tabelas que referenciam area_id (subareas é tratada à parte na mescla de áreas). */
    private const TABELAS_AREA = ['projetos', 'avaliador_profiles', 'orientador_profiles'];

    /** Árvore de áreas → subáreas com a contagem de usos de cada uma. */
    public function arvore(): array
    {
        $usosArea = $this->contarUsos(self::TABELAS_AREA, 'area_id');
        $usosSub = $this->contarUsos(self::TABELAS_SUBAREA, 'subarea_id');

        return Area::with('subareas')->orderBy('nome')->get()->map(fn (Area $a) => [
            'id' => $a->id,
            'nome' => $a->nome,
            'usos' => $usosArea[$a->id] ?? 0,
            'subareas' => $a->subareas->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn (Subarea $s) => [
                    'id' => $s->id,
                    'nome' => $s->nome,
                    'usos' => $usosSub[$s->id] ?? 0,
                ])->values(),
        ])->all();
    }

    public function renomearArea(Area $area, string $nome): void
    {
        $area->update(['nome' => $nome]);
        $this->limparCache();
    }

    public function renomearSubarea(Subarea $subarea, string $nome): void
    {
        $subarea->update(['nome' => $nome]);
    }

    /** Mescla a subárea origem na destino (mesma área): reatribui referências e exclui a origem. */
    public function mesclarSubareas(Subarea $origem, Subarea $destino): void
    {
        DB::transaction(function () use ($origem, $destino) {
            foreach (self::TABELAS_SUBAREA as $tabela) {
                DB::table($tabela)->where('subarea_id', $origem->id)->update(['subarea_id' => $destino->id]);
            }
            $origem->delete();
        });
    }

    /** Mescla a área origem na destino: move/mescla as subáreas, reatribui referências e exclui. */
    public function mesclarAreas(Area $origem, Area $destino): void
    {
        DB::transaction(function () use ($origem, $destino) {
            foreach ($origem->subareas as $sub) {
                $homonima = Subarea::where('area_id', $destino->id)
                    ->whereRaw('LOWER(nome) = LOWER(?)', [$sub->nome])
                    ->first();

                if ($homonima) {
                    foreach (self::TABELAS_SUBAREA as $tabela) {
                        DB::table($tabela)->where('subarea_id', $sub->id)->update(['subarea_id' => $homonima->id]);
                    }
                    $sub->delete();
                } else {
                    $sub->update(['area_id' => $destino->id]);
                }
            }

            foreach (self::TABELAS_AREA as $tabela) {
                DB::table($tabela)->where('area_id', $origem->id)->update(['area_id' => $destino->id]);
            }

            $origem->delete();
        });

        $this->limparCache();
    }

    public function excluirSubarea(Subarea $subarea): void
    {
        if ($this->contar(self::TABELAS_SUBAREA, 'subarea_id', $subarea->id) > 0) {
            throw ValidationException::withMessages([
                'subarea' => 'Esta subárea está em uso. Mescle-a em outra antes de excluir.',
            ]);
        }
        $subarea->delete();
    }

    public function excluirArea(Area $area): void
    {
        if ($area->subareas()->exists()) {
            throw ValidationException::withMessages([
                'area' => 'Esta área possui subáreas. Mescle ou remova as subáreas primeiro.',
            ]);
        }
        if ($this->contar(self::TABELAS_AREA, 'area_id', $area->id) > 0) {
            throw ValidationException::withMessages([
                'area' => 'Esta área está em uso. Mescle-a em outra antes de excluir.',
            ]);
        }
        $area->delete();
        $this->limparCache();
    }

    /** Usos de um id específico somando todas as tabelas. */
    private function contar(array $tabelas, string $coluna, int $id): int
    {
        return collect($tabelas)->sum(fn ($t) => DB::table($t)->where($coluna, $id)->count());
    }

    /** Mapa id => total de usos (somado entre as tabelas), em poucas queries. */
    private function contarUsos(array $tabelas, string $coluna): array
    {
        $acc = [];
        foreach ($tabelas as $tabela) {
            $linhas = DB::table($tabela)
                ->select($coluna, DB::raw('count(*) as total'))
                ->whereNotNull($coluna)
                ->groupBy($coluna)
                ->get();
            foreach ($linhas as $linha) {
                $acc[$linha->$coluna] = ($acc[$linha->$coluna] ?? 0) + $linha->total;
            }
        }

        return $acc;
    }

    private function limparCache(): void
    {
        Cache::forget('catalogo.areas');
    }
}
