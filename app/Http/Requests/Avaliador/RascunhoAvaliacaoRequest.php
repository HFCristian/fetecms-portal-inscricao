<?php

namespace App\Http\Requests\Avaliador;

/**
 * Rascunho da avaliação: nada é obrigatório — o avaliador salva o que já
 * respondeu e volta depois. O que vier ainda precisa ser válido (resposta
 * dentro da escala, texto dentro do limite, sugestão existente no catálogo).
 */
class RascunhoAvaliacaoRequest extends AvaliacaoRequest
{
    protected function obrigatorio(): bool
    {
        return false;
    }
}
