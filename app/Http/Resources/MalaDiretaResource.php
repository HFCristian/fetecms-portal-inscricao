<?php

namespace App\Http\Resources;

use App\Models\MalaDireta;
use App\Services\MalaDiretaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MalaDireta */
class MalaDiretaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Na listagem os totais vêm do withCount; no detalhe (polling do
        // progresso) são recontados na hora.
        $totais = $this->resource->total_destinatarios === null
            ? $this->resource->totais()
            : [
                'total' => (int) $this->total_destinatarios,
                'enviado' => (int) $this->total_enviados,
                'falha' => (int) $this->total_falhas,
                'invalido' => (int) $this->total_invalidos,
                'pendente' => (int) $this->total_destinatarios - (int) $this->total_enviados
                    - (int) $this->total_falhas - (int) $this->total_invalidos,
            ];
        $totais['processados'] = $totais['total'] - $totais['pendente'];

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'justificativa' => $this->justificativa,
            'solicitante' => $this->solicitante,
            'assunto' => $this->assunto,
            'corpo' => $this->corpo,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'publicos' => $this->publicos ?? [],
            'publicos_labels' => array_map(
                fn (string $p) => app(MalaDiretaService::class)->rotuloOrigem($p),
                $this->publicos ?? [],
            ),
            'emails_personalizados' => $this->emails_personalizados,
            'autor_nome' => $this->autor_nome,
            'autor_email' => $this->autor_email,
            'enviado_em' => $this->enviado_em?->toIso8601String(),
            'concluido_em' => $this->concluido_em?->toIso8601String(),
            'totais' => $totais,
        ];
    }
}
