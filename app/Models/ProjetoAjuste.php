<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decisão do orientador sobre UMA sugestão de reclassificação feita por um
 * avaliador (ver AjustesOrientadorService). Existe uma linha por sugestão
 * decidida; sugestão sem linha é sugestão ainda não respondida.
 */
class ProjetoAjuste extends Model
{
    protected $table = 'projeto_ajustes';

    public const TIPO_AREA = 'area';

    public const TIPO_SUBAREA = 'subarea';

    protected $fillable = [
        'projeto_id', 'avaliacao_id', 'tipo', 'aceito',
        'de_id', 'para_id', 'user_id', 'decidido_em',
    ];

    protected function casts(): array
    {
        return [
            'aceito' => 'boolean',
            'decidido_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function avaliacao(): BelongsTo
    {
        return $this->belongsTo(Avaliacao::class);
    }
}
