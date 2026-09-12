<?php

namespace App\Enums;

use App\Services\ConfirmacaoCadastroService;

/**
 * Os e-mails automáticos cujo texto o admin edita em Comunicação → Modelos de
 * e-mail. Cada caso traz o texto de fábrica e as variáveis que aceita — o
 * formulário desenha os botões a partir daqui, então acrescentar uma variável
 * é acrescentar uma linha em variaveis().
 */
enum ModeloEmail: string
{
    case ConfirmacaoCadastro = 'confirmacao_cadastro';
    case ProjetoSubmetido = 'projeto_submetido';
    case FeedbackSolicitado = 'feedback_solicitado';
    case ProjetosDesignados = 'projetos_designados';
    case DesignacaoConcluida = 'designacao_concluida';

    /** Texto de fábrica do comprovante de submissão. */
    private const CORPO_PROJETO_SUBMETIDO = <<<'TXT'
        Olá, {{nome}}!

        Confirmamos que o projeto "{{projeto}}" foi submetido em {{data}}, às {{hora}}, na categoria {{categoria}}.

        Guarde este e-mail como comprovante. A partir daqui o projeto segue para a etapa de avaliação; para qualquer alteração, fale com a organização pelo suporte do portal.
        TXT;

    /** Texto de fábrica do convite de feedback. */
    private const CORPO_FEEDBACK = <<<'TXT'
        Olá, {{nome}}!

        A organização da XVI FETECMS gostaria de ouvir você: "{{titulo}}".

        {{descricao}}

        São {{perguntas}} pergunta(s) e as respostas são anônimas — ninguém consegue ligar o que você escrever ao seu nome.

        Para responder, entre no portal: o convite aparece assim que você acessa.
        TXT;

    /** Texto de fábrica do aviso de projetos designados ao avaliador. */
    private const CORPO_PROJETOS_DESIGNADOS = <<<'TXT'
        Olá, {{nome}}!

        A organização da XVI FETECMS designou {{quantidade}} projeto(s) para a sua avaliação:

        {{projetos}}

        Eles já estão na sua tela de avaliação, na lista "Designados pela organização". Entre no portal para começar.
        TXT;

    /** Texto de fábrica do resumo que volta para quem fez a designação. */
    private const CORPO_DESIGNACAO_CONCLUIDA = <<<'TXT'
        Olá, {{nome}}!

        A designação que você fez no portal terminou.

        {{resumo}}

        {{problemas}}
        TXT;

    public function label(): string
    {
        return match ($this) {
            self::ConfirmacaoCadastro => 'Confirmação de cadastro',
            self::ProjetoSubmetido => 'Projeto submetido',
            self::FeedbackSolicitado => 'Pedido de feedback',
            self::ProjetosDesignados => 'Projetos designados ao avaliador',
            self::DesignacaoConcluida => 'Designação concluída (para o admin)',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::ConfirmacaoCadastro => 'Vai para quem acabou de preencher o cadastro de orientador ou de avaliador, com o código de 6 dígitos que libera a conta.',
            self::ProjetoSubmetido => 'O comprovante que o orientador recebe assim que submete a inscrição, com o título, a data e a categoria do projeto.',
            self::FeedbackSolicitado => 'O convite que sai para cada pessoa alcançada por um pedido de feedback publicado em Comunicação → Feedback.',
            self::ProjetosDesignados => 'O aviso que o avaliador recebe quando o admin designa projetos a ele em Avaliação online → Designações, com a lista do que chegou.',
            self::DesignacaoConcluida => 'O resumo que volta para o administrador que fez a designação: quantas foram criadas e o que não pôde ser designado.',
        };
    }

    public function assuntoPadrao(): string
    {
        return match ($this) {
            self::ConfirmacaoCadastro => ConfirmacaoCadastroService::ASSUNTO_PADRAO,
            self::ProjetoSubmetido => 'Projeto submetido — XVI FETECMS',
            self::FeedbackSolicitado => '{{titulo}} — XVI FETECMS',
            self::ProjetosDesignados => 'Novos projetos para avaliar — XVI FETECMS',
            self::DesignacaoConcluida => 'Designação concluída — XVI FETECMS',
        };
    }

