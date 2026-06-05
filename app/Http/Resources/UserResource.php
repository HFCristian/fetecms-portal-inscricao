<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
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
            'orientador_profile' => OrientadorProfileResource::make(
                $this->whenLoaded('orientadorProfile')
            ),
            'avaliador_profile' => AvaliadorProfileResource::make(
                $this->whenLoaded('avaliadorProfile')
            ),
        ];
    }
}
