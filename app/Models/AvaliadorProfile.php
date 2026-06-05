<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvaliadorProfile extends Model
{
    /** @use HasFactory<\Database\Factories\AvaliadorProfileFactory> */
    use HasFactory;

    protected $fillable = ['cpf', 'titulacao', 'area_id', 'subarea_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function subarea(): BelongsTo
    {
        return $this->belongsTo(Subarea::class);
    }
}
