<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma resposta, **sem dono**. O `envio` agrupa as respostas de um mesmo
 * preenchimento (para o admin ler um questionário inteiro de uma pessoa sem
 * saber quem é) e não leva a usuário nenhum.
 */
class FeedbackResposta extends Model
{
    protected $table = 'feedback_respostas';

    protected $fillable = ['feedback_id', 'pergunta_id', 'envio', 'valor'];

    public function pergunta(): BelongsTo
    {
        return $this->belongsTo(FeedbackPergunta::class, 'pergunta_id');
    }
}
