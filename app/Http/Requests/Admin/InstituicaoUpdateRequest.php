<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InstituicaoUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('nome')) {
            $this->merge(['nome' => trim(preg_replace('/\s+/', ' ', (string) $this->input('nome')))]);
        }
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }
}
