<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\CadastroPendente;
use App\Services\ConfirmacaoCadastroService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O cadastro à espera do código. Devolve só o que a tela precisa mostrar — o
 * e-mail para a pessoa conferir e o prazo. Nada do payload sai daqui.
 *
 * @mixin CadastroPendente
 */
class CadastroPendenteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'email' => $this->email,
            'nome' => $this->nome,
            'papel' => $this->papel,
            'papel_label' => Role::from($this->papel)->label(),
            'expira_em' => $this->expira_em?->toIso8601String(),
            'validade_minutos' => ConfirmacaoCadastroService::VALIDADE_MINUTOS,
        ];
    }
}
