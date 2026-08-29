<?php

namespace App\Support;

/**
 * Uma cota da lista final, que o admin preenche em **número fixo** ou em
 * **porcentagem** do recorte que a contém (o total para a categoria, a cota da
 * categoria para a área, a cota da área para o interior).
 *
 * Campo em branco vira `null` — cota ausente não limita nada. Cota 0 é
 * diferente de branco: deixa o recorte inteiro de fora.
 */
class Cota
{
    public const FIXO = 'fixo';

    public const PERCENTUAL = 'percentual';

    private function __construct(
        public readonly string $tipo,
        public readonly float $valor,
    ) {}

    /**
     * Lê o que veio do formulário. Aceita o formato completo
     * `['tipo' => 'percentual', 'valor' => 70]` e o número solto (que vale
     * como fixo). Devolve null quando não há cota.
     */
    public static function de(mixed $dados): ?self
    {
        if ($dados === null || $dados === '') {
            return null;
        }

        if (is_array($dados)) {
            $valor = $dados['valor'] ?? null;

            if ($valor === null || $valor === '') {
                return null;
            }

            $tipo = ($dados['tipo'] ?? self::FIXO) === self::PERCENTUAL ? self::PERCENTUAL : self::FIXO;

            return new self($tipo, (float) $valor);
        }

        return new self(self::FIXO, (float) $dados);
    }

    /**
     * O número de vagas que esta cota representa. A porcentagem é sobre a base
     * (arredondada para o inteiro mais próximo); o fixo ignora a base.
     */
    public function resolver(int $base): int
    {
        $vagas = $this->tipo === self::PERCENTUAL
            ? (int) round($base * $this->valor / 100)
            : (int) $this->valor;

        return max(0, $vagas);
    }

    /** Como a cota aparece na tela e nos testes ("70%" ou "20"). */
    public function rotulo(): string
    {
        $numero = rtrim(rtrim(number_format($this->valor, 2, ',', ''), '0'), ',');

        return $this->tipo === self::PERCENTUAL ? $numero.'%' : $numero;
    }
}
