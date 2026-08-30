<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'is_active' => $this->is_active,
            'chat_dica_dispensada' => (bool) $this->chat_dica_dispensada,
            // Abas do menu que este admin abre (escopo da edição em curso). Para
            // os demais papéis a lista é vazia — eles não têm menu de admin.
            'abas' => $this->when($this->role === Role::Admin, fn () => $this->abasPermitidas()),
            'orientador_profile' => OrientadorProfileResource::make(
                $this->whenLoaded('orientadorProfile')
            ),
            'avaliador_profile' => AvaliadorProfileResource::make(
                $this->whenLoaded('avaliadorProfile')
            ),
        ];
    }
}
