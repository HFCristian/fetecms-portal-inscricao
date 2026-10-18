<?php

namespace App\Models;

use App\Enums\StatusPedidoPermissao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O pedido de um avaliador da comissão especial para avaliar no estande um
 * projeto fora das designações dele — só existe com a regra "avaliar apenas
 * projetos designados" ligada na edição (Sprint 234).
 */
class PedidoAvaliacaoComite extends Model
{
    protected $table = 'pedidos_avaliacao_comite';

    protected $fillable = [
        'edicao_id', 'projeto_id', 'avaliador_id', 'status', 'justificativa', 'resposta',
        'decidido_por', 'decidido_em',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPedidoPermissao::class,
            'decidido_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliador_id');
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }
}
