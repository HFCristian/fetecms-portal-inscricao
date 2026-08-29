<?php

namespace App\Http\Requests\Orientador;

use App\Models\ProjetoAjuste;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecidirAjusteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // a posse do projeto é conferida pela Policy no controller
    }

    public function rules(): array
    {
        return [
            'avaliacao_id' => ['required', 'integer', 'exists:avaliacoes,id'],
            'tipo' => ['required', Rule::in([ProjetoAjuste::TIPO_AREA, ProjetoAjuste::TIPO_SUBAREA])],
            'aceito' => ['required', 'boolean'],
        ];
    }
}
