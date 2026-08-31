<?php

namespace App\Http\Resources;

use App\Enums\AbaAdmin;
use App\Enums\Role;
use App\Models\Edicao;
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
            // Modo demo: libera as funcionalidades que dependem de data (o
            // credenciamento antes do evento, os ajustes fora do período) para
            // esta pessoa treinar sem esperar o calendário.
            'is_demo' => (bool) $this->is_demo,
            // Conta temporária do balcão: abre só a aba Credenciamento e não
            // administra outras contas temporárias.
            'conta_temporaria' => $this->when(
                $this->role === Role::Admin,
                fn () => $this->ehContaTemporaria(),
            ),
            'chat_dica_dispensada' => (bool) $this->chat_dica_dispensada,
            // Abas do menu que este admin abre (escopo da edição em curso), na
            // ordem escolhida em Parametrização → Ordem do menu. Para os demais
            // papéis a lista é vazia — eles não têm menu de admin.
            //
            // A ordenação é de apresentação: `abasPermitidas()` continua sendo
            // o conjunto que autoriza (e o middleware `aba:` só pergunta se a
            // aba está lá), então mudar a ordem nunca muda o acesso.
            'abas' => $this->when(
                $this->role === Role::Admin,
                fn () => AbaAdmin::ordenar($this->abasPermitidas(), Edicao::atual()?->ordem_abas),
            ),
            'orientador_profile' => OrientadorProfileResource::make(
                $this->whenLoaded('orientadorProfile')
            ),
            'avaliador_profile' => AvaliadorProfileResource::make(
                $this->whenLoaded('avaliadorProfile')
            ),
        ];
    }
}
