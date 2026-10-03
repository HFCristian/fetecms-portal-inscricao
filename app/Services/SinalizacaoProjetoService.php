<?php

namespace App\Services;

use App\Enums\StatusSuporte;
use App\Models\CredenciamentoForaPrazo;
use App\Models\SuporteProjeto;

/**
 * O que as equipes do evento precisam saber de um projeto **antes** de olhar
 * para ele (Sprints 161–162): o **credenciamento fora do prazo** aprovado — com
 * a data prevista de chegada — e os **suportes aprovados** (acompanhante,
 * intérprete).
 *
 * É um lugar só porque as mesmas marcas aparecem em várias telas —
 * credenciamento, checagem de estande, avaliação presencial do admin e do
 * avaliador, mapa —, e cada uma decidir sozinha o que mostrar acabaria em selos
 * diferentes para a mesma informação. Quem lista chama {@see self::para()} uma
 * vez com os ids da página e pendura o resultado em cada linha.
 *
 * Pedido de suporte **pendente ou recusado não aparece**: o selo diz a quem
 * está no balcão o que foi combinado, não o que alguém pediu.
 */
class SinalizacaoProjetoService
{
    /**
     * @param  iterable<int>  $projetoIds
     * @return array<int, array{fora_prazo: ?array<string, mixed>, suportes: list<array<string, mixed>>}>
     */
    public function para(iterable $projetoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($projetoIds) ? $projetoIds : iterator_to_array($projetoIds))));

        if ($ids === []) {
            return [];
        }

        $foraPrazo = CredenciamentoForaPrazo::whereIn('projeto_id', $ids)->get()->keyBy('projeto_id');
        $suportes = SuporteProjeto::whereIn('projeto_id', $ids)
            ->where('status', StatusSuporte::Aprovado->value)
            ->with('aluno:id,nome')
            ->orderBy('id')
            ->get()
            ->groupBy('projeto_id');

        $mapa = [];

        foreach ($ids as $id) {
            $fp = $foraPrazo->get($id);
            $lista = $suportes->get($id, collect());

            if ($fp === null && $lista->isEmpty()) {
                continue;
            }

            $mapa[$id] = [
                'fora_prazo' => $fp === null ? null : [
                    'previsto_em' => $fp->previsto_em?->toIso8601String(),
                    'previsto_label' => $fp->previsto_em?->format('d/m/Y H:i'),
                    'observacao' => $fp->observacao,
                ],
                'suportes' => $lista->map(fn (SuporteProjeto $s) => [
                    'id' => $s->id,
                    'tipo' => $s->tipo->value,
                    'tipo_label' => $s->tipo->label(),
                    'resumo' => $s->resumo(),
                    'acompanhante' => $s->acompanhante_nome,
                    'documento' => $s->acompanhante_documento,
                ])->values()->all(),
            ];
        }

        return $mapa;
    }

    /** @return array{fora_prazo: ?array<string, mixed>, suportes: list<array<string, mixed>>}|null */
    public function de(int $projetoId): ?array
    {
        return $this->para([$projetoId])[$projetoId] ?? null;
    }
}
