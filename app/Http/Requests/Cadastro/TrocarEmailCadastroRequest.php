<?php

namespace App\Http\Requests\Cadastro;

use App\Http\Requests\Concerns\NormalizaEmail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correção do e-mail antes de confirmar o cadastro — o caminho de quem digitou
 * o endereço errado e viu na tela.
 */
class TrocarEmailCadastroRequest extends FormRequest
{
    use NormalizaEmail;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->limparEmails();
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => 'e-mail'];
    }
}
