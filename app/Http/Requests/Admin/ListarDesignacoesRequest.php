<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Services\DesignacaoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros da tabela de designações (Avaliação online → Designações).
 */
class ListarDesignacoesRequest extends FormRequest
{
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
            'situacao' => ['nullable', Rule::enum(StatusAvaliacao::class)],
            'avaliador_id' => ['nullable', 'integer', 'exists:users,id'],
            'ordenar' => ['nullable', Rule::in(array_keys(DesignacaoService::ORDENACOES))],
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
            'situacao' => $this->validated('situacao'),
            'avaliador_id' => $this->validated('avaliador_id'),
            'ordenar' => $this->validated('ordenar') ?? 'designado_em',
            'direcao' => $this->validated('direcao') ?? 'asc',
        ];
    }
}
