<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InicioInscricoesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Data/hora em que as inscrições abrem. null remove a espera (já abertas).
        return [
            'inicio' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'inicio.date' => 'Informe uma data válida.',
        ];
    }
}
