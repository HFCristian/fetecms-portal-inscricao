<?php

namespace App\Http\Requests\Orientador;

use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterOrientadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza CPF/telefone/CEP para apenas dígitos antes de validar.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'cpf' => $this->onlyDigits($this->input('cpf')),
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
        return [
            // Dados de acesso + identidade (cadastro1)
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'cpf' => ['required', 'string', 'size:11', new Cpf, 'unique:orientador_profiles,cpf'],
            'telefone' => ['required', 'string', 'max:20'],
            'data_nascimento' => ['required', 'date', 'before:today'],
            'genero' => ['nullable', 'string', 'max:30'],
            'genero_outro' => ['nullable', 'string', 'max:60'],
            'etnia' => ['nullable', 'string', 'max:30'],
            'camiseta' => ['nullable', 'string', 'max:5'],
            'pcd' => ['sometimes', 'boolean'],

            // Dados acadêmicos (cadastro2) — opcionais nesta fase
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

            // Endereço (cadastro3) — opcional nesta fase
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
