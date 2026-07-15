<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LiberacaoAvaliacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Data/hora a partir da qual a avaliação é liberada. null remove a liberação.
        return [
            'liberada_em' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'liberada_em.date' => 'Informe uma data válida.',
        ];
    }
}
