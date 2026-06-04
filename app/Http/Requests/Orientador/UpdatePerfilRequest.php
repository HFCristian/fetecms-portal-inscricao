<?php

namespace App\Http\Requests\Orientador;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        // O orientador só edita o próprio perfil — garantido pelo controller,
        // que sempre opera sobre o usuário autenticado.
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'telefone' => $this->onlyDigits($this->input('telefone')),
            'cep' => $this->onlyDigits($this->input('cep')),
        ], fn ($v) => $v !== null));
    }

    private function onlyDigits(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/\D/', '', $value);
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            // CPF/e-mail não editáveis aqui (mudança sensível); demais campos sim.
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'telefone' => ['sometimes', 'string', 'max:20'],
            'data_nascimento' => ['sometimes', 'date', 'before:today'],
            'genero' => ['nullable', 'string', 'max:30'],
            'genero_outro' => ['nullable', 'string', 'max:60'],
            'etnia' => ['nullable', 'string', 'max:30'],
            'camiseta' => ['nullable', 'string', 'max:5'],
            'pcd' => ['sometimes', 'boolean'],
            'instituicao' => ['nullable', 'string', 'max:255'],
            'tipo_instituicao' => ['nullable', 'string', 'max:60'],
            'vinculo' => ['nullable', 'string', 'max:60'],
            'titulacao' => ['nullable', 'string', 'max:60'],
            'curso_formacao' => ['nullable', 'string', 'max:120'],
            'area_conhecimento' => ['nullable', 'string', 'max:120'],
            'subarea' => ['nullable', 'string', 'max:120'],
            'tempo_orientacao' => ['nullable', 'string', 'max:30'],
            'vezes_fetec' => ['nullable', 'string', 'max:30'],
            'ex_aluno_fetec' => ['sometimes', 'boolean'],
            'cep' => ['nullable', 'string', 'size:8'],
            'logradouro' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:120'],
            'bairro' => ['nullable', 'string', 'max:120'],
            'cidade' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'string', 'max:60'],
            'pais' => ['nullable', 'string', 'max:60'],
        ];
    }
}
