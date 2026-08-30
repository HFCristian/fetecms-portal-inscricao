<?php

namespace App\Models;

use App\Enums\StatusDestinatario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um convite de feedback e o que aconteceu com ele. Mesmo desenho do
 * destinatário da mala direta: nome e e-mail desnormalizados para o relatório
 * dizer para quem foi mesmo que a pessoa troque de endereço depois.
 */
class FeedbackDestinatario extends Model
{
    protected $table = 'feedback_destinatarios';

    protected $fillable = ['feedback_id', 'user_id', 'nome', 'email', 'status', 'erro', 'enviado_em'];

    protected function casts(): array
    {
        return [
            'status' => StatusDestinatario::class,
            'enviado_em' => 'datetime',
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
}
