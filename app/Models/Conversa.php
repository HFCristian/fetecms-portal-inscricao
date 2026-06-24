<?php

namespace App\Models;

use App\Enums\StatusConversa;
use Database\Factories\ConversaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Conversa (thread) do chat de suporte. Há no máximo uma por usuário; ela é
 * reaberta quando o usuário envia uma nova mensagem após ser respondida/arquivada.
 */
class Conversa extends Model
{
    /** @use HasFactory<ConversaFactory> */
    use HasFactory;

    protected $table = 'conversas';

    protected $fillable = ['user_id', 'status', 'ultima_mensagem_em'];

    protected function casts(): array
    {
        return [
            'status' => StatusConversa::class,
            'ultima_mensagem_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(Mensagem::class)->orderBy('created_at');
    }

    /** Última mensagem da conversa (para preview no inbox do admin). */
    public function ultimaMensagem(): HasOne
    {
        return $this->hasOne(Mensagem::class)->latestOfMany();
    }
}
