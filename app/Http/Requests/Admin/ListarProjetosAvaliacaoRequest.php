<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros da tabela de projetos submetidos do admin. A mesma validação serve à
 * listagem, aos cards de resumo e ao export CSV.
 */
class ListarProjetosAvaliacaoRequest extends FormRequest
{
    /** Colunas pelas quais a tabela pode ser ordenada. */
    public const ORDENACOES = ['titulo', 'area', 'categoria', 'em_avaliacao', 'realizadas', 'faltantes'];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'categoria' => ['nullable', Rule::enum(Categoria::class)],
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
            'categoria' => $this->validated('categoria'),
            'ordenar' => $this->validated('ordenar') ?? 'titulo',
            'direcao' => $this->validated('direcao') ?? 'asc',
        ];
    }
}
