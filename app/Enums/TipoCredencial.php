<?php

namespace App\Enums;

/**
 * O que uma linha de *Credenciais e Prêmios* é.
 *
 * As duas coisas se cadastram igual — nome, órgão, descrição, vagas — e se
 * anexam a projetos finalistas do mesmo jeito, mas não são a mesma coisa no
 * dia da cerimônia:
 *
 * - **credencial** é a vaga que vai embora com o projeto: a indicação a outra
 *   feira, a bolsa, o convite do parceiro. Tem um objeto físico a separar no
 *   balcão do cerimonial, e é por isso que o card de *credenciais a separar*
 *   conta **só** estas;
 * - **prêmio** é o reconhecimento que se anuncia no palco e não vira crachá
 *   para levar. Ele entra em quem é **premiado** — e portanto nas medalhas —
 *   sem inflar a pilha de credenciais da mesa.
 *
 * Quem existia antes desta distinção é credencial: era o único tipo que havia.
 */
enum TipoCredencial: string
{
    case Credencial = 'credencial';
    case Premio = 'premio';

    public function label(): string
    {
        return match ($this) {
            self::Credencial => 'Credencial',
            self::Premio => 'Prêmio',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Credencial => 'Credenciais',
            self::Premio => 'Prêmios',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Credencial => 'Vaga que o projeto leva: indicação a outra feira, bolsa, convite de parceiro. Conta no card de credenciais a separar.',
            self::Premio => 'Reconhecimento anunciado na cerimônia, sem credencial física a entregar.',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, array{value: string, label: string, plural: string, descricao: string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'plural' => $t->plural(),
            'descricao' => $t->descricao(),
        ], self::cases());
    }
}
