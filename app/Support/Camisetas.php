<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Contagem de camisetas por tamanho (painel do admin).
 *
 * Os tamanhos vêm dos formulários: o orientador escolhe até XG e aluno/
 * coorientador até GG, então a lista canônica é a união das duas.
 *
 * Só os tamanhos conhecidos são listados: quem está sem tamanho (ou com um
 * valor de cadastro antigo, fora da lista) **não aparece**. A soma dos tamanhos
 * pode, portanto, ser menor que o `total` do card — que segue sendo o número de
 * pessoas do recorte, e não o de camisetas encomendáveis.
 */
class Camisetas
{
    /** @var list<string> */
    public const TAMANHOS = ['PP', 'P', 'M', 'G', 'GG', 'XG'];

    /**
     * Quantas camisetas de cada tamanho no recorte da query.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  já filtrada (tabela com coluna `camiseta`)
     * @return array{total:int, tamanhos: list<array{tamanho:string, total:int}>}
     */
    public static function contar(Builder $query, int $total): array
    {
        $brutos = (clone $query)
            ->whereNotNull('camiseta')
            ->groupBy('camiseta')
            ->selectRaw('camiseta, count(*) as total')
            ->pluck('total', 'camiseta');

        // Normaliza a chave — o banco guarda o que o formulário mandou.
        $porTamanho = [];
        foreach ($brutos as $camiseta => $qtd) {
            $chave = strtoupper(trim((string) $camiseta));
            if (in_array($chave, self::TAMANHOS, true)) {
                $porTamanho[$chave] = ($porTamanho[$chave] ?? 0) + (int) $qtd;
            }
        }

        $tamanhos = array_map(fn (string $t) => [
            'tamanho' => $t,
            'total' => $porTamanho[$t] ?? 0,
        ], self::TAMANHOS);

        return ['total' => $total, 'tamanhos' => $tamanhos];
    }
}
