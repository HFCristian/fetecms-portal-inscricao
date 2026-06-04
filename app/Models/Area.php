<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $table = 'areas';

    protected $fillable = ['nome'];

    public function subareas(): HasMany
    {
        return $this->hasMany(Subarea::class);
    }
}
