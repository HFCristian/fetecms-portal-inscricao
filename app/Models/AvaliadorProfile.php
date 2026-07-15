<?php

namespace App\Models;

use Database\Factories\AvaliadorProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvaliadorProfile extends Model
{
    /** @use HasFactory<AvaliadorProfileFactory> */
    use HasFactory;

    protected $fillable = ['cpf', 'titulacao', 'area_id', 'subarea_id', 'limite_avaliacoes'];

    protected function casts(): array
    {
        return ['limite_avaliacoes' => 'integer'];
    }

    /**
     * Se o avaliador atingiu o limite e não pode assumir NOVOS projetos.
     * `$assumidas` = avaliações em andamento + concluídas (as que ele pegou).
     * Sem limite (null) nunca bloqueia. Regra usada pela seleção futura (E7);
     * avaliações já em andamento podem ser concluídas mesmo excedendo o limite.
     */
    public function atingiuLimite(int $assumidas): bool
    {
        return $this->limite_avaliacoes !== null && $assumidas >= $this->limite_avaliacoes;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function subarea(): BelongsTo
    {
        return $this->belongsTo(Subarea::class);
    }
}
