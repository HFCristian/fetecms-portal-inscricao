<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha da verificação de disparidade: o projeto e as notas que ele tinha
 * no momento em que a lista foi gerada.
 */
class VerificacaoDisparidadeProjeto extends Model
{
    protected $table = 'verificacao_disparidade_projetos';

    protected $fillable = [
        'verificacao_id', 'projeto_id', 'titulo', 'area', 'categoria',
        'avaliacoes', 'nota_min', 'nota_max', 'amplitude', 'media',
    ];

    protected function casts(): array
    {
        return [
            'avaliacoes' => 'integer',
            'nota_min' => 'float',
            'nota_max' => 'float',
            'amplitude' => 'float',
            'media' => 'float',
        ];
    }

    public function verificacao(): BelongsTo
    {
        return $this->belongsTo(VerificacaoDisparidade::class, 'verificacao_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }
}
