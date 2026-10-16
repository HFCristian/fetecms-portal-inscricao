<?php

namespace App\Enums;

/**
 * O que um intérprete faz (Sprint 214). São dois grupos: **línguas**
 * estrangeiras — a feira recebe projeto de fora — e o atendimento à **pessoa
 * com deficiência**. Um intérprete pode marcar vários, de um grupo ou dos
 * dois; "Outros" de cada grupo abre um campo de texto no cadastro.
 */
enum TipoInterprete: string
{
    case Espanhol = 'espanhol';
    case Ingles = 'ingles';
    case Frances = 'frances';
    case Alemao = 'alemao';
    case Italiano = 'italiano';
    case LinguaOutra = 'lingua_outra';
    case Libras = 'libras';
    case LibrasTatil = 'libras_tatil';
    case GuiaInterprete = 'guia_interprete';
    case Audiodescricao = 'audiodescricao';
    case LedorTranscritor = 'ledor_transcritor';
    case Oralizacao = 'oralizacao';
    case PcdOutro = 'pcd_outro';

    public const GRUPO_LINGUA = 'lingua';

    public const GRUPO_PCD = 'pcd';

    public function label(): string
    {
        return match ($this) {
            self::Espanhol => 'Espanhol',
            self::Ingles => 'Inglês',
            self::Frances => 'Francês',
            self::Alemao => 'Alemão',
            self::Italiano => 'Italiano',
            self::LinguaOutra => 'Outra língua',
            self::Libras => 'Libras',
            self::LibrasTatil => 'Libras tátil',
            self::GuiaInterprete => 'Guia-intérprete (surdocegueira)',
            self::Audiodescricao => 'Audiodescrição',
            self::LedorTranscritor => 'Ledor e transcritor',
            self::Oralizacao => 'Oralização / leitura labial',
            self::PcdOutro => 'Outro atendimento',
        };
    }

    public function grupo(): string
    {
        return match ($this) {
            self::Espanhol, self::Ingles, self::Frances, self::Alemao,
            self::Italiano, self::LinguaOutra => self::GRUPO_LINGUA,
            default => self::GRUPO_PCD,
        };
    }

    /** "Outros" de cada grupo: pede o texto de qual. */
    public function ehOutro(): bool
    {
        return $this === self::LinguaOutra || $this === self::PcdOutro;
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /** @return list<array{value: string, label: string, grupo: string}> */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label(), 'grupo' => $t->grupo()],
            self::cases(),
        );
    }
}
