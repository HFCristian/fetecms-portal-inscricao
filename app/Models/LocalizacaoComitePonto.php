<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um ponto do trajeto vivo (chega de 5 em 5 segundos). Só existe enquanto o
 * localizador está ligado: encerrar ou vencer a sessão apaga o trajeto.
 */
class LocalizacaoComitePonto extends Model
{
    protected $table = 'localizacao_comite_pontos';

    public $timestamps = false;

    protected $fillable = ['localizacao_comite_id', 'latitude', 'longitude', 'registrado_em'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'registrado_em' => 'datetime',
        ];
    }

    public function sessao(): BelongsTo
    {
        return $this->belongsTo(LocalizacaoComite::class, 'localizacao_comite_id');
    }
}
