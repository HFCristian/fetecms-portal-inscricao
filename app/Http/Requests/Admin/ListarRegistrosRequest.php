<?php

namespace App\Http\Requests\Admin;

use App\Enums\TipoRegistro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros do painel de registros do admin (a mesma validação serve à listagem
 * e ao export CSV, para os dois sempre enxergarem o mesmo recorte).
 */
class ListarRegistrosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // `tipos` chega como lista (tipos[]=x&tipos[]=y) ou separada por vírgula.
        $tipos = $this->input('tipos');
        if (is_string($tipos)) {
            $this->merge(['tipos' => array_values(array_filter(explode(',', $tipos)))]);
        }
    }

    public function rules(): array
    {
        return [
            'tipos' => ['sometimes', 'array'],
            'tipos.*' => [Rule::enum(TipoRegistro::class)],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
            'busca' => ['nullable', 'string', 'max:120'],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'ate.after_or_equal' => 'A data final deve ser igual ou posterior à inicial.',
        ];
    }

    /** Filtros já normalizados para o service. */
    public function filtros(): array
    {
        return [
            'tipos' => $this->validated('tipos') ?: null,
            'de' => $this->validated('de'),
            'ate' => $this->validated('ate'),
            'busca' => $this->validated('busca'),
        ];
    }
}
