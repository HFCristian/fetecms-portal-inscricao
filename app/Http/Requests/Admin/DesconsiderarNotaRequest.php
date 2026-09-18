<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    public function attributes(): array
    {
        return ['justificativa' => 'justificativa'];
    }
}
