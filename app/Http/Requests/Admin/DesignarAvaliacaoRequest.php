<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DesignarAvaliacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tipo = $this->input('tipo');

        return [
            'tipo' => ['required', Rule::in(['avaliador', 'area', 'subarea'])],
            'alvo_id' => ['required', 'integer', match ($tipo) {
                'avaliador' => Rule::exists('users', 'id')->where('role', 'avaliador'),
                'area' => Rule::exists('areas', 'id'),
                'subarea' => Rule::exists('subareas', 'id'),
                default => 'integer',
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Escolha o tipo de designação.',
            'tipo.in' => 'Tipo de designação inválido.',
            'alvo_id.required' => 'Selecione para quem designar.',
            'alvo_id.exists' => 'A opção selecionada não existe.',
        ];
    }
}
