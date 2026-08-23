<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de que um usuário viu (e talvez tenha fechado) um aviso. */
class AvisoVisualizacao extends Model
{
    protected $table = 'aviso_visualizacoes';

    protected $fillable = ['aviso_id', 'user_id', 'visto_em', 'fechado_em'];

    protected function casts(): array
    {
        return [
            'visto_em' => 'datetime',
            'fechado_em' => 'datetime',
        ];
    }

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
