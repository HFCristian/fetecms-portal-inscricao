<?php

namespace App\Enums;

/**
 * Como cada documento foi conferido no balcão do credenciamento.
 *
 * "Não necessário" existe porque parte da lista não se aplica a todo mundo (a
 * autorização de menor de um aluno maior de idade, por exemplo) — e marcar isso
 * é diferente de deixar em branco: registra que alguém olhou e decidiu.
 */
enum SituacaoDocumento: string
{
    case Presente = 'presente';
    case Ausente = 'ausente';
    case NaoNecessario = 'nao_necessario';

    public function label(): string
    {
        return match ($this) {
            self::Presente => 'Presente',
            self::Ausente => 'Ausente',
            self::NaoNecessario => 'Não necessário',
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
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
