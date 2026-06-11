<?php

namespace App\Http\Requests\Avaliador;

use App\Models\Coorientador;
use App\Models\OrientadorProfile;
use App\Rules\Cpf;
use App\Rules\SubareaDaArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterAvaliadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('cpf')) {
            $this->merge(['cpf' => preg_replace('/\D/', '', $this->input('cpf'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'cpf' => [
                'required', 'string', 'size:11', new Cpf,
                Rule::unique('avaliador_profiles', 'cpf'),
                // Exclusão mútua: CPF não pode ser de orientador nem de coorientador.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (OrientadorProfile::where('cpf', $value)->exists()) {
                        $fail('Este CPF já está cadastrado como orientador. Um orientador não pode ser avaliador.');
                    } elseif (Coorientador::where('cpf', $value)->exists()) {
                        $fail('Este CPF consta como coorientador de um projeto e não pode ser avaliador.');
                    }
                },
            ],
            'titulacao' => ['nullable', 'string', 'max:60'],
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            // subarea_nome cria uma subárea global nova (resolvida no service).
            'subarea_id' => ['nullable', 'integer', 'exists:subareas,id', new SubareaDaArea($this->input('area_id'))],
            'subarea_nome' => ['nullable', 'string', 'min:2', 'max:120'],
        ];
    }
}
