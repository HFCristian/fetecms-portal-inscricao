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
        // Só normaliza quando for string; um array/objeto fica intacto para a
        // regra 'string' rejeitar com 422 (em vez de virar a literal "Array").
        $corpo = $this->input('corpo');
        $this->merge(['corpo' => is_string($corpo) ? trim($corpo) : $corpo]);
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
