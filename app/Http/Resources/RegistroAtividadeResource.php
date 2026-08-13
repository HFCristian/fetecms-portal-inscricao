<?php

namespace App\Http\Resources;

use App\Models\RegistroAtividade;
use App\Services\RegistroAtividadeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RegistroAtividade */
class RegistroAtividadeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'tipo_label' => $this->tipo->label(),
            'ocorrido_em' => $this->created_at?->toIso8601String(),
            'autor_email' => $this->autor_email,
            'autor_nome' => $this->autor_nome,
            'autor_role' => $this->autor_role,
            'projeto_id' => $this->projeto_id,
            'projeto_titulo' => $this->projeto_titulo,
            'projeto_categoria' => $this->projeto_categoria,
            'dono_email' => $this->dono_email,
            'dono_nome' => $this->dono_nome,
            'por_terceiro' => $this->porTerceiro(),
            'detalhes' => $this->detalhes,
            'detalhes_texto' => app(RegistroAtividadeService::class)->descreverDetalhes($this->resource),
        ];
    }
}
