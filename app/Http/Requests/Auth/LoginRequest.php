<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizaEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Chave do limite de tentativas de login: e-mail + IP. Combinar os dois evita
     * que um IP compartilhado (escola atrás de NAT) bloqueie usuários diferentes e
     * que um atacante bloqueie a conta alheia trocando de rede.
     */
    public function throttleKey(): string
    {
        return 'login|'.Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
