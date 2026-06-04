<?php

namespace App\Enums;

/**
 * Papéis de usuário do sistema. Um usuário tem exatamente um papel.
 * Regra de negócio: orientador e avaliador são mutuamente exclusivos
 * (validado no cadastro — ver Sprint 4). Admin é criado apenas por admin.
 */
enum Role: string
{
    case Orientador = 'orientador';
    case Avaliador = 'avaliador';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Orientador => 'Orientador',
            self::Avaliador => 'Avaliador',
            self::Admin => 'Administrador',
        };
    }
}
