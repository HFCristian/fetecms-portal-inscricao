<?php

namespace App\Enums;

/**
 * Tipos de documento anexáveis ao projeto (cadastro4 §3/§4).
 *
 * O **termo de responsabilidade** (Sprint 129) é de outra fase: ele não faz
 * parte da inscrição, e sim do evento — só os projetos **finalistas** o anexam,
 * depois da lista final publicada, e por isso ele tem tela e rotas próprias
 * (aba "Documentos" do orientador).
 */
enum TipoDocumento: string
{
    case PlanoPesquisa = 'plano_pesquisa';
    case ProjetoContinuacao = 'projeto_continuacao';
    case TermoEtica = 'termo_etica';
    case TermoResponsabilidade = 'termo_responsabilidade';
    case Anexo = 'anexo';

    public function label(): string
    {
        return match ($this) {
            self::PlanoPesquisa => 'Projeto de Pesquisa',
            self::ProjetoContinuacao => 'Projeto de Continuação',
            self::TermoEtica => 'Termo do Comitê de Ética',
            self::TermoResponsabilidade => 'Termo de Responsabilidade',
            self::Anexo => 'Anexo',
        };
    }
}
