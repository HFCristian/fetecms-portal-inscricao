<?php

namespace App\Models;

use App\Enums\TipoRegistro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um evento da trilha de auditoria (submissão, cancelamento, exclusão e troca
 * de e-mail). Os campos de autor/projeto são cópias do momento do evento — o
 * registro tem que sobreviver à exclusão do projeto e à troca de e-mail.
 */
class RegistroAtividade extends Model
{
    protected $table = 'registros_atividade';

    protected $fillable = [
        'tipo', 'user_id', 'autor_email', 'autor_nome', 'autor_role',
        'projeto_id', 'projeto_titulo', 'projeto_categoria',
        'dono_email', 'dono_nome', 'detalhes',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoRegistro::class,
            'detalhes' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class)->withTrashed();
    }

    /** O autor agiu sobre a inscrição de outra pessoa (admin agindo pelo orientador)? */
    public function porTerceiro(): bool
    {
        return $this->dono_email !== null && $this->dono_email !== $this->autor_email;
    }
}
