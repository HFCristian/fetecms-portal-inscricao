<?php

namespace App\Http\Resources;

use App\Models\Aluno;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Aluno */
class AlunoResource extends JsonResource
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
            'etnia' => $this->etnia,
            'camiseta' => $this->camiseta,
            'instituicao_id' => $this->instituicao_id,
            'modalidade' => $this->modalidade,
            'ano_escolar' => $this->ano_escolar,
            'periodo' => $this->periodo,
            'graduacao_pretendida' => $this->graduacao_pretendida,
            'bolsista' => $this->bolsista,
            'clube_ciencias' => $this->clube_ciencias,
            'autorizacao_menor' => $this->autorizacao_menor,
        ];
    }
}
