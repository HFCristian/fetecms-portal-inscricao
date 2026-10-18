<?php

namespace App\Enums;

/**
 * Situação do pedido de permissão do avaliador do comitê para avaliar no
 * estande um projeto fora das designações dele (Sprint 234).
 */
enum StatusPedidoPermissao: string
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
