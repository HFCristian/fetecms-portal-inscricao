<?php

namespace App\Http\Requests\Integrante;

use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;

class CoorientadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'cpf' => $this->onlyDigits($this->input('cpf')),
            'telefone' => $this->onlyDigits($this->input('telefone')),
        ], fn ($v) => $v !== null));
    }

    private function onlyDigits(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/\D/', '', $value);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'cpf' => ['required', 'string', 'size:11', new Cpf],
            'telefone' => ['nullable', 'string', 'max:20'],
            // Idade mínima: 21 anos completos (quando a data for informada).
            'data_nascimento' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(21)->toDateString()],
            'genero' => ['nullable', 'string', 'max:30'],
            'camiseta' => ['nullable', 'string', 'max:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_nascimento.before_or_equal' => 'O coorientador precisa ter ao menos 21 anos completos.',
        ];
    }
}
