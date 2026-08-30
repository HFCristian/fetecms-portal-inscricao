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

    /**
     * Sigla de três letras usada na lista final da feira (FET.AGR-001):
     * FET para a FETECMS, JR para a FETEC Jr e PIC para a FETECMS FUNDECT.
     */
    public function sigla(): string
    {
        return match ($this) {
            self::Fetecms => 'FET',
            self::FetecJr => 'JR',
            self::FetecmsFundect => 'PIC',
        };
    }

    /**
     * Ordem em que as categorias saem na lista final — FETECMS, FETEC Jr e
     * FETECMS FUNDECT —, que não é a ordem de declaração do enum.
     *
     * @return list<self>
     */
    public static function ordemDaLista(): array
    {
        return [self::Fetecms, self::FetecJr, self::FetecmsFundect];
    }

    /** Indica se a categoria pode ser contemplada pelo programa PICTEC MS. */
    public function permitePictec(): bool
    {
        return $this === self::Fetecms;
    }

    /**
     * A categoria reserva vagas da lista final para o **interior**? Só a
     * FETECMS FUNDECT: a cota de escolas fora da capital é uma exigência do
     * fomento dela, e nas demais a lista é só por nota.
     */
    public function permiteCotaInterior(): bool
    {
        return $this === self::FetecmsFundect;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }

    public const MIN_ALUNOS = 1;
}
