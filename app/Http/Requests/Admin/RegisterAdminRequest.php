<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizaEmail;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterAdminRequest extends FormRequest
{
    use NormalizaEmail;

    public function authorize(): bool
    {
        // A restrição de "só admin cria admin" é feita pelo middleware role:admin na rota.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->limparEmails();

        if ($this->has('cpf')) {
            $cpf = preg_replace('/\D/', '', (string) $this->input('cpf'));
            $this->merge(['cpf' => $cpf === '' ? null : $cpf]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'cpf' => ['nullable', 'string', 'size:11', new Cpf],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
