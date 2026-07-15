<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Distribuição automática de projetos para avaliação (E7).
 *
 * Guloso com balanceamento de carga, idempotente (re-executável): só COMPLETA
 * cada projeto até o alvo, sem tocar em avaliações já existentes. Casa por
 * subárea (preferencial) → área (fallback). Ignora avaliadores demo, inativos
 * e sem área; respeita o limite individual de cada avaliador. Projetos que não
 * fecham o alvo entram no relatório de "sub-cobertos" para o admin resolver.
 */
class DistribuicaoService
{
    /** Alvo de avaliadores por projeto e teto de visibilidade. */
    private const ALVO = 3;

    private const TETO = 5;

    /**
     * @return array{designadas_criadas:int, sub_cobertos: array<int, array{projeto_id:int, titulo:string, area:?string, faltam:int}>}
     */
    public function distribuir(): array
    {
        $avaliadores = $this->carregarAvaliadores();
        [$cargaInicial, $projetoInfo] = $this->estadoAtual($avaliadores);

        // Aplica a carga já existente e monta índice de avaliadores por área.
        $porArea = [];
        foreach ($avaliadores as $id => $av) {
            $avaliadores[$id]['carga'] = $cargaInicial[$id] ?? 0;
            $porArea[$av['area_id']][] = $id;
        }

        $projetos = $this->carregarProjetos($projetoInfo);

        // Elegíveis de um projeto: mesma área, com folga no limite, ainda não designado.
        $elegiveis = function (array $proj) use (&$avaliadores, $porArea): array {
            $ids = $porArea[$proj['area_id']] ?? [];

            return array_values(array_filter(
                $ids,
                fn ($id) => $avaliadores[$id]['carga'] < $avaliadores[$id]['capacidade']
                    && ! isset($proj['assigned'][$id])
            ));
        };

        // Ordena os projetos por escassez (menos elegíveis primeiro; empate: menor cobertura).
        foreach ($projetos as &$p) {
            $p['elegiveis_ini'] = count($elegiveis($p));
        }
        unset($p);
        usort($projetos, fn ($a, $b) => ($a['elegiveis_ini'] <=> $b['elegiveis_ini']) ?: ($a['coverage'] <=> $b['coverage']));

        $novas = [];
        $subCobertos = [];

        foreach ($projetos as &$proj) {
            while ($proj['coverage'] < self::ALVO && $proj['coverage'] < self::TETO) {
                $cands = $elegiveis($proj);
                if ($cands === []) {
                    break;
                }

                // Preferência: subárea igual → menor carga → id (desempate estável).
                usort($cands, function ($x, $y) use (&$avaliadores, $proj) {
                    $tx = ($avaliadores[$x]['subarea_id'] !== null && $avaliadores[$x]['subarea_id'] === $proj['subarea_id']) ? 1 : 0;
                    $ty = ($avaliadores[$y]['subarea_id'] !== null && $avaliadores[$y]['subarea_id'] === $proj['subarea_id']) ? 1 : 0;

                    return ($ty <=> $tx) ?: (($avaliadores[$x]['carga'] <=> $avaliadores[$y]['carga']) ?: ($x <=> $y));
                });

                $escolhido = $cands[0];
                $avaliadores[$escolhido]['carga']++;
                $proj['coverage']++;
                $proj['assigned'][$escolhido] = true;

                $novas[] = [
                    'projeto_id' => $proj['id'],
                    'avaliador_id' => $escolhido,
                    'status' => StatusAvaliacao::Designada->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($proj['coverage'] < self::ALVO) {
                $subCobertos[] = [
                    'projeto_id' => $proj['id'],
                    'titulo' => $proj['titulo'],
                    'area' => $proj['area_nome'],
                    'faltam' => self::ALVO - $proj['coverage'],
                ];
            }
        }
        unset($proj);

        if ($novas !== []) {
            DB::table('avaliacoes')->insert($novas);
        }

        return [
            'designadas_criadas' => count($novas),
            'sub_cobertos' => $subCobertos,
        ];
    }

    /** Avaliadores elegíveis: ativos, não-demo, com área. capacidade = limite ?? 3. */
    private function carregarAvaliadores(): array
    {
        $avaliadores = [];

        User::query()
            ->where('role', Role::Avaliador->value)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->with('avaliadorProfile:id,user_id,area_id,subarea_id,limite_avaliacoes')
            ->get(['id'])
            ->each(function (User $u) use (&$avaliadores) {
                $perfil = $u->avaliadorProfile;
                if (! $perfil || ! $perfil->area_id) {
                    return; // sem área não participa da distribuição automática
                }

                $avaliadores[$u->id] = [
                    'id' => $u->id,
                    'area_id' => $perfil->area_id,
                    'subarea_id' => $perfil->subarea_id,
                    'capacidade' => $perfil->limite_avaliacoes ?? self::ALVO,
                    'carga' => 0,
                ];
            });

        return $avaliadores;
    }

    /**
     * Carga atual por avaliador real e, por projeto, cobertura (avaliações de
     * avaliadores reais) + conjunto de já designados (real + demo, p/ dedupe).
     *
     * @return array{0: array<int,int>, 1: array<int, array{coverage:int, assigned: array<int,bool>}>}
     */
    private function estadoAtual(array $avaliadores): array
    {
        $carga = [];
        $projeto = [];

        Avaliacao::query()->get(['projeto_id', 'avaliador_id'])->each(function ($a) use ($avaliadores, &$carga, &$projeto) {
            $real = isset($avaliadores[$a->avaliador_id]);
            if ($real) {
                $carga[$a->avaliador_id] = ($carga[$a->avaliador_id] ?? 0) + 1;
            }
            $projeto[$a->projeto_id]['coverage'] = ($projeto[$a->projeto_id]['coverage'] ?? 0) + ($real ? 1 : 0);
            $projeto[$a->projeto_id]['assigned'][$a->avaliador_id] = true;
        });

        return [$carga, $projeto];
    }

    private function carregarProjetos(array $projetoInfo): array
    {
        return Projeto::query()
            ->where('status', ProjetoStatus::Submetido->value)
            ->with('area:id,nome')
            ->get(['id', 'titulo', 'area_id', 'subarea_id'])
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area_id' => $p->area_id,
                'subarea_id' => $p->subarea_id,
                'area_nome' => $p->area?->nome,
                'coverage' => $projetoInfo[$p->id]['coverage'] ?? 0,
                'assigned' => $projetoInfo[$p->id]['assigned'] ?? [],
            ])
            ->all();
    }
}
