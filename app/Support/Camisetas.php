<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Contagem de camisetas por tamanho (painel do admin).
 *
 * Os tamanhos vêm dos formulários: o orientador escolhe até XG e aluno/
 * coorientador até GG, então a lista canônica é a união das duas. Quem está sem
 * tamanho (ou com um valor fora da lista, de cadastro antigo) cai no balde
 * "N.I." — assim a soma dos tamanhos sempre fecha com o total do card.
 */
class Camisetas
{
    /** @var list<string> */
    public const TAMANHOS = ['PP', 'P', 'M', 'G', 'GG', 'XG'];

    /** Rótulo do balde de quem não informou tamanho. */
    public const NAO_INFORMADO = 'N.I.';

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

        // Normaliza a chave (o banco guarda o que o formulário mandou) e soma o
        // que sobrar em N.I.: total − o que casou com um tamanho conhecido.
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

        $tamanhos[] = [
            'tamanho' => self::NAO_INFORMADO,
            'total' => max(0, $total - array_sum($porTamanho)),
        ];

        return ['total' => $total, 'tamanhos' => $tamanhos];
    }
}
