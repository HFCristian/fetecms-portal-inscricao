<?php

namespace Tests\Unit;

use App\Rules\Cpf;
use PHPUnit\Framework\TestCase;

class CpfRuleTest extends TestCase
{
    private function fails(string $value): bool
    {
        $failed = false;
        (new Cpf)->validate('cpf', $value, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_aceita_cpfs_validos(): void
    {
        $this->assertFalse($this->fails('52998224725'));
        $this->assertFalse($this->fails('529.982.247-25')); // com máscara
        $this->assertFalse($this->fails('111.444.777-35'));
    }

    public function test_rejeita_cpfs_invalidos(): void
    {
        $this->assertTrue($this->fails('12345678900'));   // dígitos verificadores errados
        $this->assertTrue($this->fails('11111111111'));   // todos iguais
        $this->assertTrue($this->fails('123'));            // tamanho errado
    }
}
