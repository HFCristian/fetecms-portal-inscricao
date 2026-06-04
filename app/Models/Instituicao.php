<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Instituicao extends Model
{
    protected $table = 'instituicoes';

    protected $fillable = ['nome', 'cidade_id', 'tipo'];

    public function cidade(): BelongsTo
    {
        return $this->belongsTo(Cidade::class);
    }
}
