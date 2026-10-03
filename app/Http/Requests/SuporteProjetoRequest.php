<?php

namespace App\Http\Requests;

use App\Enums\TipoSuporte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pedido de suporte de um projeto finalista (Sprint 162). As exigências de cada
 * tipo — o acompanhante tem nome, documento, vínculo e estudante; o intérprete
 * de outra língua, a língua — são conferidas no service, que dá a mensagem certa
 * para cada caso.
 */
class SuporteProjetoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoSuporte::class)],
            'idioma' => ['nullable', 'string', 'max:60'],
            'aluno_id' => ['nullable', 'integer'],
            'acompanhante_nome' => ['nullable', 'string', 'max:255'],
            'acompanhante_documento' => ['nullable', 'string', 'max:60'],
            'acompanhante_vinculo' => ['nullable', 'string', 'max:80'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            // Só o admin: registrar um pedido já aprovado (recebido por fora).
            'aprovar' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'idioma' => 'língua',
            'aluno_id' => 'estudante',
            'acompanhante_nome' => 'nome do acompanhante',
            'acompanhante_documento' => 'documento do acompanhante',
            'acompanhante_vinculo' => 'vínculo com o estudante',
            'observacao' => 'observação',
        ];
    }
}
