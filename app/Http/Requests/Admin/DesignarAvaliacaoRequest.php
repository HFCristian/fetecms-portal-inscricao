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
            'tipo' => ['required', Rule::in(['avaliador', 'area', 'subarea', 'comissao'])],
            // A comissão especial não tem alvo único: vai para todos os membros
            // ou para os que o admin marcar em `avaliador_ids`.
            'alvo_id' => ['required_unless:tipo,comissao', 'integer', match ($tipo) {
                'avaliador' => Rule::exists('users', 'id')->where('role', 'avaliador'),
                'area' => Rule::exists('areas', 'id'),
                'subarea' => Rule::exists('subareas', 'id'),
                default => 'nullable',
            }],
            'avaliador_ids' => ['nullable', 'array'],
            'avaliador_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', 'avaliador')],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Escolha o tipo de designação.',
            'tipo.in' => 'Tipo de designação inválido.',
            'alvo_id.required_unless' => 'Selecione para quem designar.',
            'alvo_id.exists' => 'A opção selecionada não existe.',
            'avaliador_ids.*.exists' => 'Um dos avaliadores selecionados não existe.',
        ];
    }

    /** @return list<int> */
    public function avaliadorIds(): array
    {
        return array_map('intval', $this->validated('avaliador_ids') ?? []);
    }
}
