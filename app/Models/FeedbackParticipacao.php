<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quem foi alcançado por um feedback e o que fez com o balão: viu, dispensou ou
 * respondeu.
 *
 * Não guarda conteúdo — é o que permite saber "quantos responderam" sem quebrar
 * o anonimato das respostas.
 */
class FeedbackParticipacao extends Model
{
    protected $table = 'feedback_participacoes';

    protected $fillable = ['feedback_id', 'user_id', 'visto_em', 'dispensado_em', 'respondido_em'];

    protected function casts(): array
    {
        return [
            'visto_em' => 'datetime',
            'dispensado_em' => 'datetime',
            'respondido_em' => 'datetime',
        ];
    }

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** O balão já saiu da frente desta pessoa? */
    public function encerrada(): bool
    {
        return $this->dispensado_em !== null || $this->respondido_em !== null;
    }
}
