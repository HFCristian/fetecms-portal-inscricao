<?php

namespace App\Enums;

/**
 * Situação de uma avaliação de projeto (E7 — distribuição/avaliação online).
 * O algoritmo de distribuição ainda será implementado; este enum e a tabela
 * `avaliacoes` já deixam o terreno pronto.
 *
 * - designada: projeto atribuído ao avaliador, ainda não iniciado;
 * - em_andamento: avaliação iniciada (no máximo 1 por avaliador ao mesmo tempo);
 * - concluida: nota lançada.
 */
enum StatusAvaliacao: string
{
    case Designada = 'designada';
    case EmAndamento = 'em_andamento';
    case Concluida = 'concluida';

    public function label(): string
    {
        return match ($this) {
            self::Designada => 'Designada',
            self::EmAndamento => 'Em andamento',
            self::Concluida => 'Concluída',
        };
    }

    /** Nº máximo de avaliações que cada avaliador realiza (regra do edital). */
    public const MAX_POR_AVALIADOR = 3;
}
