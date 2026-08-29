<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma imagem do corpo ou um anexo de uma mala direta. Enquanto o admin escreve,
 * o arquivo existe sem mala; o disparo é que o vincula.
 */
class MalaDiretaArquivo extends Model
{
    protected $table = 'mala_direta_arquivos';

    public const TIPO_IMAGEM = 'imagem';

    public const TIPO_ANEXO = 'anexo';

    /** Limites que a organização definiu para uma mensagem. */
    public const MAX_IMAGENS = 5;

    public const MAX_ANEXOS = 10;

    /** Tamanho máximo de cada arquivo, em kilobytes (o que a validação espera). */
    public const MAX_IMAGEM_KB = 10 * 1024;

    public const MAX_ANEXO_KB = 20 * 1024;

    protected $fillable = [
        'mala_direta_id', 'tipo', 'user_id', 'disk', 'path',
        'nome_original', 'mime', 'tamanho_bytes',
    ];

    protected function casts(): array
    {
        return ['tamanho_bytes' => 'integer'];
    }

    public function mala(): BelongsTo
    {
        return $this->belongsTo(MalaDireta::class, 'mala_direta_id');
    }

    /** Quantos arquivos deste tipo cabem numa mensagem. */
    public static function maximoDe(string $tipo): int
    {
        return $tipo === self::TIPO_IMAGEM ? self::MAX_IMAGENS : self::MAX_ANEXOS;
    }

    /** Tamanho máximo (KB) de um arquivo deste tipo. */
    public static function tamanhoMaximoDe(string $tipo): int
    {
        return $tipo === self::TIPO_IMAGEM ? self::MAX_IMAGEM_KB : self::MAX_ANEXO_KB;
    }
}
