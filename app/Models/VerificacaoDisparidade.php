<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma verificação de disparidade (Avaliação online → Ranking → Verificar
 * disparidade): a diferença que o admin pediu e a lista de projetos cujas
 * notas se afastaram pelo menos isso.
 *
 * A lista fica **congelada** nos itens: uma avaliação nova depois muda a
 * amplitude do projeto, mas não pode mudar o que a verificação de ontem disse.
 */
class VerificacaoDisparidade extends Model
{
    protected $table = 'verificacoes_disparidade';

    protected $fillable = ['edicao_id', 'diferenca', 'total', 'gerada_por'];

    protected function casts(): array
    {
        return [
            'diferenca' => 'float',
            'total' => 'integer',
        ];
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerada_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(VerificacaoDisparidadeProjeto::class, 'verificacao_id');
    }
}
