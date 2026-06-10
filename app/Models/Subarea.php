<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subarea extends Model
{
    protected $table = 'subareas';

    protected $fillable = ['area_id', 'nome'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
