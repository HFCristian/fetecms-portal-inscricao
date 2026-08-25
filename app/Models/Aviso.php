<?php

namespace App\Models;

use App\Enums\PublicoMala;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aviso publicado pelo admin e mostrado como card para quem está nos públicos
 * escolhidos. Vários avisos podem estar no ar ao mesmo tempo, um por público —
 * cada pessoa vê no máximo um card: o mais recente que a alcança.
 */
class Aviso extends Model
{
    protected $table = 'avisos';

    protected $fillable = ['titulo', 'mensagem', 'user_id', 'autor_nome', 'encerrado_em', 'publicos', 'expira_em'];

    protected function casts(): array
    {
        return [
            'encerrado_em' => 'datetime',
            'expira_em' => 'datetime',
            'publicos' => 'array',
        ];
    }

    /**
     * Avisos no ar: não encerrados pelo admin e ainda dentro da validade.
     * Vários podem coexistir — cada um com o seu público.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVigente(Builder $query): void
    {
        $query->whereNull('encerrado_em')
            ->where(fn (Builder $q) => $q->whereNull('expira_em')->orWhere('expira_em', '>', now()));
    }

    /** A data de expiração já passou? */
    public function expirado(): bool
    {
        return $this->expira_em !== null && now()->greaterThan($this->expira_em);
    }

    /** Públicos-alvo já convertidos para o enum. */
    public function publicosAlvo(): array
    {
        return array_values(array_filter(array_map(
            fn ($valor) => PublicoMala::tryFrom((string) $valor),
            $this->publicos ?? [],
        )));
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function visualizacoes(): HasMany
    {
        return $this->hasMany(AvisoVisualizacao::class);
    }

    /** Está no ar agora (nem encerrado pelo admin, nem expirado)? */
    public function ativo(): bool
    {
        return $this->encerrado_em === null && ! $this->expirado();
    }
}
