<?php

namespace App\Models;

use App\Enums\StatusRecomendacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um projeto finalista recomendado por um avaliador do comitê especial para
 * uma credencial ou prêmio (Sprint 193), com a decisão da organização.
 */
class RecomendacaoPremiacao extends Model
{
    protected $table = 'recomendacoes_premiacao';

    protected $fillable = [
        'credencial_id', 'projeto_id', 'avaliador_id', 'justificativa', 'status', 'decidida_por', 'decidida_em',
    ];

    protected $attributes = ['status' => 'pendente'];

    protected function casts(): array
    {
        return [
            'status' => StatusRecomendacao::class,
            'decidida_em' => 'datetime',
        ];
    }

    public function credencial(): BelongsTo
    {
        return $this->belongsTo(Credencial::class);
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliador_id');
    }

    public function decididaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidida_por');
    }
}
