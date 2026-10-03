<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizaEmail;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
{
    use NormalizaEmail;

    public function authorize(): bool
    {
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
        // Ignora o próprio admin na checagem de e-mail único.
        $adminId = $this->route('admin')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($adminId)],
            'cpf' => ['nullable', 'string', 'size:11', new Cpf],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do administrador.',
            'email.required' => 'Informe o e-mail do administrador.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Este e-mail já está em uso por outro usuário.',
        ];
    }
}
