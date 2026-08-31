<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O texto customizado de um e-mail automático. Só existe linha para o que o
 * admin editou — o padrão mora no enum App\Enums\ModeloEmail.
 *
 * `formato` distingue o corpo escrito no editor rico (`html`, sanitizado na
 * gravação) do texto puro de sempre (`texto`, o padrão).
 */
class ModeloEmailTexto extends Model
{
    protected $table = 'modelos_email';

    protected $fillable = ['chave', 'assunto', 'corpo', 'formato', 'user_id', 'autor_nome'];

    /** O corpo é HTML do editor (e não texto puro com parágrafos)? */
    public function ehHtml(): bool
    {
        return $this->formato === 'html';
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
