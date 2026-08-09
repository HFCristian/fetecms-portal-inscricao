<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Aceite (em lote) das sugestões de reclassificação. Cada item aponta um projeto
 * e o que aceitar dele: a área, a subárea ou as duas. A checagem de que o valor
 * foi mesmo sugerido por um avaliador é regra de negócio e fica no service.
 */
class AplicarReclassificacaoRequest extends FormRequest
{
    /** Autorização é da rota (middleware `role:admin`). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.projeto_id' => ['required', 'integer', 'distinct', 'exists:projetos,id'],
            'itens.*.area_id' => ['nullable', 'integer', 'exists:areas,id', 'required_without:itens.*.subarea_id'],
            'itens.*.subarea_id' => ['nullable', 'integer', 'exists:subareas,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'itens.required' => 'Selecione ao menos uma sugestão para aplicar.',
            'itens.*.projeto_id.distinct' => 'Cada projeto só pode aparecer uma vez na seleção.',
            'itens.*.area_id.required_without' => 'Escolha a área e/ou a subárea a aplicar em cada projeto selecionado.',
        ];
    }
}
