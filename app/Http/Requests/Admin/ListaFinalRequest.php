<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cotas da lista final (Ranking dos projetos → Gerar lista final). Todas são
 * opcionais: cota em branco não limita nada, e uma cota 0 deixa aquele recorte
 * inteiro de fora.
 */
class ListaFinalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    public function rules(): array
    {
        return [
            'total' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'categorias' => ['nullable', 'array'],
            'categorias.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'areas' => ['nullable', 'array'],
            'areas.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                foreach (array_keys((array) $this->input('categorias', [])) as $chave) {
                    if (Categoria::tryFrom((string) $chave) === null) {
                        $validator->errors()->add("categorias.{$chave}", 'Categoria desconhecida.');
                    }
                }
            },
        ];
    }

    /**
     * As cotas prontas para o serviço, sem os campos em branco.
     *
     * @return array{total:int|null, categorias:array<string,int>, areas:array<int,int>}
     */
    public function cotas(): array
    {
        $limpar = fn (?array $mapa) => array_map(
            'intval',
            array_filter((array) $mapa, fn ($valor) => $valor !== null && $valor !== ''),
        );

        return [
            'total' => $this->input('total') === null ? null : (int) $this->input('total'),
            'categorias' => $limpar($this->input('categorias')),
            'areas' => $limpar($this->input('areas')),
        ];
    }

    public function attributes(): array
    {
        return ['total' => 'quantidade total de projetos'];
    }
}
