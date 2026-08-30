<?php

namespace App\Enums;

/**
 * Como o comitê está se deslocando. Além de aparecer no mapa, é o que define o
 * perfil de rota pedido ao provedor (dirigindo, a pé, transporte público).
 */
enum MeioTransporte: string
{
    case Carro = 'carro';
    case Van = 'van';
    case Onibus = 'onibus';
    case APe = 'a_pe';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Carro => 'Carro',
            self::Van => 'Van',
            self::Onibus => 'Ônibus',
            self::APe => 'A pé',
            self::Outro => 'Outro',
        };
    }

    /** Modo de viagem do Google Directions correspondente. */
    public function modoRota(): string
    {
        return match ($this) {
            self::APe => 'WALKING',
            self::Onibus => 'TRANSIT',
            default => 'DRIVING',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
