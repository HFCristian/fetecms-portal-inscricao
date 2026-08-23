<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class EncerramentoAvaliacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Data/hora em que a avaliação se encerra. null deixa o período aberto.
        return [
            'encerrada_em' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'encerrada_em.date' => 'Informe uma data válida.',
        ];
    }
}
