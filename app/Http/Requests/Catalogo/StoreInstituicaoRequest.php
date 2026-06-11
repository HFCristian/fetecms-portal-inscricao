<?php

namespace App\Http\Requests\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Criação de instituição pelo app (orientador/projeto autenticado, combobox
 * "digite/crie"). No cadastro público do orientador a criação é feita no service
 * via instituicao_nome (transação do registro).
 */
class StoreInstituicaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('nome')) {
            $this->merge(['nome' => trim(preg_replace('/\s+/', ' ', (string) $this->input('nome')))]);
        }
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:2', 'max:255'],
            'cidade_id' => ['nullable', 'integer', 'exists:cidades,id'],
            'tipo' => ['nullable', 'string', 'max:60'],
        ];
    }
}
