<?php

namespace App\Models;

use App\Enums\StatusAvaliacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avaliação de um projeto submetido por um avaliador (E7). O preenchimento
 * (distribuição/nota) virá com o algoritmo de avaliação; o model já existe.
 */
class Avaliacao extends Model
{
    protected $table = 'avaliacoes';

    protected $fillable = ['projeto_id', 'avaliador_id', 'status', 'nota'];

    protected function casts(): array
    {
        return [
            'status' => StatusAvaliacao::class,
            'nota' => 'integer',
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
