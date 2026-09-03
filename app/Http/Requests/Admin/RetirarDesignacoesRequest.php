<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Retirada em lote de designações. O admin marca as linhas na tabela e manda os
 * ids; a conferência de qual situação pode sair fica no DesignacaoService.
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
        ];
    }

    public function attributes(): array
    {
        return ['avaliacao_ids' => 'designações'];
    }
}
