<?php

namespace App\Models;

use App\Enums\Turno;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A chegada do intérprete numa ocorrência do evento (dia × turno). */
class InterpreteCheckin extends Model
{
    protected $fillable = ['interprete_id', 'dia', 'turno', 'registrado_por'];

    protected function casts(): array
    {
        return [
            'dia' => 'date',
            'turno' => Turno::class,
        ];
    }

    public function interprete(): BelongsTo
    {
        return $this->belongsTo(Interprete::class);
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
