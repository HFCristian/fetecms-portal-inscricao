<?php

namespace App\Models;

use App\Enums\Categoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um evento de cerimônia: o nome e o **público** — as categorias dos projetos
 * finalistas que ele recebe. A feira faz uma premiação para a FETECMS e outra
 * para a FETEC Jr e a FETECMS FUNDECT, com o mesmo funcionamento; o que muda
 * é quem entra.
 */
class CerimonialEvento extends Model
{
    protected $table = 'cerimonial_eventos';

    protected $fillable = ['edicao_id', 'nome', 'categorias'];

    protected function casts(): array
    {
        return ['categorias' => 'array'];
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(CerimonialCheckin::class, 'evento_id');
    }

    /** O projeto desta categoria é do público do evento? */
    public function recebe(?Categoria $categoria): bool
    {
        return $categoria !== null && in_array($categoria->value, $this->categorias ?? [], true);
    }

    /** "FETECMS", "FETEC Jr e FETECMS FUNDECT" — na ordem da lista final. */
    public function publicoLabel(): string
    {
        $labels = array_values(array_map(
            fn (Categoria $c) => $c->label(),
            array_filter(Categoria::ordemDaLista(), fn (Categoria $c) => in_array($c->value, $this->categorias ?? [], true)),
        ));

        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }

        return implode(', ', array_slice($labels, 0, -1)).' e '.end($labels);
    }
}
