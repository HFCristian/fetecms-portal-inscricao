<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aviso publicado pelo admin e mostrado como card para os orientadores
 * conectados. Só um fica ativo por vez.
 */
class Aviso extends Model
{
    protected $table = 'avisos';

    protected $fillable = ['titulo', 'mensagem', 'user_id', 'autor_nome', 'encerrado_em'];

    protected function casts(): array
    {
        return ['encerrado_em' => 'datetime'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function visualizacoes(): HasMany
    {
        return $this->hasMany(AvisoVisualizacao::class);
    }

    public function ativo(): bool
    {
        return $this->encerrado_em === null;
    }
}
