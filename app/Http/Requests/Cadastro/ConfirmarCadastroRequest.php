<?php

namespace App\Http\Requests\Cadastro;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmarCadastroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** O código chega como a pessoa digitou: só os dígitos interessam. */
    protected function prepareForValidation(): void
    {
        $this->merge(['codigo' => preg_replace('/\D/', '', (string) $this->input('codigo'))]);
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'size:6'],
        ];
    }

    public function attributes(): array
    {
        return ['codigo' => 'código'];
    }
}
