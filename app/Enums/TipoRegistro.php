<?php

namespace App\Enums;

/**
 * Tipos de evento gravados na trilha de registros (painel do admin), divididos
 * em seções — cada uma é uma tela.
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
 *
 * Seção "Projetos" (o admin corrigindo a inscrição de alguém): categoria, área,
 * subárea e link do vídeo. Aqui a justificativa é obrigatória e entra no
 * registro junto do "de → para".
 *
 * Seção "Rascunhos" (o admin terminando e enviando a inscrição de alguém depois
 * do prazo): cada campo que ele mexe no rascunho vira uma linha, e a submissão
 * fecha a sequência carregando a justificativa obrigatória.
 *
 * Seção "Lista final" (a lista oficial da feira): a oficialização e cada
 * projeto acrescentado ou retirado depois, sempre com justificativa — é a
 * composição de quem sobe ao evento, então toda mexida fica registrada.
 *
 * Seção "Credenciamento" (o balcão do evento): quem credenciou cada projeto,
 * quando, e o que ficou ausente na conferência dos documentos.
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
    case AvaliacaoMaxAvaliador = 'avaliacao_max_avaliador';
    case AvaliacaoMaxProjeto = 'avaliacao_max_projeto';
    case AvaliacaoLimitesCategoria = 'avaliacao_limites_categoria';
    case AvaliacaoRegraDistribuicao = 'avaliacao_regra_distribuicao';
    case AvaliacaoDesignacaoAoCadastrar = 'avaliacao_designacao_ao_cadastrar';
    case AvaliacaoPisoFila = 'avaliacao_piso_fila';
    case AvaliacaoDesignacaoRetirada = 'avaliacao_designacao_retirada';
    case AvaliacaoAjustesInicio = 'avaliacao_ajustes_inicio';
    case AvaliacaoAjustesFim = 'avaliacao_ajustes_fim';
    case ProjetoCategoria = 'projeto_categoria';
    case ProjetoArea = 'projeto_area';
    case ProjetoSubarea = 'projeto_subarea';
    case ProjetoVideo = 'projeto_video';
    case RascunhoAlteracao = 'rascunho_alteracao';
    case RascunhoSubmissao = 'rascunho_submissao';
    case ListaFinalOficializada = 'lista_final_oficializada';
    case ListaFinalProjetoAdicionado = 'lista_final_projeto_adicionado';
    case ListaFinalProjetoRemovido = 'lista_final_projeto_removido';
    case CredenciamentoRealizado = 'credenciamento_realizado';

    /** Seções da tela de Registros. */
    public const SECAO_INSCRICOES = 'inscricoes';

    public const SECAO_AVALIACAO = 'avaliacao';

    public const SECAO_PROJETOS = 'projetos';

    public const SECAO_RASCUNHOS = 'rascunhos';

    public const SECAO_LISTA_FINAL = 'lista_final';

    public const SECAO_CREDENCIAMENTO = 'credenciamento';

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
            self::AvaliacaoMaxAvaliador => 'Máximo por avaliador',
            self::AvaliacaoMaxProjeto => 'Máximo por projeto',
            self::AvaliacaoLimitesCategoria => 'Limites por categoria',
            self::AvaliacaoRegraDistribuicao => 'Regra da distribuição',
            self::AvaliacaoDesignacaoAoCadastrar => 'Designação ao cadastrar',
            self::AvaliacaoPisoFila => 'Piso da fila do avaliador',
            self::AvaliacaoDesignacaoRetirada => 'Designação retirada',
            self::AvaliacaoAjustesInicio => 'Início dos ajustes',
            self::AvaliacaoAjustesFim => 'Fim dos ajustes',
            self::ProjetoCategoria => 'Categoria do projeto',
            self::ProjetoArea => 'Área do projeto',
            self::ProjetoSubarea => 'Subárea do projeto',
            self::ProjetoVideo => 'Vídeo do projeto',
            self::RascunhoAlteracao => 'Alteração no rascunho',
            self::RascunhoSubmissao => 'Submissão do rascunho',
            self::ListaFinalOficializada => 'Lista final oficializada',
            self::ListaFinalProjetoAdicionado => 'Projeto incluído na lista',
            self::ListaFinalProjetoRemovido => 'Projeto retirado da lista',
            self::CredenciamentoRealizado => 'Credenciamento realizado',
        };
    }

    /** A qual seção da tela de Registros este tipo pertence. */
    public function secao(): string
    {
        return match ($this) {
            self::Submissao, self::Cancelamento, self::Exclusao, self::TrocaEmail => self::SECAO_INSCRICOES,
            self::ProjetoCategoria, self::ProjetoArea, self::ProjetoSubarea, self::ProjetoVideo => self::SECAO_PROJETOS,
            self::RascunhoAlteracao, self::RascunhoSubmissao => self::SECAO_RASCUNHOS,
            self::ListaFinalOficializada, self::ListaFinalProjetoAdicionado,
            self::ListaFinalProjetoRemovido => self::SECAO_LISTA_FINAL,
            self::CredenciamentoRealizado => self::SECAO_CREDENCIAMENTO,
            default => self::SECAO_AVALIACAO,
        };
    }

    /** @return list<string> */
    public static function secoes(): array
    {
        return [
            self::SECAO_INSCRICOES, self::SECAO_AVALIACAO, self::SECAO_PROJETOS,
            self::SECAO_RASCUNHOS, self::SECAO_LISTA_FINAL, self::SECAO_CREDENCIAMENTO,
        ];
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
