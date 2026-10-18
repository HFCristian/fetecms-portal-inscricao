<?php

namespace App\Enums;

use App\Support\JanelaTurnos;

/**
 * A vaga que uma avaliação presencial ocupa no projeto (Sprint 222).
 *
 * Das avaliações que o projeto recebe no estande, uma parte é de avaliadores
 * comuns e outra da **comissão especial** (padrão: 2 + 1), e a da comissão
 * pesa mais na média (padrão: peso 2) — os números são da edição
 * ({@see JanelaTurnos}). O avaliador da comissão ocupa a vaga
 * dela; com ela já tomada, entra numa vaga comum e pesa como os outros.
 *
 * A vaga é gravada na avaliação, e não deduzida de quem avaliou: tirar alguém
 * da comissão depois não pode mudar o peso do que ele já entregou.
 */
enum VagaPresencial: string
{
    case Comum = 'comum';
    case Comite = 'comite';

    public function label(): string
    {
        return match ($this) {
            self::Comum => 'Vaga comum',
            self::Comite => 'Comissão especial',
        };
    }
}
