<?php

namespace App\Models;

use App\Enums\TipoPessoaCredenciamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um volume guardado no almoxarifado.
 *
 * O item é a unidade porque é ele que é retirado: a retirada parcial existe
 * justamente porque o dono de uma maquete aparece antes dos colegas, e uma
 * contagem só não diria o que saiu nem para quem.
 */
class AlmoxarifadoItem extends Model
{
    protected $table = 'almoxarifado_itens';

    protected $fillable = [
        'guarda_id', 'descricao', 'retirado_em', 'retirado_por_tipo',
        'retirado_por_id', 'retirado_por_nome', 'retirada_registrada_por',
    ];

    protected function casts(): array
    {
        return [
            'retirado_por_tipo' => TipoPessoaCredenciamento::class,
            'retirado_em' => 'datetime',
        ];
    }

    public function guarda(): BelongsTo
    {
        return $this->belongsTo(AlmoxarifadoGuarda::class, 'guarda_id');
    }

    public function retirado(): bool
    {
        return $this->retirado_em !== null;
    }
}
