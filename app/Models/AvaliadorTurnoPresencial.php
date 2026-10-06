<?php

namespace App\Models;

use App\Enums\Turno;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ativação de um avaliador num turno de um dia da feira (Sprint 169).
 *
 * Ele chega ao evento, passa na cabine da avaliação e a organização o ativa
 * para o turno — ou pré-ativa os seguintes que ele escolher. Sem ativação ele
 * não avalia naquele turno e a distribuição não o alcança.
 */
class AvaliadorTurnoPresencial extends Model
{
    protected $table = 'avaliador_turnos_presenciais';

    protected $fillable = ['edicao_id', 'user_id', 'dia', 'turno', 'ativado_por'];

    protected function casts(): array
    {
        return [
            'dia' => 'date:Y-m-d',
            'turno' => Turno::class,
        ];
    }

    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** "2026-10-20|A" — a chave da ocorrência, a mesma da agenda. */
    public function chave(): string
    {
        return $this->dia->toDateString().'|'.$this->turno->value;
    }
}
