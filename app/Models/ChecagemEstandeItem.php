<?php

namespace App\Models;

use App\Enums\SituacaoDocumento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um item conferido numa checagem de estande. Desde a Sprint 182 o item pode
 * ser de uma pessoa (`pessoa_tipo` = papel, `pessoa_id`) ou do projeto
 * (`pessoa_tipo` = projeto, `pessoa_id` = o próprio projeto).
 */
class ChecagemEstandeItem extends Model
{
    protected $table = 'checagem_estande_itens';

    protected $fillable = ['checagem_id', 'item_id', 'pessoa_tipo', 'pessoa_id', 'item_nome', 'situacao'];

    protected function casts(): array
    {
        return ['situacao' => SituacaoDocumento::class];
    }

    public function checagem(): BelongsTo
    {
        return $this->belongsTo(ChecagemEstande::class, 'checagem_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemConferencia::class, 'item_id');
    }
}
