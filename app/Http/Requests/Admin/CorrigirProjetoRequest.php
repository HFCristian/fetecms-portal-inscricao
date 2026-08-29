<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use App\Rules\SubareaDaArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correção de um projeto submetido pelo admin. Só chegam os campos que a tela
 * mandou — o que não vier fica como está —, e a justificativa é sempre exigida:
 * é ela que explica, na trilha de registros, por que a inscrição de outra
 * pessoa foi alterada.
 */
class CorrigirProjetoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categoria' => ['sometimes', 'nullable', Rule::enum(Categoria::class)],
            'area_id' => ['sometimes', 'nullable', 'integer', 'exists:areas,id'],
            'subarea_id' => [
                'sometimes', 'nullable', 'integer', 'exists:subareas,id',
                // A subárea é conferida contra a área que vai VALER depois da
                // correção: a que veio no payload ou, se a tela não mandou área,
                // a que o projeto já tem.
                new SubareaDaArea($this->areaEfetiva()),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! empty($value) && empty($this->areaEfetiva())) {
                        $fail('Escolha a área antes da subárea.');
                    }
                },
            ],
            'link_video' => ['sometimes', 'nullable', 'url', 'max:255'],
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** A área que o projeto terá depois desta correção. */
    private function areaEfetiva(): mixed
    {
        if ($this->has('area_id')) {
            return $this->input('area_id');
        }

        $projeto = $this->route('projeto');

        return $projeto?->area_id;
    }

    public function attributes(): array
    {
        return [
            'area_id' => 'área',
            'subarea_id' => 'subárea',
            'link_video' => 'link do vídeo',
        ];
    }
}
