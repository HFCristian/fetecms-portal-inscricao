<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O texto customizado de um e-mail automático. Só existe linha para o que o
 * admin editou — o padrão mora no enum App\Enums\ModeloEmail.
 */
class ModeloEmailTexto extends Model
{
    protected $table = 'modelos_email';

    protected $fillable = ['chave', 'assunto', 'corpo', 'user_id', 'autor_nome'];

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
