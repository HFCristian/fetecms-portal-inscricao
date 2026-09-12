<?php

namespace App\Enums;

/**
 * Os dois turnos de apresentação da feira.
 *
 * O mesmo estande recebe um projeto de manhã e **outro** à tarde — é assim que
 * 230 estandes acomodam até 460 trabalhos. Por isso o turno é do **projeto**, e
 * não do estande: cada projeto apresenta uma vez só.
 *
 * Os nomes de guerra da organização são "turno A" e "turno B"; os rótulos
 * trazem o horário porque é o que o finalista lê no documento.
 */
enum Turno: string
{
    case A = 'A';
    case B = 'B';

    public function label(): string
    {
        return match ($this) {
            self::A => 'Turno A (matutino)',
            self::B => 'Turno B (vespertino)',
        };
    }

    public function curto(): string
    {
        return match ($this) {
            self::A => 'Matutino',
            self::B => 'Vespertino',
        };
    }

    /** O outro turno — é para onde vai quem não pode estar neste. */
    public function oposto(): self
    {
        return $this === self::A ? self::B : self::A;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, array{value: string, label: string, curto: string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'curto' => $t->curto(),
        ], self::cases());
    }
}
