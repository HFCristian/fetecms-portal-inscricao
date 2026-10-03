<?php

namespace App\Enums;

/** Situação de um pedido de suporte: o orientador pede, a organização decide. */
enum StatusSuporte: string
{
    case Pendente = 'pendente';
    case Aprovado = 'aprovado';
    case Recusado = 'recusado';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Aguardando a organização',
            self::Aprovado => 'Aprovado',
            self::Recusado => 'Recusado',
        };
    }
}
