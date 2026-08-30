<?php

namespace App\Models;

use App\Enums\SituacaoDocumento;
use App\Enums\TipoPessoaCredenciamento;
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

    protected $fillable = [
        'credenciamento_id', 'documento_credenciamento_id',
        'pessoa_tipo', 'pessoa_id', 'pessoa_nome', 'situacao',
    ];

    protected function casts(): array
    {
        return [
            'pessoa_tipo' => TipoPessoaCredenciamento::class,
            'situacao' => SituacaoDocumento::class,
        ];
    }

    public function credenciamento(): BelongsTo
    {
        return $this->belongsTo(Credenciamento::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoCredenciamento::class, 'documento_credenciamento_id');
    }
}
