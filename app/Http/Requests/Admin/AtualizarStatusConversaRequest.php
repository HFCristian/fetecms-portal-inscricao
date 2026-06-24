<?php

namespace App\Http\Requests\Admin;

use App\Enums\StatusConversa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtualizarStatusConversaRequest extends FormRequest
{
    /** Status que o admin pode definir manualmente (visualizada é automática ao abrir). */
    private const PERMITIDOS = [
        StatusConversa::Visualizada->value,
        StatusConversa::EmTratamento->value,
        StatusConversa::Arquivada->value,
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(self::PERMITIDOS)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Informe o novo status da conversa.',
            'status.in' => 'Status inválido para esta ação.',
        ];
    }

    public function statusEnum(): StatusConversa
    {
        return StatusConversa::from($this->validated('status'));
    }
}
