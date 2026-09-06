<?php

namespace App\Support;

use App\Enums\Categoria;

/**
 * Regras do algoritmo de distribuição, uma por categoria (aba Avaliação
 * Online → Algoritmo de distribuição):
 *
 * - `ativa`: a categoria entra na distribuição automática;
 * - `min_concluidas` / `max_concluidas`: faixa de avaliações que o PROJETO já
 *   recebeu (as concluídas) para ele ainda aceitar um avaliador novo — a conta é
 *   por projeto, não pela carga do avaliador. `max` nulo = sem teto, então "só
 *   FETEC Jr com 0 avaliações" é min 0 / máx 0 e "FUNDECT de 0 a 1" é min 0 / máx 1.
 * - `designacoes`: quantos avaliadores a distribuição designa para cada projeto
 *   desta categoria. Nulo segue o número geral da edição, que por sua vez segue
 *   o mínimo por projeto — o comportamento histórico. Quem sabe o valor que vale
 *   de fato é o {@see LimitesAvaliacao}, que o limita ao máximo da categoria.
 *
 * O padrão é o comportamento histórico: toda categoria ativa, sem faixa. Vale
 * para a distribuição em massa, para a reposição da fila do avaliador e para o
 * sorteio — a designação manual do admin passa por cima (escape do edital).
 */
final class RegrasDistribuicao
{
    /** Teto de avaliações que a faixa admite (mesma ordem de grandeza dos mínimos). */
    public const MAX_CONCLUIDAS = 50;

    /** @var array<string, array{ativa:bool, min_concluidas:int, max_concluidas:int|null, designacoes:int|null}> */
    private array $regras;

    /** @param  array<string, mixed>  $regras */
    public function __construct(array $regras = [])
    {
        $this->regras = [];

        foreach (Categoria::cases() as $categoria) {
            $this->regras[$categoria->value] = self::normalizar($regras[$categoria->value] ?? []);
        }
    }

    /** As regras gravadas na edição (ou o padrão, quando nunca configuradas). */
    public static function deArray(?array $bruto): self
    {
        return new self($bruto ?? []);
    }

    /**
     * Uma regra vinda do banco/request, com os padrões preenchidos.
     *
     * @param  array<string, mixed>  $regra
     * @return array{ativa:bool, min_concluidas:int, max_concluidas:int|null, designacoes:int|null}
     */
    private static function normalizar(array $regra): array
    {
        $min = max(0, (int) ($regra['min_concluidas'] ?? 0));
        $max = $regra['max_concluidas'] ?? null;
        $max = $max === null || $max === '' ? null : max($min, (int) $max);

        $designacoes = $regra['designacoes'] ?? null;

        return [
            'ativa' => (bool) ($regra['ativa'] ?? true),
            'min_concluidas' => $min,
            'max_concluidas' => $max,
            // Em branco (ou zero) a categoria segue o número geral.
            'designacoes' => $designacoes === null || $designacoes === '' || (int) $designacoes < 1
                ? null
                : (int) $designacoes,
        ];
    }

    /** @return array{ativa:bool, min_concluidas:int, max_concluidas:int|null, designacoes:int|null} */
    public function para(Categoria $categoria): array
    {
        return $this->regras[$categoria->value];
    }

    /**
     * Quantas designações esta categoria pede, como está gravado — `null`
     * quando ela segue o número geral. Quem resolve o valor final (com o teto do
     * máximo por projeto) é o {@see LimitesAvaliacao}.
     */
    public function designacoesDe(?Categoria $categoria): ?int
    {
        return $categoria === null ? null : $this->para($categoria)['designacoes'];
    }

    /**
     * O projeto pode receber mais um avaliador automático? `$concluidas` é
     * quantas avaliações concluídas ELE já recebeu. Projeto sem categoria não
     * tem regra que o alcance e segue elegível.
     */
    public function aceita(?Categoria $categoria, int $concluidas): bool
    {
        if ($categoria === null) {
            return true;
        }

        $regra = $this->para($categoria);

        return $regra['ativa']
            && $concluidas >= $regra['min_concluidas']
            && ($regra['max_concluidas'] === null || $concluidas <= $regra['max_concluidas']);
    }

    /** Alguma categoria ficou de fora ou com faixa? Usado só para avisar na tela. */
    public function restringe(): bool
    {
        return $this->toArray() !== (new self)->toArray();
    }

    /** @return array<string, array{ativa:bool, min_concluidas:int, max_concluidas:int|null, designacoes:int|null}> */
    public function toArray(): array
    {
        return $this->regras;
    }

    /**
     * Uma linha legível das regras, para o "de → para" da trilha de registros.
     * Ex.: "FETECMS: 0+ · FETEC Jr: 0 a 0 · FETECMS FUNDECT: fora".
     */
    public function resumo(): string
    {
        $partes = array_map(function (Categoria $c) {
            $r = $this->para($c);

            if (! $r['ativa']) {
                return $c->label().': fora';
            }

            $faixa = $r['max_concluidas'] === null
                ? $r['min_concluidas'].'+'
                : $r['min_concluidas'].' a '.$r['max_concluidas'];

            $designacoes = $r['designacoes'] === null ? '' : ', '.$r['designacoes'].' designação(ões)';

            return $c->label().': '.$faixa.$designacoes;
        }, Categoria::cases());

        return implode(' · ', $partes);
    }
}
