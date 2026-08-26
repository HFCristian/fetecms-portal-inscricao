<?php

namespace App\Enums;

/**
 * Tipos de evento gravados na trilha de registros (painel do admin), divididos
 * em duas seções — cada uma é uma tela.
 *
 * Seção "Inscrições" (o que acontece com os projetos e as contas):
 * - submissao: orientador submeteu a inscrição (irreversível para ele depois
 *   que a avaliação começa);
 * - cancelamento: submissão desfeita — o projeto voltou a rascunho;
 * - exclusao: projeto submetido excluído de vez pelo orientador (ou admin);
 * - troca_email: e-mail de acesso da conta alterado (guarda o "de → para").
 *
 * Seção "Avaliação Online" (parametrização do período pelo admin): cada
 * mudança guarda o valor anterior e o novo.
 */
enum TipoRegistro: string
{
    case Submissao = 'submissao';
    case Cancelamento = 'cancelamento';
    case Exclusao = 'exclusao';
    case TrocaEmail = 'troca_email';
    case AvaliacaoLiberacao = 'avaliacao_liberacao';
    case AvaliacaoEncerramento = 'avaliacao_encerramento';
    case AvaliacaoMinAvaliador = 'avaliacao_min_avaliador';
    case AvaliacaoMinProjeto = 'avaliacao_min_projeto';
    case AvaliacaoRegraDistribuicao = 'avaliacao_regra_distribuicao';
    case AvaliacaoDesignacaoAoCadastrar = 'avaliacao_designacao_ao_cadastrar';

    /** Seções da tela de Registros. */
    public const SECAO_INSCRICOES = 'inscricoes';

    public const SECAO_AVALIACAO = 'avaliacao';

    public function label(): string
    {
        return match ($this) {
            self::Submissao => 'Submissão',
            self::Cancelamento => 'Cancelamento',
            self::Exclusao => 'Exclusão',
            self::TrocaEmail => 'Troca de e-mail',
            self::AvaliacaoLiberacao => 'Início da avaliação',
            self::AvaliacaoEncerramento => 'Fim da avaliação',
            self::AvaliacaoMinAvaliador => 'Mínimo por avaliador',
            self::AvaliacaoMinProjeto => 'Mínimo por projeto',
            self::AvaliacaoRegraDistribuicao => 'Regra da distribuição',
            self::AvaliacaoDesignacaoAoCadastrar => 'Designação ao cadastrar',
        };
    }

    /** A qual seção da tela de Registros este tipo pertence. */
    public function secao(): string
    {
        return match ($this) {
            self::Submissao, self::Cancelamento, self::Exclusao, self::TrocaEmail => self::SECAO_INSCRICOES,
            default => self::SECAO_AVALIACAO,
        };
    }

    /** @return list<string> */
    public static function secoes(): array
    {
        return [self::SECAO_INSCRICOES, self::SECAO_AVALIACAO];
    }

    /**
     * Tipos de uma seção (ou todos, sem seção).
     *
     * @return list<self>
     */
    public static function daSecao(?string $secao = null): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $t) => $secao === null || $t->secao() === $secao,
        ));
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opcoes(?string $secao = null): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label()],
            self::daSecao($secao),
        );
    }
}
