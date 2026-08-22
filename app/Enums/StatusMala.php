<?php

namespace App\Enums;

/**
 * Situação de uma mala direta. Não há rascunho: a mala nasce no momento do
 * disparo (o admin confirma a mensagem antes) e só existe enquanto envia ou
 * depois de concluída — com ou sem falhas no relatório.
 */
enum StatusMala: string
{
    case Enviando = 'enviando';
    case Concluida = 'concluida';

    public function label(): string
    {
        return match ($this) {
            self::Enviando => 'Enviando',
            self::Concluida => 'Concluída',
        };
    }
}
