<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Projeto */
class ProjetoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'editavel' => $this->status->editavel(),
            'categoria' => $this->categoria?->value,
            'categoria_label' => $this->categoria?->label(),
            'max_alunos' => $this->categoria?->maxAlunos(),
            'edicao_id' => $this->edicao_id,
            'instituicao_id' => $this->instituicao_id,
            'area_id' => $this->area_id,
            'subarea_id' => $this->subarea_id,
            'resumo' => $this->resumo,
            'link_video' => $this->link_video,
            'palavras_chave' => $this->palavras_chave ?? [],
            'pais' => $this->pais,
            'estado_id' => $this->estado_id,
            'cidade_id' => $this->cidade_id,
            'continuacao' => $this->continuacao,
            'tempo_pesquisa_meses' => $this->tempo_pesquisa_meses,
            'feira_afiliada' => $this->feira_afiliada,
            'feira_afiliada_nome' => $this->feira_afiliada_nome,
            'necessita_termo_etica' => $this->necessita_termo_etica,
            'numero_credencial' => $this->numero_credencial,
            'agenda_2030' => $this->agenda_2030,
            'categoria_agenda_2030' => $this->categoria_agenda_2030,
            'email_comunicacao' => $this->email_comunicacao,
            'declaracao_email' => $this->declaracao_email,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Nomes legíveis (quando carregados) para exibição.
            'nomes' => [
                'instituicao' => $this->whenLoaded('instituicao', fn () => $this->instituicao?->nome),
                'area' => $this->whenLoaded('area', fn () => $this->area?->nome),
                'subarea' => $this->whenLoaded('subarea', fn () => $this->subarea?->nome),
                'estado' => $this->whenLoaded('estado', fn () => $this->estado?->nome),
                'cidade' => $this->whenLoaded('cidade', fn () => $this->cidade?->nome),
            ],
        ];
    }
}
