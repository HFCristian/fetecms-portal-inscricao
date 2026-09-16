<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um turno de trabalho de uma conta temporária (voluntário do evento).
 *
 * Fora dos turnos a conta não abre, mesmo dentro do prazo geral — é o que
 * distingue "tenho acesso neste fim de semana" de "trabalho sábado de manhã e
 * domingo à tarde".
 */
class ContaTemporariaTurno extends Model
{
    protected $table = 'conta_temporaria_turnos';

    protected $fillable = ['conta_temporaria_id', 'inicio', 'fim'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime'];
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaTemporaria::class, 'conta_temporaria_id');
    }

    /** O turno está acontecendo agora? */
    public function agora(): bool
    {
        return now()->betweenIncluded($this->inicio, $this->fim);
    }
}