    public function corpoPadrao(): string
    {
        return match ($this) {
            self::ConfirmacaoCadastro => ConfirmacaoCadastroService::CORPO_PADRAO,
            self::ProjetoSubmetido => self::CORPO_PROJETO_SUBMETIDO,
            self::FeedbackSolicitado => self::CORPO_FEEDBACK,
            self::ProjetosDesignados => self::CORPO_PROJETOS_DESIGNADOS,
            self::DesignacaoConcluida => self::CORPO_DESIGNACAO_CONCLUIDA,
        };
    }

    /**
     * Variáveis aceitas no assunto e no corpo, com a explicação que aparece no
     * botão da tela.
     *
     * @return array<int, array{chave: string, descricao: string}>
     */
    public function variaveis(): array
    {
        return match ($this) {
            self::ProjetoSubmetido => [
                ['chave' => 'nome', 'descricao' => 'Primeiro nome do orientador'],
                ['chave' => 'nome_completo', 'descricao' => 'Nome completo do orientador'],
                ['chave' => 'email', 'descricao' => 'E-mail do orientador'],
                ['chave' => 'projeto', 'descricao' => 'Título do projeto submetido'],
                ['chave' => 'categoria', 'descricao' => 'Categoria da feira'],
                ['chave' => 'area', 'descricao' => 'Área do conhecimento'],
                ['chave' => 'data', 'descricao' => 'Data da submissão (dd/mm/aaaa)'],
                ['chave' => 'hora', 'descricao' => 'Hora da submissão (hh:mm)'],
            ],
            self::FeedbackSolicitado => [
                ['chave' => 'nome', 'descricao' => 'Primeiro nome de quem recebe'],
                ['chave' => 'nome_completo', 'descricao' => 'Nome completo'],
                ['chave' => 'email', 'descricao' => 'E-mail de quem recebe'],
                ['chave' => 'titulo', 'descricao' => 'Título do pedido de feedback'],
                ['chave' => 'descricao', 'descricao' => 'A descrição escrita no pedido'],
                ['chave' => 'perguntas', 'descricao' => 'Quantas perguntas o questionário tem'],
            ],
            self::ProjetosDesignados => [
                ['chave' => 'nome', 'descricao' => 'Primeiro nome do avaliador'],
                ['chave' => 'nome_completo', 'descricao' => 'Nome completo do avaliador'],
                ['chave' => 'email', 'descricao' => 'E-mail do avaliador'],
                ['chave' => 'quantidade', 'descricao' => 'Quantos projetos foram designados agora'],
                ['chave' => 'projetos', 'descricao' => 'A lista dos projetos designados, um por linha'],
            ],
            self::DesignacaoConcluida => [
                ['chave' => 'nome', 'descricao' => 'Primeiro nome do administrador'],
                ['chave' => 'nome_completo', 'descricao' => 'Nome completo do administrador'],
                ['chave' => 'email', 'descricao' => 'E-mail do administrador'],
                ['chave' => 'resumo', 'descricao' => 'Quantas designações foram criadas e para quantos avaliadores'],
                ['chave' => 'problemas', 'descricao' => 'O que não pôde ser designado — ou a confirmação de que deu tudo certo'],
            ],
            self::ConfirmacaoCadastro => [
                ['chave' => 'nome', 'descricao' => 'Primeiro nome de quem se cadastrou'],
                ['chave' => 'nome_completo', 'descricao' => 'Nome completo'],
                ['chave' => 'email', 'descricao' => 'E-mail que está sendo confirmado'],
                ['chave' => 'codigo', 'descricao' => 'O código de 6 dígitos'],
                ['chave' => 'validade', 'descricao' => 'Por quantos minutos o código vale'],
                ['chave' => 'papel', 'descricao' => 'Orientador ou Avaliador'],
            ],
        };
    }

    /** @return array<int, array<string, mixed>> */
    public static function catalogo(): array
    {
        return array_map(fn (self $m) => [
            'chave' => $m->value,
            'label' => $m->label(),
            'descricao' => $m->descricao(),
            'variaveis' => $m->variaveis(),
        ], self::cases());
    }
}
