<?php

namespace App\Http\Resources;

use App\Models\OrientadorProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrientadorProfile */
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
            'instituicao_id' => $this->instituicao_id,
            'instituicao' => $this->instituicao?->nome,
            'tipo_instituicao' => $this->tipo_instituicao,
            'vinculo' => $this->vinculo,
            'titulacao' => $this->titulacao,
            'curso_formacao' => $this->curso_formacao,
            // Área/subárea do catálogo unificado (FK) + nomes resolvidos p/ exibição.
            'area_id' => $this->area_id,
            'subarea_id' => $this->subarea_id,
            'area' => $this->area?->nome,
            'subarea' => $this->subarea?->nome,
            'tempo_orientacao' => $this->tempo_orientacao,
            'vezes_fetec' => $this->vezes_fetec,
            'ex_aluno_fetec' => $this->ex_aluno_fetec,
            'endereco' => [
                'cep' => $this->cep,
                'logradouro' => $this->logradouro,
                'numero' => $this->numero,
                'complemento' => $this->complemento,
                'bairro' => $this->bairro,
                'pais' => $this->pais,
                // No Brasil: FK do catálogo. Fora do Brasil: texto livre.
                'estado_id' => $this->estado_id,
                'cidade_id' => $this->cidade_id,
                'estado_nome' => $this->estado_nome,
                'cidade_nome' => $this->cidade_nome,
                // Resolvidos para exibição (UF/nome do catálogo, ou o texto livre).
                'estado' => $this->estado?->nome ?? $this->estado_nome,
                'estado_uf' => $this->estado?->uf,
                'cidade' => $this->cidade?->nome ?? $this->cidade_nome,
            ],
        ];
    }
}
