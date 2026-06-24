<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ResponderConversaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['corpo' => trim((string) $this->input('corpo'))]);
    }

    public function rules(): array
    {
        return [
            'corpo' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'corpo.required' => 'Escreva a resposta antes de enviar.',
            'corpo.max' => 'A resposta pode ter no máximo :max caracteres.',
        ];
    }
}
