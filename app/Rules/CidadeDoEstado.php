<?php

namespace App\Rules;

use App\Models\Cidade;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Garante que a cidade (FK) realmente pertence ao estado (FK) informado — evita
 * combinações inconsistentes vindas de manipulação do formulário.
 */
class CidadeDoEstado implements ValidationRule
{
    public function __construct(private readonly mixed $estadoId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Sem um dos lados não há o que cruzar (campos são opcionais nesta fase).
        if (empty($value) || empty($this->estadoId)) {
            return;
        }

        $pertence = Cidade::where('id', $value)
            ->where('estado_id', $this->estadoId)
            ->exists();

        if (! $pertence) {
            $fail('A cidade selecionada não pertence ao estado informado.');
        }
    }
}
