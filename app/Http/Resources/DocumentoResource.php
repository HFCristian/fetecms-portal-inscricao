<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProjetoDocumento */
class DocumentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'tipo_label' => $this->tipo->label(),
            'nome_original' => $this->nome_original,
            'mime' => $this->mime,
            'tamanho_bytes' => $this->tamanho_bytes,
            // URLs autenticadas (nunca expõem o path interno do storage).
            'download_url' => url("/api/v1/documentos/{$this->id}/download"),
            'preview_url' => url("/api/v1/documentos/{$this->id}/preview"),
            'is_pdf' => $this->mime === 'application/pdf',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
