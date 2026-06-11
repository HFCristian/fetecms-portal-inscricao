<?php

namespace App\Rules;

use App\Models\Subarea;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Garante que a subárea (FK) pertence à área (FK) informada — evita combinações
 * inconsistentes vindas de manipulação do formulário. Subárea é opcional, então
 * só valida quando ambos os lados estão presentes.
 */
class SubareaDaArea implements ValidationRule
{
    public function __construct(private readonly mixed $areaId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value) || empty($this->areaId)) {
            return;
        }

        $pertence = Subarea::where('id', $value)
            ->where('area_id', $this->areaId)
            ->exists();

        if (! $pertence) {
            $fail('A subárea selecionada não pertence à área informada.');
        }
    }
}
