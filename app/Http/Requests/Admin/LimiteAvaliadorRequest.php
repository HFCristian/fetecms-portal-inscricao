<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LimiteAvaliadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // limite = número máximo de avaliações que o avaliador pode assumir.
        // null (ou ausente) remove o limite.
        return [
            'limite' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'limite.integer' => 'O limite deve ser um número inteiro.',
            'limite.min' => 'O limite não pode ser negativo.',
            'limite.max' => 'O limite máximo permitido é :max.',
        ];
    }
}
