<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Os filtros das recomendações do comitê dentro dos cartões das credenciais
 * (Sprints 194 e 201): título ou código do projeto, área e categoria.
 */
class FiltroRecomendacoesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'categoria' => ['nullable', Rule::enum(Categoria::class)],
        ];
    }

    /** @return array{q: ?string, area_id: ?int, categoria: ?string} */
    public function filtros(): array
    {
        return [
            'q' => $this->validated('q'),
            'area_id' => $this->validated('area_id') === null ? null : (int) $this->validated('area_id'),
            'categoria' => $this->validated('categoria'),
        ];
    }
}
