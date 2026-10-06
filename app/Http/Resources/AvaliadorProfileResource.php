<?php

namespace App\Http\Resources;

use App\Models\AvaliadorProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AvaliadorProfile */
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
            // Respondeu "sim" na aba Presencial: o menu mostra a aba da
            // avaliação no estande (Sprint 171). Nulo é "ainda não respondeu".
            'presencial' => $this->presencial,
        ];
    }
}
