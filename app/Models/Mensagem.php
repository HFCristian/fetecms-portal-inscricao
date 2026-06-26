<?php

namespace App\Models;

use Database\Factories\MensagemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem dentro de uma conversa de suporte. `autor` é 'usuario' (orientador/
 * avaliador) ou 'suporte' (admin). `autor_user_id` guarda o admin que respondeu.
 */
class Mensagem extends Model
{
    /** @use HasFactory<MensagemFactory> */
    use HasFactory;

    public const AUTOR_USUARIO = 'usuario';

    public const AUTOR_SUPORTE = 'suporte';

    protected $table = 'mensagens';

    protected $fillable = ['conversa_id', 'autor', 'autor_user_id', 'corpo'];

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(Conversa::class);
    }

    public function autorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_user_id');
    }
}
