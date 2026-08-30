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

    public function label(): string
    {
        return match ($this) {
            self::ConfirmacaoCadastro => 'Confirmação de cadastro',
            self::ProjetoSubmetido => 'Projeto submetido',
            self::FeedbackSolicitado => 'Pedido de feedback',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::ConfirmacaoCadastro => 'Vai para quem acabou de preencher o cadastro de orientador ou de avaliador, com o código de 6 dígitos que libera a conta.',
            self::ProjetoSubmetido => 'O comprovante que o orientador recebe assim que submete a inscrição, com o título, a data e a categoria do projeto.',
            self::FeedbackSolicitado => 'O convite que sai para cada pessoa alcançada por um pedido de feedback publicado em Comunicação → Feedback.',
        };
    }

    public function assuntoPadrao(): string
    {
        return match ($this) {
            self::ConfirmacaoCadastro => ConfirmacaoCadastroService::ASSUNTO_PADRAO,
            self::ProjetoSubmetido => 'Projeto submetido — XVI FETECMS',
            self::FeedbackSolicitado => '{{titulo}} — XVI FETECMS',
        };
    }

    public function corpoPadrao(): string
    {
        return match ($this) {
            self::ConfirmacaoCadastro => ConfirmacaoCadastroService::CORPO_PADRAO,
            self::ProjetoSubmetido => self::CORPO_PROJETO_SUBMETIDO,
            self::FeedbackSolicitado => self::CORPO_FEEDBACK,
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
