<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros da tabela de avaliadores do admin. A mesma validação serve à listagem
 * e ao export CSV, para os dois enxergarem sempre o mesmo recorte.
 */
class ListarAvaliadoresRequest extends FormRequest
{
    /** Colunas pelas quais a tabela pode ser ordenada. */
    public const ORDENACOES = ['nome', 'area', 'em_avaliacao', 'avaliou', 'faltam', 'criado_em'];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'situacao' => ['nullable', Rule::in(['comissao', 'demo', 'bloqueados'])],
            'ordenar' => ['nullable', Rule::in(self::ORDENACOES)],
            'direcao' => ['nullable', Rule::in(['asc', 'desc'])],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:200'],
        ];
    }

    /** @return array<string, mixed> */
    public function filtros(): array
    {
        return [
            'q' => $this->validated('q'),
            'area_id' => $this->validated('area_id'),
            'situacao' => $this->validated('situacao'),
            'ordenar' => $this->validated('ordenar') ?? 'nome',
            'direcao' => $this->validated('direcao') ?? 'asc',
        ];
    }
}
