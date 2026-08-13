<?php

namespace App\Enums;

/**
 * Tipos de evento gravados na trilha de registros (painel do admin).
 *
 * - submissao: orientador submeteu a inscrição (irreversível para ele depois
 *   que a avaliação começa);
 * - cancelamento: submissão desfeita — o projeto voltou a rascunho;
 * - exclusao: projeto submetido excluído de vez pelo orientador (ou admin);
 * - troca_email: e-mail de acesso da conta alterado (guarda o "de → para").
 */
enum TipoRegistro: string
{
    case Submissao = 'submissao';
    case Cancelamento = 'cancelamento';
    case Exclusao = 'exclusao';
    case TrocaEmail = 'troca_email';

    public function label(): string
    {
        return match ($this) {
            self::Submissao => 'Submissão',
            self::Cancelamento => 'Cancelamento',
            self::Exclusao => 'Exclusão',
            self::TrocaEmail => 'Troca de e-mail',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label()],
            self::cases(),
        );
    }
}
