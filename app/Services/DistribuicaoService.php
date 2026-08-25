<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Distribuição automática de projetos para avaliação (E7).
 *
 * Guloso com balanceamento de carga, idempotente (re-executável): só COMPLETA
 * cada projeto até o alvo, sem tocar em avaliações já existentes. Casa por
 * subárea (preferencial) → área → área IRMÃ (mesmo grupo de correlação, só
 * quando a própria área se esgota). Ignora avaliadores demo, inativos e sem
 * área; respeita o limite individual de cada avaliador. Projetos que não
 * fecham o alvo entram no relatório de "sub-cobertos" para o admin resolver.
 */
class DistribuicaoService
{
    /**
     * @return array{designadas_criadas:int, sub_cobertos: array<int, array{projeto_id:int, titulo:string, area:?string, faltam:int}>}
     */
    public function distribuir(): array
    {
        // Mínimos parametrizados pelo admin (Parametrização → Avaliação Online).
        $alvo = Edicao::minPorProjeto();
        $teto = max(Avaliacao::TETO_POR_PROJETO, $alvo);

        $avaliadores = $this->carregarAvaliadores(Edicao::minPorAvaliador());
        [$cargaInicial, $projetoInfo] = $this->estadoAtual($avaliadores);

        // Aplica a carga já existente e monta índice de avaliadores por área. O
        // avaliador aparece na sua área e em cada área extra liberada pelo admin.
        $porArea = [];
        foreach ($avaliadores as $id => $av) {
            $avaliadores[$id]['carga'] = $cargaInicial[$id] ?? 0;
            foreach ($av['areas'] as $areaId) {
                $porArea[$areaId][] = $id;
            }
        }

        $projetos = $this->carregarProjetos($projetoInfo);
        $correlatas = $this->mapaCorrelatas();

        // Disponíveis dentre uma lista: com folga no limite e ainda não designados.
        $disponiveis = function (array $ids, array $proj) use (&$avaliadores): array {
            return array_values(array_filter(
                $ids,
                fn ($id) => $avaliadores[$id]['carga'] < $avaliadores[$id]['capacidade']
                    && ! isset($proj['assigned'][$id])
            ));
        };

        // Avaliadores das áreas irmãs do projeto (vazio quando a área não tem grupo).
        $irmaos = fn (array $proj) => array_merge(
            ...array_map(fn ($areaId) => $porArea[$areaId] ?? [], $correlatas[$proj['area_id']] ?? []),
        );

        // Elegíveis de um projeto: a própria área manda; só quando ela se esgota
        // o projeto cai para as áreas irmãs (mesmo grupo de correlação).
        $elegiveis = function (array $proj) use ($disponiveis, $irmaos, $porArea): array {
            $proprios = $disponiveis($porArea[$proj['area_id']] ?? [], $proj);

            return $proprios !== [] ? $proprios : $disponiveis($irmaos($proj), $proj);
        };

        // Ordena os projetos por escassez (menos elegíveis primeiro; empate: menor
        // cobertura). A escassez olha o pool inteiro — própria área + irmãs —, que é
        // de onde o projeto pode de fato ser servido.
        foreach ($projetos as &$p) {
            $p['elegiveis_ini'] = count($disponiveis($porArea[$p['area_id']] ?? [], $p))
                + count($disponiveis($irmaos($p), $p));
        }
        unset($p);
        usort($projetos, fn ($a, $b) => ($a['elegiveis_ini'] <=> $b['elegiveis_ini']) ?: ($a['coverage'] <=> $b['coverage']));

        $novas = [];
        $subCobertos = [];

        foreach ($projetos as &$proj) {
            while ($proj['coverage'] < $alvo && $proj['coverage'] < $teto) {
                $cands = $elegiveis($proj);
                if ($cands === []) {
                    break;
                }

                // Preferência: subárea igual → menor carga → id (desempate estável).
                $casaSubarea = fn ($id) => $proj['subarea_id'] !== null
                    && in_array([$proj['area_id'], $proj['subarea_id']], $avaliadores[$id]['pares'], true) ? 1 : 0;

                usort($cands, function ($x, $y) use (&$avaliadores, $casaSubarea) {
                    return ($casaSubarea($y) <=> $casaSubarea($x))
                        ?: (($avaliadores[$x]['carga'] <=> $avaliadores[$y]['carga']) ?: ($x <=> $y));
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

            if ($proj['coverage'] < $alvo) {
                $subCobertos[] = [
                    'projeto_id' => $proj['id'],
                    'titulo' => $proj['titulo'],
                    'area' => $proj['area_nome'],
                    'faltam' => $alvo - $proj['coverage'],
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

    /**
     * Mapa área → áreas IRMÃS (as outras do mesmo grupo de correlação). Área sem
     * grupo não aparece no mapa: ela nunca recebe avaliador de fora.
     *
     * @return array<int, list<int>>
     */
    private function mapaCorrelatas(): array
    {
        $mapa = [];

        Area::query()
            ->whereNotNull('grupo_correlato')
            ->get(['id', 'grupo_correlato'])
            ->groupBy(fn (Area $a) => $a->grupo_correlato->value)
            ->each(function ($areas) use (&$mapa) {
                $ids = $areas->pluck('id')->all();

                foreach ($ids as $id) {
                    $mapa[$id] = array_values(array_diff($ids, [$id]));
                }
            });

        return $mapa;
    }

    /**
     * Avaliadores elegíveis: ativos, não-demo e com ao menos uma área (a própria
     * ou uma liberada pelo admin). A capacidade é o limite individual do
     * avaliador ou, sem limite, o mínimo por avaliador da edição.
     */
    private function carregarAvaliadores(int $minPorAvaliador): array
    {
        $avaliadores = [];

        User::query()
            ->where('role', Role::Avaliador->value)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->with(['avaliadorProfile:id,user_id,area_id,subarea_id,limite_avaliacoes', 'avaliadorProfile.areasExtras'])
            ->get(['id'])
            ->each(function (User $u) use (&$avaliadores, $minPorAvaliador) {
                $perfil = $u->avaliadorProfile;
                $areas = $perfil?->areasAtendidas() ?? [];

                if ($areas === []) {
                    return; // sem área nenhuma não participa da distribuição automática
                }

                $avaliadores[$u->id] = [
                    'id' => $u->id,
                    // A própria área mais as extras liberadas pelo admin.
                    'areas' => $areas,
                    'pares' => $perfil->paresAtendidos(),
                    'capacidade' => $perfil->limite_avaliacoes ?? $minPorAvaliador,
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
