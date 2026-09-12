<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma versão da planta do evento.
 *
 * A **vigente** é a que o mapa desenha; as outras são o histórico, que existe
 * porque o ginásio muda de um ano para o outro e o desenho que valeu precisa
 * continuar consultável.
 */
class MapaLayout extends Model
{
    protected $table = 'mapa_layouts';

    protected $fillable = ['edicao_id', 'versao', 'nome', 'dados', 'vigente', 'criado_por'];

    protected function casts(): array
    {
        return [
            'dados' => 'array',
            'vigente' => 'boolean',
            'versao' => 'integer',
        ];
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /** A planta em vigor na edição (null quando a edição ainda não tem nenhuma). */
    public static function vigente(?Edicao $edicao = null): ?self
    {
        $edicao ??= Edicao::atual();

        if ($edicao === null) {
            return null;
        }

        return self::where('edicao_id', $edicao->id)->where('vigente', true)->first();
    }
}
