<?php

namespace App\Enums;

/**
 * De quem é o documento conferido no credenciamento. São os três papéis que
 * sobem ao evento com o projeto — aluno, orientador e coorientador —, e cada
 * um tem a sua própria lista de documentos exigidos (Parametrização →
 * Credenciamento).
 */
enum TipoPessoaCredenciamento: string
{
    case Aluno = 'aluno';
    case Orientador = 'orientador';
    case Coorientador = 'coorientador';

    public function label(): string
    {
        return match ($this) {
            self::Aluno => 'Aluno',
            self::Orientador => 'Orientador',
            self::Coorientador => 'Coorientador',
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
