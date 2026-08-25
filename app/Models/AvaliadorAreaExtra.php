<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Área (e, opcionalmente, subárea) que o ADMIN liberou para um avaliador além
 * da que ele escolheu no cadastro. Vale na distribuição automática e na
 * reposição da fila, com a mesma prioridade da área própria.
 */
class AvaliadorAreaExtra extends Model
{
    protected $table = 'avaliador_areas_extras';

    protected $fillable = ['avaliador_profile_id', 'area_id', 'subarea_id'];

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(AvaliadorProfile::class, 'avaliador_profile_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function subarea(): BelongsTo
    {
        return $this->belongsTo(Subarea::class);
    }
}
