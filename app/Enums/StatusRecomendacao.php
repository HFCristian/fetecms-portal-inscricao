<?php

namespace App\Enums;

/**
 * Situação de uma recomendação do comitê especial (Sprint 193): o avaliador da
 * comissão recomenda um projeto para uma credencial ou prêmio, e a
 * organização aprova ou rejeita. Aprovar **não** premia — a atribuição da
 * credencial continua sendo um passo à parte, em Credenciais e Prêmios.
 */
enum StatusRecomendacao: string
{
    case Pendente = 'pendente';
    case Aprovada = 'aprovada';
    case Rejeitada = 'rejeitada';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Aguardando a organização',
            self::Aprovada => 'Aprovada',
            self::Rejeitada => 'Rejeitada',
        };
    }
}
