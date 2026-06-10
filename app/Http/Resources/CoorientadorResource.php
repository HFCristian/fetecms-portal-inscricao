<?php

namespace App\Http\Resources;

use App\Models\Coorientador;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coorientador */
class CoorientadorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'email' => $this->email,
            'cpf' => $this->cpf,
            'telefone' => $this->telefone,
            'data_nascimento' => $this->data_nascimento?->toDateString(),
            'genero' => $this->genero,
            'camiseta' => $this->camiseta,
        ];
    }
}
