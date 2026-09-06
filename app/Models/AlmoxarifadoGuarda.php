<?php

namespace App\Models;

use App\Enums\TipoPessoaCredenciamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Um atendimento do almoxarifado: a equipe de um projeto deixou material
 * guardado durante a feira.
 *
 * O responsável é quem **deixou** — quem retira pode ser outra pessoa, e é por
 * isso que a retirada mora no item, não aqui: cada volume sai no seu horário e
 * na mão de quem veio buscá-lo.
 */
class AlmoxarifadoGuarda extends Model
{
    use SoftDeletes;

    protected $table = 'almoxarifado_guardas';

    protected $fillable = [
        'edicao_id', 'projeto_id', 'responsavel_tipo', 'responsavel_id', 'responsavel_nome',
        'registrado_por', 'registrado_em', 'demo',
    ];

    protected function casts(): array
    {
        return [
            'responsavel_tipo' => TipoPessoaCredenciamento::class,
            'registrado_em' => 'datetime',
            'demo' => 'boolean',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(AlmoxarifadoItem::class, 'guarda_id');
    }

    /** Itens que ainda estão no almoxarifado. */
    public function pendentes(): HasMany
    {
        return $this->itens()->whereNull('retirado_em');
    }

    /** Tudo já foi retirado? */
    public function retiradoTotalmente(): bool
    {
        return $this->itens->isNotEmpty() && $this->itens->every(fn (AlmoxarifadoItem $i) => $i->retirado());
    }
}
