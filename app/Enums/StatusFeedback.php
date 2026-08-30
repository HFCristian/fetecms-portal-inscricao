<?php

namespace App\Enums;

/** Em que pé está um pedido de feedback. */
enum StatusFeedback: string
{
    /** Criado; os convites estão na fila (ou saindo). */
    case Enviando = 'enviando';
    /** No ar: aparece para quem ainda não respondeu nem dispensou. */
    case Ativo = 'ativo';
    /** Fechado pelo admin: não aparece mais, mas os resultados continuam. */
    case Encerrado = 'encerrado';

    public function label(): string
    {
        return match ($this) {
            self::Enviando => 'Enviando convites',
            self::Ativo => 'No ar',
            self::Encerrado => 'Encerrado',
        };
    }
}
