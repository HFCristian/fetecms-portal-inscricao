<?php

namespace App\Enums;

/**
 * Tipos de documento anexáveis ao projeto (cadastro4 §3/§4).
 *
 * O **termo de responsabilidade** (Sprint 129) é de outra fase: ele não faz
 * parte da inscrição, e sim do evento — só os projetos **finalistas** o anexam,
 * depois da lista final publicada, e por isso ele tem tela e rotas próprias
 * (aba "Documentos" do orientador).
 *
 * O **termo de uso de imagem** (Sprint 174) é da mesma fase e mora na mesma
 * aba, com uma diferença: o projeto pode mandar **vários** arquivos, e cada um
 * diz para quais estudantes vale (`projeto_documento_alunos`).
 */
enum TipoDocumento: string
{
    case PlanoPesquisa = 'plano_pesquisa';
    case ProjetoContinuacao = 'projeto_continuacao';
    case TermoEtica = 'termo_etica';
    case TermoResponsabilidade = 'termo_responsabilidade';
    case TermoUsoImagem = 'termo_uso_imagem';
    case Anexo = 'anexo';

    public function label(): string
    {
        return match ($this) {
            self::PlanoPesquisa => 'Projeto de Pesquisa',
            self::ProjetoContinuacao => 'Projeto de Continuação',
            self::TermoEtica => 'Termo do Comitê de Ética',
            self::TermoResponsabilidade => 'Termo de Responsabilidade',
            self::TermoUsoImagem => 'Termo de Uso de Imagem',
            self::Anexo => 'Anexo',
        };
    }

    /**
     * Documento do **evento**, e não da inscrição: só o finalista envia, pela
     * aba Documentos. Ele não entra pelo anexo da inscrição e fica fora da
     * leitura do avaliador — é documento com dado pessoal, não o que se avalia.
     */
    public function doEvento(): bool
    {
        return in_array($this, self::doEventoTodos(), true);
    }

    /** @return list<self> */
    public static function doEventoTodos(): array
    {
        return [self::TermoResponsabilidade, self::TermoUsoImagem];
    }
}
