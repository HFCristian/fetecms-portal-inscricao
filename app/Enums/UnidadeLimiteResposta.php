<?php

namespace App\Enums;

/** Em que unidade o limite de uma resposta dissertativa é contado. */
enum UnidadeLimiteResposta: string
{
    case Palavras = 'palavras';
    case Caracteres = 'caracteres';

    public function label(): string
    {
        return match ($this) {
            self::Palavras => 'palavras',
            self::Caracteres => 'caracteres',
        };
    }

    /** Quanto uma resposta "mede" nesta unidade. */
    public function medir(string $texto): int
    {
        $limpo = trim($texto);

        if ($limpo === '') {
            return 0;
        }

        return $this === self::Caracteres
            ? mb_strlen($limpo)
            : count(preg_split('/\s+/u', $limpo) ?: []);
    }
}
