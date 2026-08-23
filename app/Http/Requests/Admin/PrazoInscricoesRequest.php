<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PrazoInscricoesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Data/hora limite para submeter. null remove o prazo (inscrições abertas).
        return [
            'prazo' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'prazo.date' => 'Informe uma data válida.',
        ];
    }
}
