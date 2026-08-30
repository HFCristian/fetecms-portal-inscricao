<?php

namespace App\Enums;

/**
 * O que se pede numa pergunta de feedback.
 *
 * `alternativa` mostra opções para escolher (escritas à mão ou vindas de um
 * modelo pronto); `dissertativa` abre um campo de texto com limite mínimo e
 * máximo, contado em palavras ou em caracteres.
 */
enum TipoPerguntaFeedback: string
{
    case Alternativa = 'alternativa';
    case Dissertativa = 'dissertativa';

    public function label(): string
    {
        return match ($this) {
            self::Alternativa => 'Alternativas',
            self::Dissertativa => 'Resposta dissertativa',
        };
    }
}
