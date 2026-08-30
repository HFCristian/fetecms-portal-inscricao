<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O credenciamento de um projeto finalista no dia do evento: quem atendeu,
 * quando começou e quando terminou. A conferência item a item está em
 * `documentos`.
 *
 * `finalizado_em` é o que separa "em atendimento" de "credenciado".
 */
class Credenciamento extends Model
{
    protected $table = 'credenciamentos';

    protected $fillable = [
        'projeto_id', 'lista_final_id', 'credenciado_por',
        'iniciado_em', 'finalizado_em', 'observacao',
    ];

    protected function casts(): array
    {
        return [
            'iniciado_em' => 'datetime',
            'finalizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credenciado_por');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(CredenciamentoDocumento::class);
    }

    public function concluido(): bool
    {
        return $this->finalizado_em !== null;
    }
}
