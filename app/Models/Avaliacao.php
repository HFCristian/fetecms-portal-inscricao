<?php

namespace App\Models;

use App\Enums\StatusAvaliacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avaliação de um projeto submetido por um avaliador (E7). A nota sai de uma
 * rubrica de três quesitos (vídeo de apresentação, resumo e projeto de
 * pesquisa), cada um de 0 a 10 e com comentários opcionais; `nota` guarda a
 * soma dos três (0 a 30).
 */
class Avaliacao extends Model
{
    /** Nota máxima de cada quesito da rubrica. */
    public const NOTA_MAXIMA_QUESITO = 10;

    /** Quesitos da rubrica, na ordem em que aparecem para o avaliador. */
    public const QUESITOS = ['video', 'resumo', 'pesquisa'];

    protected $table = 'avaliacoes';

    protected $fillable = [
        'projeto_id', 'avaliador_id', 'status', 'nota',
        'nota_video', 'comentario_video',
        'nota_resumo', 'comentario_resumo',
        'nota_pesquisa', 'comentario_pesquisa',
    ];

    /** Nota máxima da avaliação: a soma dos quesitos (3 × 10 = 30). */
    public static function notaMaxima(): int
    {
        return count(self::QUESITOS) * self::NOTA_MAXIMA_QUESITO;
    }

    /** Soma dos quesitos preenchidos — o valor gravado em `nota` ao concluir. */
    public function somaDosQuesitos(): int
    {
        return array_sum(array_map(
            fn (string $q) => (int) $this->{"nota_{$q}"},
            self::QUESITOS,
        ));
    }

    protected function casts(): array
    {
        return [
            'status' => StatusAvaliacao::class,
            'nota' => 'integer',
            'nota_video' => 'integer',
            'nota_resumo' => 'integer',
            'nota_pesquisa' => 'integer',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliador_id');
    }
}
