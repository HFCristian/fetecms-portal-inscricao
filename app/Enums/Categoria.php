<?php

namespace App\Enums;

/**
 * Categorias da feira. A categoria define o limite de alunos por projeto
 * (regra de negócio aplicada no E4): FETEC Jr até 4; demais até 3; mínimo 1.
 */
enum Categoria: string
{
    case FetecJr = 'fetec_jr';
    case Fetecms = 'fetecms';
    case FetecmsFundect = 'fetecms_fundect';

    public function label(): string
    {
        return match ($this) {
            self::FetecJr => 'FETEC Jr',
            self::Fetecms => 'FETECMS',
            self::FetecmsFundect => 'FETECMS FUNDECT',
        };
    }

    public function maxAlunos(): int
    {
        return $this === self::FetecJr ? 4 : 3;
    }

    public const MIN_ALUNOS = 1;
}
