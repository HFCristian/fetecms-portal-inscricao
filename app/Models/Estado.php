<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estado extends Model
{
    protected $table = 'estados';

    protected $fillable = ['nome', 'uf'];

    public function cidades(): HasMany
    {
        return $this->hasMany(Cidade::class);
    }
}
