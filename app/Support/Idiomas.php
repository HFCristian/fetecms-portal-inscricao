<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Idiomas em que o avaliador se declara apto a avaliar.
 *
 * A feira recebe projetos e visitantes de fora do Brasil, e a banca precisa
 * saber quem consegue conduzir uma avaliação em espanhol ou inglês antes do
 * dia — perguntar no corredor não escala.
 *
 * É uma **lista**, não um campo único: quem fala três idiomas marca os três, e
 * a distribuição continua sendo por área. O idioma não entra no algoritmo de
 * distribuição de propósito — ele informa a organização, que designa à mão o
 * projeto estrangeiro a quem pode avaliá-lo.
 *
 * Os códigos são ISO 639-1 (`pt`, `es`, `en`) e não os rótulos: rótulo muda de
 * redação, código não, e é o código que está gravado em `avaliador_profiles.idiomas`.
 */
class Idiomas
{
    /** @var array<string, string> código ISO 639-1 => rótulo em pt-BR */
    public const IDIOMAS = [
        'pt' => 'Português',
        'es' => 'Espanhol',
        'en' => 'Inglês',
    ];

    /** @return list<string> */
    public static function codigos(): array
    {
        return array_keys(self::IDIOMAS);
    }

    public static function label(string $codigo): string
    {
        return self::IDIOMAS[$codigo] ?? $codigo;
    }

    /**
     * As opções como a tela as desenha.
     *
     * @return list<array{value:string, label:string}>
     */
    public static function opcoes(): array
    {
        return array_map(
            fn (string $codigo) => ['value' => $codigo, 'label' => self::IDIOMAS[$codigo]],
            self::codigos(),
        );
    }

    /**
     * Normaliza o que veio do formulário: só códigos conhecidos, sem repetição
     * e na ordem canônica — assim duas pessoas que marcaram os mesmos idiomas
     * têm o mesmo valor gravado, e a comparação em teste não depende da ordem
     * em que se clicou nas caixas.
     *
     * @param  iterable<mixed>|null  $valores
     * @return list<string>
     */
    public static function normalizar(mixed $valores): array
    {
        if (! is_iterable($valores)) {
            return [];
        }

        $marcados = [];
        foreach ($valores as $valor) {
            $codigo = is_string($valor) ? strtolower(trim($valor)) : null;
            if ($codigo !== null && isset(self::IDIOMAS[$codigo])) {
                $marcados[$codigo] = true;
            }
        }

        return array_values(array_filter(self::codigos(), fn (string $c) => isset($marcados[$c])));
    }

    /** Os rótulos, na ordem canônica — o que vai para a tabela e para o CSV. */
    public static function rotulos(mixed $valores): string
    {
        return implode(', ', array_map(self::label(...), self::normalizar($valores)));
    }

    /**
     * Quantos avaliadores declararam cada idioma, no recorte da query.
     *
     * Ao contrário da camiseta, **a soma passa do total**: quem marcou três
     * idiomas conta nos três. O número grande continua sendo o de pessoas.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  já filtrada (tabela com coluna `idiomas`)
     * @return array{total:int, idiomas: list<array{codigo:string, label:string, total:int}>}
     */
    public static function contar(Builder $query, int $total): array
    {
        $idiomas = array_map(fn (string $codigo) => [
            'codigo' => $codigo,
            'label' => self::IDIOMAS[$codigo],
            'total' => (clone $query)->whereJsonContains('idiomas', $codigo)->count(),
        ], self::codigos());

        return ['total' => $total, 'idiomas' => $idiomas];
    }
}
