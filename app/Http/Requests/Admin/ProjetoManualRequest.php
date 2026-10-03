<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use App\Http\Requests\Concerns\NormalizaEmail;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro (e correção) manual de um projeto pelo admin — Sprint 157.
 *
 * Diferente da inscrição, CPF e e-mail de estudantes e coorientador são
 * opcionais: a organização costuma receber essas equipes só com o nome. O que
 * vier é validado do mesmo jeito. O orientador é uma conta: ou se escolhe uma
 * existente (`orientador.user_id`), ou se informa nome e e-mail para criá-la.
 */
class ProjetoManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $digitos = fn ($v) => ($v === null || $v === '') ? null : preg_replace('/\D/', '', (string) $v);
        $email = fn ($v) => ($v === null || $v === '') ? null : NormalizaEmail::semEspacos((string) $v);

        $orientador = (array) $this->input('orientador', []);
        if (array_key_exists('email', $orientador)) {
            $orientador['email'] = $email($orientador['email']);
        }
        if (array_key_exists('cpf', $orientador)) {
            $orientador['cpf'] = $digitos($orientador['cpf']);
        }
        if (array_key_exists('telefone', $orientador)) {
            $orientador['telefone'] = $digitos($orientador['telefone']);
        }

        $coorientador = $this->input('coorientador');
        if (is_array($coorientador)) {
            $coorientador['email'] = $email($coorientador['email'] ?? null);
            $coorientador['cpf'] = $digitos($coorientador['cpf'] ?? null);
            if (trim((string) ($coorientador['nome'] ?? '')) === '') {
                $coorientador = null;
            }
        }

        $alunos = array_map(fn ($a) => is_array($a) ? array_merge($a, [
            'email' => $email($a['email'] ?? null),
            'cpf' => $digitos($a['cpf'] ?? null),
        ]) : $a, (array) $this->input('alunos', []));

        $this->merge([
            'orientador' => $orientador,
            'coorientador' => $coorientador,
            'alunos' => $alunos,
        ]);
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'categoria' => ['required', Rule::enum(Categoria::class)],
            'instituicao_id' => ['required', 'integer', 'exists:instituicoes,id'],
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'subarea_id' => ['nullable', 'integer', 'exists:subareas,id'],
            'origem' => ['required', Rule::in(['finalista', 'credencial'])],
            'feira_afiliada_nome' => ['nullable', 'required_if:origem,credencial', 'string', 'max:255'],
            'numero_credencial' => ['nullable', 'string', 'max:50'],

            'orientador' => ['required', 'array'],
            'orientador.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'orientador.nome' => ['nullable', 'required_without:orientador.user_id', 'string', 'max:255'],
            'orientador.email' => ['nullable', 'required_without:orientador.user_id', 'email', 'max:255'],
            'orientador.cpf' => ['nullable', 'string', 'size:11', new Cpf],
            'orientador.telefone' => ['nullable', 'string', 'max:20'],

            'coorientador' => ['nullable', 'array'],
            'coorientador.nome' => ['required_with:coorientador', 'string', 'max:255'],
            'coorientador.email' => ['nullable', 'email', 'max:255'],
            'coorientador.cpf' => ['nullable', 'string', 'size:11', new Cpf],

            'alunos' => ['required', 'array', 'min:1', 'max:4'],
            'alunos.*.id' => ['nullable', 'integer'],
            'alunos.*.nome' => ['required', 'string', 'max:255'],
            'alunos.*.email' => ['nullable', 'email', 'max:255'],
            'alunos.*.cpf' => ['nullable', 'string', 'size:11', new Cpf],

            'credenciado' => ['nullable', 'boolean'],
            'justificativa' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'titulo' => 'título',
            'instituicao_id' => 'instituição',
            'area_id' => 'área',
            'subarea_id' => 'subárea',
            'feira_afiliada_nome' => 'nome da feira afiliada',
            'numero_credencial' => 'número da credencial',
            'orientador.nome' => 'nome do orientador',
            'orientador.email' => 'e-mail do orientador',
            'orientador.cpf' => 'CPF do orientador',
            'coorientador.nome' => 'nome do coorientador',
            'coorientador.email' => 'e-mail do coorientador',
            'coorientador.cpf' => 'CPF do coorientador',
            'alunos' => 'estudantes',
            'alunos.*.nome' => 'nome do estudante',
            'alunos.*.email' => 'e-mail do estudante',
            'alunos.*.cpf' => 'CPF do estudante',
        ];
    }

    public function messages(): array
    {
        return [
            'feira_afiliada_nome.required_if' => 'Informe de qual feira veio a credencial.',
            'orientador.nome.required_without' => 'Escolha um orientador existente ou informe o nome do novo.',
            'orientador.email.required_without' => 'Escolha um orientador existente ou informe o e-mail do novo.',
            'alunos.required' => 'Informe ao menos um estudante.',
            'alunos.min' => 'Informe ao menos um estudante.',
        ];
    }
}
