<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A equipe foi embora antes do fim do turno, num dia do evento (Sprint 243). */
class SaidaAntecipada extends Model
{
    protected $table = 'saidas_antecipadas';

    protected $fillable = ['projeto_id', 'dia', 'saiu_em', 'observacao', 'registrado_por'];

    protected function casts(): array
    {
        // `dia` fica como texto (Y-m-d): o cast de data gravaria a hora junto e
        // a busca por dia deixaria de casar no SQLite.
        return ['saiu_em' => 'datetime'];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
