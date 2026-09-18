<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Desconsiderar (ou voltar a considerar) a nota de um avaliador.
 *
 * A justificativa é o campo inteiro: descartar uma nota muda a média do projeto
 * e pode mudar quem entra na lista final. É escape do edital — o avaliador
 * enviou, e o envio era irreversível —, e escape do edital se explica por
 * escrito, como em toda correção que o admin faz por cima do que já foi fechado.
 */
class DesconsiderarNotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'justificativa' => ['required', 'string', 'min:5', 'max:1000'],
            // O que entra no lugar da nota que saiu. Ausente vale como
            // "nenhuma": desconsiderar sem repor é decisão legítima quando o
            // projeto ainda tem pareceres de sobra.
            'substituicao' => ['sometimes', 'nullable', 'array'],
            'substituicao.tipo' => ['required_with:substituicao', Rule::in(['nenhuma', 'avaliador', 'admin'])],
            'substituicao.avaliador_id' => [
                'exclude_unless:substituicao.tipo,avaliador',
                'required', 'integer',
                Rule::exists('users', 'id')->where('role', Role::Avaliador->value)->where('is_active', true),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'justificativa' => 'justificativa',
            'substituicao.tipo' => 'substituição',
            'substituicao.avaliador_id' => 'avaliador',
        ];
    }
}
