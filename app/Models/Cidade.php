<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cidade extends Model
{
    protected $table = 'cidades';

    protected $fillable = ['estado_id', 'nome', 'capital'];

    protected function casts(): array
    {
        return ['capital' => 'boolean'];
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }
}
