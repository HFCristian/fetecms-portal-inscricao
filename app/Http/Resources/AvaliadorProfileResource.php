<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AvaliadorProfile */
class AvaliadorProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'cpf' => $this->cpf,
            'titulacao' => $this->titulacao,
            'area_id' => $this->area_id,
            'subarea_id' => $this->subarea_id,
            'area' => $this->whenLoaded('area', fn () => $this->area?->nome),
            'subarea' => $this->whenLoaded('subarea', fn () => $this->subarea?->nome),
        ];
    }
}
