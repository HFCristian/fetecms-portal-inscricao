<?php

namespace App\Http\Resources;

use App\Models\Conversa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversa */
class ConversaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'nao_respondida' => $this->status->naoRespondida(),
            'ultima_mensagem_em' => $this->ultima_mensagem_em?->toIso8601String(),
            'usuario' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role->value,
                'role_label' => $this->user->role->label(),
            ]),
            'ultima_mensagem' => MensagemResource::make($this->whenLoaded('ultimaMensagem')),
            'mensagens' => MensagemResource::collection($this->whenLoaded('mensagens')),
        ];
    }
}
