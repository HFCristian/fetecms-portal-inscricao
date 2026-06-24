<?php

namespace App\Http\Resources;

use App\Models\Mensagem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Mensagem */
class MensagemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'autor' => $this->autor,
            'corpo' => $this->corpo,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
