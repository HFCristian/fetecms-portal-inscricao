<?php

namespace App\Models;

use App\Enums\StatusDestinatario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do snapshot de destinatários de uma mala direta: para quem o
 * e-mail foi (ou deveria ter ido), de qual público veio e o que aconteceu.
 * Nome, papel e projetos são cópias do momento do disparo.
 */
class MalaDiretaDestinatario extends Model
{
    protected $table = 'mala_direta_destinatarios';

    protected $fillable = [
        'mala_direta_id', 'user_id', 'email', 'nome', 'papel', 'origens',
        'projetos_total', 'projetos_titulos', 'status', 'erro', 'enviado_em',
    ];

    protected function casts(): array
    {
        return [
            'origens' => 'array',
            'projetos_titulos' => 'array',
            'status' => StatusDestinatario::class,
            'projetos_total' => 'integer',
            'enviado_em' => 'datetime',
        ];
    }

    public function mala(): BelongsTo
    {
        return $this->belongsTo(MalaDireta::class, 'mala_direta_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Só o primeiro nome — é assim que a mensagem cumprimenta o destinatário. */
    public function primeiroNome(): ?string
    {
        $nome = trim((string) $this->nome);

        return $nome === '' ? null : explode(' ', $nome)[0];
    }
}
