<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Instituicao extends Model
{
    protected $table = 'instituicoes';

    protected $fillable = ['nome', 'cidade_id', 'tipo', 'codigo_inep', 'zona'];

    public function cidade(): BelongsTo
    {
        return $this->belongsTo(Cidade::class);
    }

    /**
     * Busca por nome tolerante à ordem: cada palavra do termo precisa aparecer no nome,
     * em qualquer posição. Assim "IFMS Dourados" casa "IFMS - Campus Dourados".
     */
    public function scopeBuscaNome(Builder $query, ?string $termo): Builder
    {
        foreach (preg_split('/\s+/', trim((string) $termo), -1, PREG_SPLIT_NO_EMPTY) as $palavra) {
            $query->whereRaw('LOWER(nome) LIKE ?', ['%'.mb_strtolower($palavra).'%']);
        }

        return $query;
    }
}
