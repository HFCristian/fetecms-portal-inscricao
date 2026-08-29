<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizaEmail;
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
    }

    public function rules(): array
    {
        // Ignora o próprio admin na checagem de e-mail único.
        $adminId = $this->route('admin')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($adminId)],
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
