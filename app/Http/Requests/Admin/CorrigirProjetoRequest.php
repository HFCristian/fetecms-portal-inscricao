<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use App\Enums\Role;
use App\Http\Requests\Concerns\NormalizaEmail;
use App\Rules\Cpf;
use App\Rules\SubareaDaArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correção de um projeto submetido pelo admin. Só chegam os campos que a tela
 * mandou — o que não vier fica como está —, e a justificativa é sempre exigida:
 * é ela que explica, na trilha de registros, por que a inscrição de outra
 * pessoa foi alterada.
 */
class CorrigirProjetoRequest extends FormRequest
{
    use NormalizaEmail;

    /**
     * CPF e telefone do coorientador chegam com máscara da tela e são gravados
     * só com dígitos, como no formulário do orientador.
     */
    protected function prepareForValidation(): void
    {
        $this->limparEmails();

        $coorientador = $this->input('coorientador');

        if (is_array($coorientador)) {
            foreach (['cpf', 'telefone'] as $campo) {
                if (isset($coorientador[$campo])) {
                    $coorientador[$campo] = preg_replace('/\D/', '', (string) $coorientador[$campo]);
                }
            }

            $this->merge(['coorientador' => $coorientador]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categoria' => ['sometimes', 'nullable', Rule::enum(Categoria::class)],
            'area_id' => ['sometimes', 'nullable', 'integer', 'exists:areas,id'],
            'subarea_id' => [
                'sometimes', 'nullable', 'integer', 'exists:subareas,id',
                // A subárea é conferida contra a área que vai VALER depois da
                // correção: a que veio no payload ou, se a tela não mandou área,
                // a que o projeto já tem.
                new SubareaDaArea($this->areaEfetiva()),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! empty($value) && empty($this->areaEfetiva())) {
                        $fail('Escolha a área antes da subárea.');
                    }
                },
            ],
            'link_video' => ['sometimes', 'nullable', 'url', 'max:255'],
            // Trocar o orientador troca o DONO do projeto, então só vale uma
            // conta de orientador que já existe (e está ativa).
            'user_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')
                    ->where('role', Role::Orientador->value)
                    ->where('is_active', true),
            ],
            // Coorientador: array edita/inclui, `null` explícito remove.
            'coorientador' => ['sometimes', 'nullable', 'array'],
            'coorientador.nome' => ['required_with:coorientador', 'string', 'max:255'],
            'coorientador.email' => ['required_with:coorientador', 'email', 'max:255'],
            'coorientador.cpf' => ['required_with:coorientador', 'string', 'size:11', new Cpf],
            'coorientador.telefone' => ['nullable', 'string', 'max:20'],
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** A área que o projeto terá depois desta correção. */
    private function areaEfetiva(): mixed
    {
        if ($this->has('area_id')) {
            return $this->input('area_id');
        }

        $projeto = $this->route('projeto');

        return $projeto?->area_id;
    }

    public function attributes(): array
    {
        return [
            'area_id' => 'área',
            'subarea_id' => 'subárea',
            'link_video' => 'link do vídeo',
        ];
    }
}
