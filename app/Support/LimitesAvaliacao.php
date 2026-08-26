<?php

namespace App\Support;

use App\Enums\Categoria;
use App\Models\Avaliacao;
use App\Models\Edicao;

/**
 * Os limites de avaliação da edição (Parametrização → Avaliação Online), lidos
 * de uma vez só para não consultar a edição projeto a projeto.
 *
 * - **por avaliador**: o mínimo é quantas avaliações ele precisa concluir — e
 *   quantos projetos vê de uma vez na tela; o máximo é o teto de avaliações que
 *   ele pode acumular no total (em branco = sem teto, que é como a fila sempre
 *   funcionou). O bloqueio individual do admin continua valendo por cima.
 * - **por projeto**: o mínimo é o alvo da distribuição e a base das colunas de
 *   "faltantes"; o máximo é quantos avaliadores enxergam o projeto. Os dois
 *   podem ser definidos **por categoria** — o que ficar em branco segue o
 *   número geral.
 */
final class LimitesAvaliacao
{
    /** Teto que a tela aceita em qualquer um dos campos. */
    public const MAXIMO = 50;

    /** @param  array<string, array{min:int|null, max:int|null}>  $porCategoria */
    public function __construct(
        private readonly int $minPorAvaliador,
        private readonly ?int $maxPorAvaliador,
        private readonly int $minPorProjeto,
        private readonly int $maxPorProjeto,
        private readonly array $porCategoria = [],
    ) {}

    public static function daEdicao(?Edicao $edicao): self
    {
        $minAvaliador = $edicao?->avaliacoes_min_por_avaliador ?? Edicao::PADRAO_MIN_POR_AVALIADOR;
        $minProjeto = $edicao?->avaliacoes_min_por_projeto ?? Edicao::PADRAO_MIN_POR_PROJETO;

        $maxAvaliador = $edicao?->avaliacoes_max_por_avaliador;

        return new self(
            minPorAvaliador: $minAvaliador,
            // Nunca abaixo do mínimo: um teto menor deixaria a fila impossível.
            maxPorAvaliador: $maxAvaliador === null ? null : max($minAvaliador, $maxAvaliador),
            minPorProjeto: $minProjeto,
            maxPorProjeto: max($minProjeto, $edicao?->avaliacoes_max_por_projeto ?? Avaliacao::TETO_POR_PROJETO),
            porCategoria: self::normalizar($edicao?->avaliacoes_por_categoria),
        );
    }

    /**
     * @param  array<string, mixed>|null  $bruto
     * @return array<string, array{min:int|null, max:int|null}>
     */
    private static function normalizar(?array $bruto): array
    {
        $limpo = [];

        foreach (Categoria::cases() as $categoria) {
            $item = $bruto[$categoria->value] ?? [];
            $min = $item['min'] ?? null;
            $max = $item['max'] ?? null;

            $limpo[$categoria->value] = [
                'min' => $min === null || $min === '' ? null : (int) $min,
                'max' => $max === null || $max === '' ? null : (int) $max,
            ];
        }

        return $limpo;
    }

    public function minPorAvaliador(): int
    {
        return $this->minPorAvaliador;
    }

    /** Teto total de avaliações do avaliador; null quando não há teto. */
    public function maxPorAvaliador(): ?int
    {
        return $this->maxPorAvaliador;
    }

    /** Alvo de avaliações concluídas do projeto — o da categoria, se houver. */
    public function minPorProjeto(?Categoria $categoria = null): int
    {
        return $this->porCategoria[$categoria?->value]['min'] ?? $this->minPorProjeto;
    }

    /**
     * Quantos avaliadores no máximo enxergam o projeto. Nunca menor que o
     * mínimo da mesma categoria: um alvo inalcançável travaria a distribuição.
     */
    public function maxPorProjeto(?Categoria $categoria = null): int
    {
        $max = $this->porCategoria[$categoria?->value]['max'] ?? $this->maxPorProjeto;

        return max($max, $this->minPorProjeto($categoria));
    }

    /**
     * As três categorias com o que está gravado (null = segue o geral) e o
     * número que vale hoje, para a tela mostrar o efeito.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categorias(): array
    {
        return array_map(fn (Categoria $c) => [
            'value' => $c->value,
            'label' => $c->label(),
            'min' => $this->porCategoria[$c->value]['min'],
            'max' => $this->porCategoria[$c->value]['max'],
            'min_efetivo' => $this->minPorProjeto($c),
            'max_efetivo' => $this->maxPorProjeto($c),
        ], Categoria::cases());
    }

    /**
     * Todas as categorias pedem o mesmo mínimo? Quando não, as telas de resumo
     * falam em "mínimo da categoria" em vez de cravar um número.
     */
    public function minUniforme(): bool
    {
        foreach (Categoria::cases() as $categoria) {
            if ($this->minPorProjeto($categoria) !== $this->minPorProjeto()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array{min:int|null, max:int|null}> */
    public function porCategoria(): array
    {
        return $this->porCategoria;
    }

    /**
     * Uma linha legível dos limites por categoria, para o "de → para" da trilha
     * de registros. Ex.: "FETEC Jr: 2 a 4 · FETECMS: geral".
     */
    public function resumoCategorias(): string
    {
        $partes = array_map(function (Categoria $c) {
            $item = $this->porCategoria[$c->value];

            return $c->label().': '.($item['min'] === null && $item['max'] === null
                ? 'geral'
                : ($item['min'] ?? $this->minPorProjeto).' a '.($item['max'] ?? $this->maxPorProjeto));
        }, Categoria::cases());

        return implode(' · ', $partes);
    }
}
