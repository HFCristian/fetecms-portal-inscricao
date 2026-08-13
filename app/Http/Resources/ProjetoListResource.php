<?php

namespace App\Http\Resources;

use App\Models\Projeto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Projeto */
class ProjetoListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'categoria' => $this->categoria?->value,
            'categoria_label' => $this->categoria?->label(),
            'max_alunos' => $this->maxAlunos(),
            'instituicao' => $this->whenLoaded('instituicao', fn () => $this->instituicao?->nome),
            'area' => $this->whenLoaded('area', fn () => $this->area?->nome),
            // Submissão ainda reversível (nenhuma avaliação iniciada e período de
            // avaliação não começado) — habilita cancelar/excluir na listagem.
            'pode_desfazer' => (bool) $this->resource->getAttribute('pode_desfazer'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
