<?php

namespace App\Http\Requests\Avaliador;

/**
 * Rascunho da avaliação: nada é obrigatório — o avaliador salva o que já
 * preencheu e volta depois. O que vier ainda precisa ser válido (nota dentro da
 * escala Likert, comentário dentro do limite, sugestão existente no catálogo).
 */
class RascunhoAvaliacaoRequest extends AvaliacaoRequest
{
    protected function obrigatorio(): bool
    {
        return false;
    }
}
