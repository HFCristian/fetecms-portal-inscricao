<?php

namespace App\Http\Resources;

use App\Models\MalaDiretaDestinatario;
use App\Services\MalaDiretaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MalaDiretaDestinatario */
class MalaDiretaDestinatarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'nome' => $this->nome,
            'papel' => $this->papel,
            'papel_label' => app(MalaDiretaService::class)->rotuloPapel($this->papel) ?: null,
            'origens' => $this->origens ?? [],
            'origens_labels' => array_map(
                fn (string $o) => app(MalaDiretaService::class)->rotuloOrigem($o),
                $this->origens ?? [],
            ),
            'projetos_total' => $this->projetos_total,
            'projetos_titulos' => $this->projetos_titulos ?? [],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'erro' => $this->erro,
            'enviado_em' => $this->enviado_em?->toIso8601String(),
        ];
    }
}
