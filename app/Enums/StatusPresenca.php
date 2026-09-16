<?php

namespace App\Enums;

/**
 * Situação da **presença** de uma conta temporária no turno dela.
 *
 * A pessoa marca presença no primeiro acesso e a organização confirma: é o
 * "bati o ponto" do voluntário. O nulo (não marcada) é um quarto estado, e o
 * mais comum antes do evento começar.
 */
enum StatusPresenca: string
{
    case Pendente = 'pendente';
    case Aprovada = 'aprovada';
    case Rejeitada = 'rejeitada';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Aguardando aprovação',
            self::Aprovada => 'Presença aprovada',
            self::Rejeitada => 'Presença rejeitada',
        };
    }
}
