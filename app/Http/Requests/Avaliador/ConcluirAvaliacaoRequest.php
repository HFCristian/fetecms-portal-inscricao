<?php

namespace App\Http\Requests\Avaliador;

/**
 * Envio final da avaliação: os três quesitos (0 a 10) e a conferência da área
 * são obrigatórios; conferir a subárea é opcional, mas quem a marcar como
 * incorreta precisa sugerir a correta. A nota final (soma) é calculada no
 * service — o cliente não a envia.
 */
class ConcluirAvaliacaoRequest extends AvaliacaoRequest
{
    protected function obrigatorio(): bool
    {
        return true;
    }
}
