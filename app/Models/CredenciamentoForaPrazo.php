<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Credenciamento fora do prazo aprovado para um projeto (Sprint 161). */
class CredenciamentoForaPrazo extends Model
{
    protected $table = 'credenciamentos_fora_prazo';

    protected $fillable = ['projeto_id', 'previsto_em', 'observacao', 'registrado_por'];

    protected function casts(): array
    {
        return ['previsto_em' => 'datetime'];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
