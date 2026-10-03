<?php

namespace App\Enums;

/** O tipo de suporte que um projeto finalista pede para o evento (Sprint 162). */
enum TipoSuporte: string
{
    case Acompanhante = 'acompanhante';
    case InterpreteLibras = 'interprete_libras';
    case InterpreteLingua = 'interprete_lingua';

    public function label(): string
    {
        return match ($this) {
            self::Acompanhante => 'Acompanhante',
            self::InterpreteLibras => 'Intérprete de Libras',
            self::InterpreteLingua => 'Intérprete de outra língua',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
