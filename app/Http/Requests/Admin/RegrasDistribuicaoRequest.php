<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use App\Support\RegrasDistribuicao;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Regras do algoritmo de distribuição (Avaliação Online → Algoritmo de
 * distribuição). O formulário salva as três categorias de uma vez, então o
 * mapa vem inteiro — cada chave é o valor de uma {@see Categoria}.
 */
class RegrasDistribuicaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    public function rules(): array
    {
        $teto = RegrasDistribuicao::MAX_CONCLUIDAS;

        $regras = [
            'regras' => ['required', 'array'],
            'regras.*' => ['array'],
        ];

        foreach (Categoria::cases() as $categoria) {
            $chave = 'regras.'.$categoria->value;

            $regras[$chave] = ['required', 'array'];
            $regras[$chave.'.ativa'] = ['required', 'boolean'];
            $regras[$chave.'.min_concluidas'] = ['required', 'integer', 'min:0', 'max:'.$teto];
            // Faixa aberta em cima: nulo = sem teto de avaliações concluídas.
            $regras[$chave.'.max_concluidas'] = [
                'nullable', 'integer', 'min:0', 'max:'.$teto,
                'gte:'.$chave.'.min_concluidas',
            ];
        }

        return $regras;
    }

    public function attributes(): array
    {
        $nomes = [];

        foreach (Categoria::cases() as $categoria) {
            $chave = 'regras.'.$categoria->value;
            $nomes[$chave.'.ativa'] = 'distribuição da categoria '.$categoria->label();
            $nomes[$chave.'.min_concluidas'] = 'mínimo de avaliações concluídas da '.$categoria->label();
            $nomes[$chave.'.max_concluidas'] = 'máximo de avaliações concluídas da '.$categoria->label();
        }

        return $nomes;
    }
}
