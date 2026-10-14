<?php

namespace App\Support;

/**
 * Senha gerada pelo portal para entregar em mãos: 10 caracteres sem os que se
 * confundem lidos num papel (0/O, 1/l/I), com letra e número garantidos.
 *
 * Usada nas contas temporárias em lote (Sprint 155) e no cadastro de
 * avaliadores do evento presencial (Sprint 191). O portal guarda só o hash,
 * então a senha é mostrada uma vez.
 */
final class SenhaGerada
{
    public static function nova(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $numeros = '23456789';
        $todos = $letras.$numeros;

        $senha = [$letras[random_int(0, strlen($letras) - 1)], $numeros[random_int(0, strlen($numeros) - 1)]];

        while (count($senha) < 10) {
            $senha[] = $todos[random_int(0, strlen($todos) - 1)];
        }

        // Fisher–Yates com random_int: o shuffle() do PHP não é criptográfico.
        for ($i = count($senha) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$senha[$i], $senha[$j]] = [$senha[$j], $senha[$i]];
        }

        return implode('', $senha);
    }
}
