<?php

namespace App\Models;

use App\Enums\GrupoCorrelato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $table = 'areas';

    protected $fillable = ['nome', 'grupo_correlato'];

    protected function casts(): array
    {
        return ['grupo_correlato' => GrupoCorrelato::class];
    }

    public function subareas(): HasMany
    {
        return $this->hasMany(Subarea::class);
    }
}
