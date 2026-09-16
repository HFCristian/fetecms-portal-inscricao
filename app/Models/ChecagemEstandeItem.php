<?php

namespace App\Models;

use App\Enums\SituacaoDocumento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um item conferido numa checagem de estande. */
class ChecagemEstandeItem extends Model
{
    protected $table = 'checagem_estande_itens';

    protected $fillable = ['checagem_id', 'item_id', 'item_nome', 'situacao'];

    protected function casts(): array
    {
        return ['situacao' => SituacaoDocumento::class];
    }

    public function checagem(): BelongsTo
    {
        return $this->belongsTo(ChecagemEstande::class, 'checagem_id');
    }
}
