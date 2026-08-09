<?php

namespace Tests\Unit;

use App\Support\Tempo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TempoTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function casos(): array
    {
        return [
            'zero' => [0, '0 segundos'],
            'um segundo' => [1, '1 segundo'],
            'segundos' => [45, '45 segundos'],
            'um minuto exato' => [60, '1 minuto'],
            'minuto e segundos' => [65, '1 minuto e 5 segundos'],
            'minuto e um segundo' => [61, '1 minuto e 1 segundo'],
            'minutos exatos' => [120, '2 minutos'],
            'minutos e segundos' => [125, '2 minutos e 5 segundos'],
            'negativo vira zero' => [-10, '0 segundos'],
        ];
    }

    #[DataProvider('casos')]
    public function test_humanizar(int $segundos, string $esperado): void
    {
        $this->assertSame($esperado, Tempo::humanizar($segundos));
    }
}
