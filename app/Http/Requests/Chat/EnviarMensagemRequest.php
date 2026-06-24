<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class EnviarMensagemRequest extends FormRequest
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
            'corpo' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'corpo.required' => 'Digite uma mensagem antes de enviar.',
            'corpo.max' => 'A mensagem pode ter no máximo :max caracteres.',
        ];
    }
}
