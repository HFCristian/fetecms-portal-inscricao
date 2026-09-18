<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Retirada em lote de designações. O admin marca as linhas na tabela e manda os
 * ids; a conferência de qual situação pode sair fica no DesignacaoService.
 *
 * `redesignar` diz o que acontece com o projeto depois: **ligado** (o padrão, e
 * o comportamento de sempre) ele volta ao bolo e ganha outro avaliador na hora;
 * **desligado**, a designação é só removida. São duas intenções diferentes —
 * "este avaliador não pode ficar com este projeto" e "este projeto não precisa
 * mais deste parecer" —, e emendar as duas obrigava o admin a retirar e depois
 * retirar de novo quem o algoritmo tinha acabado de pôr ali.
 */
class RetirarDesignacoesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'avaliacao_ids' => ['required', 'array', 'min:1', 'max:200'],
            'avaliacao_ids.*' => ['integer', 'exists:avaliacoes,id'],
            // Ausente vale como "sim": quem não mandar o campo continua tendo a
            // reposição de antes.
            'redesignar' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['avaliacao_ids' => 'designações', 'redesignar' => 'reposição'];
    }
}
