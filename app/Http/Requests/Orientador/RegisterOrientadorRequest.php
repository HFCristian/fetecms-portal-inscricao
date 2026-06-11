<?php

namespace App\Http\Requests\Orientador;

use App\Models\AvaliadorProfile;
use App\Rules\CidadeDoEstado;
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
            'cpf' => [
                'required', 'string', 'size:11', new Cpf, 'unique:orientador_profiles,cpf',
                // Exclusão mútua: quem já é avaliador (por CPF) não pode ser orientador.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (AvaliadorProfile::where('cpf', $value)->exists()) {
                        $fail('Este CPF já está cadastrado como avaliador. Um avaliador não pode ser orientador.');
                    }
                },
            ],
            'telefone' => ['required', 'string', 'max:20'],
            // Idade mínima: 21 anos completos (nascido até a data de 21 anos atrás).
            'data_nascimento' => ['required', 'date', 'before_or_equal:'.now()->subYears(21)->toDateString()],
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
            // Endereço: no Brasil usa o catálogo (FK); fora do Brasil, texto livre.
            'estado_id' => ['nullable', 'integer', 'exists:estados,id'],
            'cidade_id' => ['nullable', 'integer', 'exists:cidades,id', new CidadeDoEstado($this->input('estado_id'))],
            'estado_nome' => ['nullable', 'string', 'max:120'],
            'cidade_nome' => ['nullable', 'string', 'max:120'],
            'pais' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_nascimento.before_or_equal' => 'É necessário possuir ao menos 21 anos completos para submissão.',
        ];
    }
}
