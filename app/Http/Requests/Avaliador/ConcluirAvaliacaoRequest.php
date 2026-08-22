<?php

namespace App\Http\Requests\Avaliador;

/**
 * Envio final da avaliação: todas as perguntas pontuadas da rubrica e a
 * conferência da área são obrigatórias. Conferir a subárea é opcional, mas
 * quem a marcar como incorreta precisa sugerir a correta; as recomendações
 * (vídeo e projeto) também são opcionais. A nota final é calculada no service
 * — o cliente não a envia.
 */
class ConcluirAvaliacaoRequest extends AvaliacaoRequest
{
    protected function obrigatorio(): bool
    {
        return true;
    }
}
