<?php

namespace App\Enums;

/**
 * Ciclo de vida do projeto. Após `submetido` não há volta (regra de edital).
 */
enum ProjetoStatus: string
{
    case Rascunho = 'rascunho';
    case Submetido = 'submetido';
    case Aprovado = 'aprovado';
    case Rejeitado = 'rejeitado';

    public function label(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Submetido => 'Submetido',
            self::Aprovado => 'Aprovado',
            self::Rejeitado => 'Rejeitado',
        };
    }

    public function editavel(): bool
    {
        return $this === self::Rascunho;
    }
}
