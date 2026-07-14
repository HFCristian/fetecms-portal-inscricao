<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Projeto;
use App\Models\User;

/**
 * Telas de "Avaliação online" do admin (E7). O algoritmo de distribuição ainda
 * não existe, então os números vêm da tabela `avaliacoes` (zerados por enquanto).
 */
class AdminAvaliacaoService
{
    /**
     * Avaliadores agrupados por área, com o progresso de cada um:
     * em_avaliacao (em andamento agora, 0 ou 1), avaliou (concluídas) e
     * faltam (3 − avaliou, mínimo 0).
     *
     * @return array<int, array{area_id:int, area:string, avaliadores:array}>
     */
    public function avaliadoresPorArea(): array
    {
        $avaliadores = User::query()
            ->where('role', Role::Avaliador->value)
            ->with('avaliadorProfile.area:id,nome')
            ->withCount([
                'avaliacoes as em_avaliacao_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
                'avaliacoes as avaliou_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->orderBy('name')
            ->get();

        $grupos = [];
        foreach ($avaliadores as $u) {
            $area = $u->avaliadorProfile?->area;
            $chave = $area?->id ?? 0;
            $grupos[$chave] ??= ['area_id' => (int) ($area?->id ?? 0), 'area' => $area?->nome ?? 'Sem área', 'avaliadores' => []];

            $avaliou = (int) $u->avaliou_count;
            $grupos[$chave]['avaliadores'][] = [
                'id' => $u->id,
                'nome' => $u->name,
                'em_avaliacao' => (int) $u->em_avaliacao_count,
                'avaliou' => $avaliou,
                'faltam' => max(0, StatusAvaliacao::MAX_POR_AVALIADOR - $avaliou),
            ];
        }

        return $this->ordenarPorArea($grupos);
    }

    /**
     * Projetos submetidos agrupados por área, com quantas avaliações (concluídas)
     * cada um já recebeu.
     *
     * @return array<int, array{area_id:int, area:string, projetos:array}>
     */
    public function projetosSubmetidosPorArea(): array
    {
        $projetos = Projeto::query()
            ->where('status', ProjetoStatus::Submetido->value)
            ->with('area:id,nome')
            ->withCount(['avaliacoes as avaliacoes_recebidas' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value)])
            ->orderBy('titulo')
            ->get();

        $grupos = [];
        foreach ($projetos as $p) {
            $area = $p->area;
            $chave = $area?->id ?? 0;
            $grupos[$chave] ??= ['area_id' => (int) ($area?->id ?? 0), 'area' => $area?->nome ?? 'Sem área', 'projetos' => []];

            $grupos[$chave]['projetos'][] = [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'avaliacoes_recebidas' => (int) $p->avaliacoes_recebidas,
            ];
        }

        return $this->ordenarPorArea($grupos);
    }

    /** Ordena os grupos por nome da área (mantendo "Sem área" no fim). */
    private function ordenarPorArea(array $grupos): array
    {
        $lista = array_values($grupos);
        usort($lista, function ($a, $b) {
            if ($a['area_id'] === 0) {
                return 1;
            }
            if ($b['area_id'] === 0) {
                return -1;
            }

            return strcmp($a['area'], $b['area']);
        });

        return $lista;
    }
}
