<?php

namespace App\Http\Requests\Integrante;

use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AlunoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorização real é feita no controller (Policy do projeto dono)
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'cpf' => $this->onlyDigits($this->input('cpf')),
            'telefone' => $this->onlyDigits($this->input('telefone')),
        ], fn ($v) => $v !== null));
    }

    private function onlyDigits(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/\D/', '', $value);
    }

    public function rules(): array
    {
        $projetoId = $this->route('projeto')?->id ?? $this->route('aluno')?->projeto_id;
        $alunoId = $this->route('aluno')?->id;

        return [
            'nome' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('alunos', 'email')->where('projeto_id', $projetoId)->ignore($alunoId),
            ],
            'cpf' => [
                'required', 'string', 'size:11', new Cpf,
                Rule::unique('alunos', 'cpf')->where('projeto_id', $projetoId)->ignore($alunoId),
            ],
            'telefone' => ['nullable', 'string', 'max:20'],
            'data_nascimento' => ['nullable', 'date', 'before:today'],
            'genero' => ['nullable', 'string', 'max:30'],
            'etnia' => ['nullable', 'string', 'max:30'],
            'camiseta' => ['nullable', 'string', 'max:5'],
            'instituicao_id' => ['nullable', 'integer', 'exists:instituicoes,id'],
            'modalidade' => ['nullable', 'string', 'max:30'],
            'ano_escolar' => ['nullable', 'string', 'max:30'],
            'periodo' => ['nullable', 'string', 'max:30'],
            'graduacao_pretendida' => ['nullable', 'string', 'max:120'],
            'bolsista' => ['sometimes', 'boolean'],
            'clube_ciencias' => ['sometimes', 'boolean'],
            'autorizacao_menor' => ['sometimes', 'boolean'],
        ];
    }
}
