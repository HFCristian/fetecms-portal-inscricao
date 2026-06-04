<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OrientadorProfile */
class OrientadorProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'cpf' => $this->cpf,
            'telefone' => $this->telefone,
            'data_nascimento' => $this->data_nascimento?->toDateString(),
            'genero' => $this->genero,
            'genero_outro' => $this->genero_outro,
            'etnia' => $this->etnia,
            'camiseta' => $this->camiseta,
            'pcd' => $this->pcd,
            'instituicao' => $this->instituicao,
            'tipo_instituicao' => $this->tipo_instituicao,
            'vinculo' => $this->vinculo,
            'titulacao' => $this->titulacao,
            'curso_formacao' => $this->curso_formacao,
            'area_conhecimento' => $this->area_conhecimento,
            'subarea' => $this->subarea,
            'tempo_orientacao' => $this->tempo_orientacao,
            'vezes_fetec' => $this->vezes_fetec,
            'ex_aluno_fetec' => $this->ex_aluno_fetec,
            'endereco' => [
                'cep' => $this->cep,
                'logradouro' => $this->logradouro,
                'numero' => $this->numero,
                'complemento' => $this->complemento,
                'bairro' => $this->bairro,
                'cidade' => $this->cidade,
                'estado' => $this->estado,
                'pais' => $this->pais,
            ],
        ];
    }
}
