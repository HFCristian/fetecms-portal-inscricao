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
 * `finalizado_em` é o que separa o **rascunho** do "credenciado" — e um
 * projeto credenciado ainda pode ter **pendências**: quem faltou ao balcão e os
 * kits que ninguém levou. As duas coisas estão em `pessoas`.
 */
class Credenciamento extends Model
{
    protected $table = 'credenciamentos';

    protected $fillable = [
        'projeto_id', 'lista_final_id', 'credenciado_por', 'iniciado_por',
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

    /** Quem abriu o atendimento — o dono do rascunho enquanto ele não fecha. */
    public function iniciador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(CredenciamentoDocumento::class);
    }

    /** Presença e retirada de kit, uma linha por pessoa do projeto. */
    public function pessoas(): HasMany
    {
        return $this->hasMany(CredenciamentoPessoa::class);
    }

    public function concluido(): bool
    {
        return $this->finalizado_em !== null;
    }

    /** Atendimento aberto: começou e ainda não fechou. */
    public function emRascunho(): bool
    {
        return $this->finalizado_em === null;
    }
}
