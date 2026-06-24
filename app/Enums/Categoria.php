<?php

namespace App\Enums;

/**
 * Categorias da feira. A categoria define o limite de alunos por projeto
 * (regra de negócio aplicada no E4): FETEC Jr até 3; FETECMS FUNDECT até 4;
 * FETECMS até 3 (ou 4 quando contemplado pelo programa PICTEC MS); mínimo 1.
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

    /**
     * Limite de alunos da categoria. O flag PICTEC MS só tem efeito na FETECMS,
     * onde eleva o limite de 3 para 4; nas demais categorias é ignorado.
     */
    public function maxAlunos(bool $pictecMs = false): int
    {
        return match ($this) {
            self::FetecJr => 3,
            self::FetecmsFundect => 4,
            self::Fetecms => $pictecMs ? 4 : 3,
        };
    }

    /** Indica se a categoria pode ser contemplada pelo programa PICTEC MS. */
    public function permitePictec(): bool
    {
        return $this === self::Fetecms;
    }

    public const MIN_ALUNOS = 1;
}
