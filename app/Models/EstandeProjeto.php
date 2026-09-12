<?php

namespace App\Models;

use App\Enums\Turno;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O estande de um projeto (Mapa do Evento → Estandes dos Projetos).
 *
 * O número é do **par turno + estande**: o mesmo 042 recebe um projeto de manhã
 * e outro à tarde. `manual` marca o que o admin trocou depois da geração —
 * trocar dois projetos de lugar não pede justificativa, mas fica registrado.
 */
class EstandeProjeto extends Model
{
    protected $table = 'estandes_projetos';

    protected $fillable = ['edicao_id', 'projeto_id', 'turno', 'numero', 'regra', 'manual'];

    protected function casts(): array
    {
        return [
            'turno' => Turno::class,
            'numero' => 'integer',
            'manual' => 'boolean',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }
}
