<?php

namespace App\Http\Requests\Avaliador;

/**
 * Envio final da avaliação: os três quesitos (escala Likert de 1 a 5) e a
 * conferência da área são obrigatórios — mais o quesito do projeto de
 * continuação, quando o projeto tem esse documento. Conferir a subárea é
 * opcional, mas quem a marcar como incorreta precisa sugerir a correta. A nota
 * final é calculada no service — o cliente não a envia.
 */
class ConcluirAvaliacaoRequest extends AvaliacaoRequest
{
    protected function obrigatorio(): bool
    {
        return true;
    }
}
