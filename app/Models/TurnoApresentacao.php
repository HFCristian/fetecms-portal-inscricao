<?php

namespace App\Models;

use App\Enums\Turno;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O turno em que um projeto apresenta (Mapa do Evento → Turnos de
 * Apresentação).
 *
 * Uma linha por projeto e por edição: cada projeto apresenta **uma vez só**, e é
 * isso que permite dois projetos no mesmo estande — um de manhã, outro à tarde.
 *
 * `regra`/`origem` dizem por que ele está ali (a regra que o alcançou e o nome
 * da lista, quando houve uma); `manual` marca a troca feita pelo admin depois da
 * geração — ela não exige justificativa, mas fica registrada.
 */
class TurnoApresentacao extends Model
{
    protected $table = 'turnos_apresentacao';

    protected $fillable = ['edicao_id', 'projeto_id', 'turno', 'regra', 'origem', 'manual'];

    protected function casts(): array
    {
        return [
            'turno' => Turno::class,
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
