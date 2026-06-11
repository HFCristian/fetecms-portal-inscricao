<?php

namespace App\Http\Requests\Catalogo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Criação de subárea pelo app (orientador/avaliador autenticado, no formulário de
 * projeto/perfil). Cadastro durante o registro público é tratado no service de
 * cada papel (via subarea_nome na transação), não por aqui.
 */
class StoreSubareaRequest extends FormRequest
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
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'nome' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
