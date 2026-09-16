<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A checagem presencial do estande de um projeto finalista: quem conferiu,
 * quando, e o que encontrou item a item.
 */
class ChecagemEstande extends Model
{
    protected $table = 'checagens_estande';

    protected $fillable = [
        'projeto_id', 'lista_final_id', 'verificado_por', 'verificado_em', 'observacao', 'demo',
    ];

    protected function casts(): array
    {
        return [
            'verificado_em' => 'datetime',
            'demo' => 'boolean',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificado_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ChecagemEstandeItem::class, 'checagem_id');
    }
}
