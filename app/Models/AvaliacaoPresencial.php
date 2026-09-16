<?php

namespace App\Models;

use App\Enums\StatusAvaliacao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma avaliação presencial: o que um avaliador deu a um projeto no estande.
 *
 * A nota é **separada** da avaliação online — ela alimenta a premiação, e não
 * o ranking que definiu a lista final.
 */
class AvaliacaoPresencial extends Model
{
    protected $table = 'avaliacoes_presenciais';

    /**
     * Quantas avaliações presenciais um projeto recebe. O limite existe porque
     * o tempo do evento é curto: acima disso, o avaliador é mandado para um
     * estande que ainda não foi visitado.
     */
    public const MAX_POR_PROJETO = 3;

    protected $fillable = [
        'edicao_id', 'projeto_id', 'avaliador_id', 'status', 'respostas', 'nota',
        'comentario', 'designacao_manual', 'iniciada_em', 'concluida_em',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusAvaliacao::class,
            'respostas' => 'array',
            'nota' => 'float',
            'designacao_manual' => 'boolean',
            'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
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

    public function concluida(): bool
    {
        return $this->status === StatusAvaliacao::Concluida;
    }
}
