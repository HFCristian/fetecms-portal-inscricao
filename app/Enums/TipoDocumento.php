<?php

namespace App\Enums;

/**
 * Tipos de documento anexáveis ao projeto (cadastro4 §3/§4).
 */
enum TipoDocumento: string
{
    case PlanoPesquisa = 'plano_pesquisa';
    case ProjetoContinuacao = 'projeto_continuacao';
    case TermoEtica = 'termo_etica';
    case Anexo = 'anexo';

    public function label(): string
    {
        return match ($this) {
            self::PlanoPesquisa => 'Projeto de Pesquisa',
            self::ProjetoContinuacao => 'Projeto de Continuação',
            self::TermoEtica => 'Termo do Comitê de Ética',
            self::Anexo => 'Anexo',
        };
    }
}
