<?php

namespace App\Support;

/**
 * Formatação de tempo por extenso em pt_BR para mensagens ao usuário
 * (ex.: espera de bloqueio por excesso de tentativas de login).
 */
class Tempo
{
    /** Segundos → texto pt_BR ("45 segundos", "1 minuto", "2 minutos e 5 segundos"). */
    public static function humanizar(int $segundos): string
    {
        $segundos = max(0, $segundos);

        if ($segundos < 60) {
            return self::plural($segundos, 'segundo');
        }

        $minutos = intdiv($segundos, 60);
        $resto = $segundos % 60;

        return $resto === 0
            ? self::plural($minutos, 'minuto')
            : self::plural($minutos, 'minuto').' e '.self::plural($resto, 'segundo');
    }

    /**
     * Minutos → carga horária curta em pt_BR ("2h30", "5h", "0h"). É o formato
     * do certificado do avaliador, onde o total sempre cai em hora cheia ou
     * meia hora.
     */
    public static function cargaHoraria(int $minutos): string
    {
        $minutos = max(0, $minutos);
        $resto = $minutos % 60;

        return intdiv($minutos, 60).'h'.($resto === 0 ? '' : str_pad((string) $resto, 2, '0', STR_PAD_LEFT));
    }

    private static function plural(int $valor, string $unidade): string
    {
        return $valor.' '.($valor === 1 ? $unidade : $unidade.'s');
    }
}
