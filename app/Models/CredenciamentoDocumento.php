<?php

namespace App\Models;

use App\Enums\SituacaoDocumento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A conferência de UM documento de UMA pessoa num credenciamento.
 *
 * O nome e o papel da pessoa ficam gravados aqui (e não só a FK): a equipe pode
 * mudar depois do evento, e o registro precisa continuar dizendo quem estava no
 * balcão com qual documento.
 */
class CredenciamentoDocumento extends Model
{
    protected $table = 'credenciamento_documentos';

    /**
     * `pessoa_tipo` é o papel (aluno, orientador, coorientador) ou `projeto`,
     * no item que é do projeto e não de alguém (Sprint 182) — por isso fica
     * como texto, e não no enum dos papéis.
     */
    protected $fillable = [
        'credenciamento_id', 'item_id',
        'pessoa_tipo', 'pessoa_id', 'pessoa_nome', 'situacao',
    ];

    protected function casts(): array
    {
        return [
            'situacao' => SituacaoDocumento::class,
        ];
    }

    public function credenciamento(): BelongsTo
    {
        return $this->belongsTo(Credenciamento::class);
    }

    /** O item da lista única conferido (Sprint 181). */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(ItemConferencia::class, 'item_id');
    }
}
