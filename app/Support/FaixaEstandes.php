<?php

namespace App\Support;

/**
 * A faixa de estandes de uma categoria, escrita como a organização escreve:
 * `1-4, 7-9, 10-52` — ou número a número, `1, 2, 3`, ou os dois misturados.
 *
 * Guardar o **texto** que o admin digitou (e não a lista expandida) é
 * deliberado: é assim que a faixa volta para a tela igual ao que ele escreveu,
 * e uma lista de 230 números no banco não diz a ninguém que a intenção era
 * "o bloco da frente inteiro".
 */
final class FaixaEstandes
{
    /** Teto de segurança: ninguém digita faixa maior que isso sem ser engano. */
    public const MAX_ESTANDES = 5000;

    /**
     * Os números da faixa, em ordem, sem repetição.
     *
     * Entrada malformada não estoura: o que não for número ou intervalo é
     * ignorado — a tela valida antes, e um caractere perdido não pode derrubar
     * a geração inteira no dia do evento.
     *
     * @return list<int>
     */
    public static function expandir(?string $texto): array
    {
        $numeros = [];

        foreach (preg_split('/[,;\n]+/', (string) $texto) as $parte) {
            $parte = trim($parte);

            if ($parte === '') {
                continue;
            }

            // "10-52": intervalo, inclusive nas duas pontas. Aceita o traço
            // comum e o travessão, que é o que o Word põe no lugar dele.
            if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $parte, $m)) {
                [$de, $ate] = [(int) $m[1], (int) $m[2]];

                // "52-10" é claramente a mesma intenção escrita ao contrário.
                if ($de > $ate) {
                    [$de, $ate] = [$ate, $de];
                }

                for ($n = $de; $n <= $ate && count($numeros) < self::MAX_ESTANDES; $n++) {
                    $numeros[$n] = true;
                }

                continue;
            }

            if (preg_match('/^\d+$/', $parte)) {
                $numeros[(int) $parte] = true;
            }
        }

        $lista = array_keys($numeros);
        sort($lista);

        return array_values(array_filter($lista, fn (int $n) => $n > 0));
    }

    /**
     * O caminho inverso: uma lista de números vira o texto compacto, com os
     * consecutivos agrupados em intervalo. Serve para a tela sugerir a faixa
     * dos números que sobraram.
     *
     * @param  list<int>  $numeros
     */
    public static function comprimir(array $numeros): string
    {
        $numeros = array_values(array_unique(array_filter($numeros, fn ($n) => (int) $n > 0)));
        sort($numeros);

        if ($numeros === []) {
            return '';
        }

        $blocos = [];
        $inicio = $anterior = $numeros[0];

        foreach (array_slice($numeros, 1) as $n) {
            if ($n === $anterior + 1) {
                $anterior = $n;

                continue;
            }

            $blocos[] = $inicio === $anterior ? (string) $inicio : "{$inicio}-{$anterior}";
            $inicio = $anterior = $n;
        }

        $blocos[] = $inicio === $anterior ? (string) $inicio : "{$inicio}-{$anterior}";

        return implode(', ', $blocos);
    }

    /**
     * Os números que duas ou mais faixas disputam.
     *
     * Um estande em duas categorias é um conflito de verdade — dois projetos
     * disputando o mesmo lugar físico —, então quem chama recusa a configuração
     * em vez de escolher por conta própria.
     *
     * @param  array<string, string|null>  $faixas  categoria => texto da faixa
     * @return list<int>
     */
    public static function sobrepostos(array $faixas): array
    {
        $vistos = [];
        $conflitos = [];

        foreach ($faixas as $texto) {
            foreach (self::expandir($texto) as $n) {
                if (isset($vistos[$n])) {
                    $conflitos[$n] = true;

                    continue;
                }

                $vistos[$n] = true;
            }
        }

        $lista = array_keys($conflitos);
        sort($lista);

        return $lista;
    }
}
