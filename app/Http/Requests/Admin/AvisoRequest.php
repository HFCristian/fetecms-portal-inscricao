<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AvisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'titulo' => trim((string) $this->input('titulo')),
            'mensagem' => trim((string) $this->input('mensagem')),
        ]);
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:120'],
            'mensagem' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'Escreva o título do aviso.',
            'titulo.max' => 'O título pode ter no máximo 120 caracteres.',
            'mensagem.required' => 'Escreva a mensagem do aviso.',
            'mensagem.max' => 'A mensagem pode ter no máximo 2000 caracteres.',
        ];
    }
}
