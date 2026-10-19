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
 *
 * `primeiro_finalizado_em` (Sprint 245) guarda a **primeira** conclusão:
 * regravar a ficha depois move `finalizado_em`, e o mapa do evento precisa
 * saber se a equipe já tinha passado pelo balcão num dia anterior. Quem cuida
 * dele é o próprio model, ao salvar — todo caminho que conclui (balcão,
 * cadastro manual, ensaio) passa por aqui.
 */
class Credenciamento extends Model
{
    protected $table = 'credenciamentos';

    protected $fillable = [
        'projeto_id', 'lista_final_id', 'credenciado_por', 'iniciado_por',
        'iniciado_em', 'finalizado_em', 'primeiro_finalizado_em', 'observacao',
    ];

    protected static function booted(): void
    {
        static::saving(function (Credenciamento $c) {
            if ($c->finalizado_em === null) {
                return;
            }

            // A menor das duas: regravar mais tarde não muda a primeira vez,
            // mas corrigir a hora para antes (lançamento retroativo) corrige.
            if ($c->primeiro_finalizado_em === null || $c->finalizado_em->lessThan($c->primeiro_finalizado_em)) {
                $c->primeiro_finalizado_em = $c->finalizado_em;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'iniciado_em' => 'datetime',
            'finalizado_em' => 'datetime',
            'primeiro_finalizado_em' => 'datetime',
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
