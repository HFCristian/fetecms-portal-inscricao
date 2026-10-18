<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A liberação de um avaliador para a avaliação presencial de uma edição
 * (Sprint 223): sem ela, quem confirmou o presencial não avalia nem recebe
 * designação. Vale para o evento inteiro; revogar é apagar a linha.
 */
class AvaliadorLiberacaoPresencial extends Model
{
    protected $table = 'avaliador_liberacoes_presenciais';

    protected $fillable = ['edicao_id', 'user_id', 'liberado_por'];

    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function liberadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberado_por');
    }
}
