<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O texto de um e-mail automático (Comunicação → Modelos de e-mail).
 *
 * `formato` distingue o corpo escrito no editor rico (`html`) do texto puro de
 * sempre (`texto`, o padrão de quem não manda o campo). O HTML é **sanitizado
 * no service**, não aqui: a regra de o que pode passar é uma só, e mora com
 * quem grava.
 */
class ModeloEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assunto' => ['required', 'string', 'max:200'],
            // O limite folgado é para o HTML: a mesma mensagem ocupa bem mais
            // espaço com as tags do editor do que em texto puro.
            'corpo' => ['required', 'string', 'max:20000'],
            'formato' => ['nullable', Rule::in(['texto', 'html'])],
        ];
    }

    public function attributes(): array
    {
        return ['corpo' => 'texto'];
    }
}
