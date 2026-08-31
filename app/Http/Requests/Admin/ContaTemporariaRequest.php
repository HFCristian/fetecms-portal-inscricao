<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizaEmail;
use App\Rules\Cpf;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro de uma conta temporária de credenciamento.
 *
 * É o mesmo formulário do administrador (nome, e-mail e senha) mais **CPF**,
 * **curso** e a **janela** de validade: quando o acesso começa (`valido_de`, em
 * branco = agora) e quanto dura — uma quantidade de **horas** a contar do
 * início, ou uma data explícita de fim (`expira_em`).
 *
 * O CPF não é conferido contra os catálogos de orientador/avaliador: quem
 * atende o balcão pode perfeitamente ser um deles, e a conta é de acesso, não
 * de participação na feira.
 */
class ContaTemporariaRequest extends FormRequest
{
    use NormalizaEmail;

    public function authorize(): bool
    {
        // A restrição é da rota (role:admin + aba:credenciamento).
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->limparEmails();

        if ($this->filled('cpf')) {
            $this->merge(['cpf' => preg_replace('/\D/', '', (string) $this->input('cpf'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'cpf' => ['required', 'string', 'size:11', new Cpf],
            'curso' => ['required', 'string', 'max:255'],
            // Em branco, o acesso vale a partir de agora.
            'valido_de' => ['nullable', 'date'],
            // Um dos dois basta; sem nenhum, o service usa o padrão de 5 horas.
            'expira_em' => ['nullable', 'date'],
            'horas' => ['nullable', 'integer', 'min:1', 'max:'.ContaTemporariaService::HORAS_MAX],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'curso' => 'nome do curso',
            'valido_de' => 'início do acesso',
            'expira_em' => 'prazo',
            'horas' => 'quantidade de horas',
        ];
    }
}
