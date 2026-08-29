<?php

namespace App\Models;

use Database\Factories\AvaliadorProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AvaliadorProfile extends Model
{
    /** @use HasFactory<AvaliadorProfileFactory> */
    use HasFactory;

    /**
     * Titulações aceitas no cadastro do avaliador. Quem está CURSANDO a
     * pós-graduação já pode avaliar — por isso cada nível aparece nas duas
     * situações, e não só como concluído.
     */
    public const TITULACOES = [
        'Especialização (em andamento)',
        'Especialização (concluída)',
        'Mestrado (em andamento)',
        'Mestrado (concluído)',
        'Doutorado (em andamento)',
        'Doutorado (concluído)',
    ];

    /**
     * Carga horária que cada avaliação concluída rende no certificado do
     * avaliador (2h30, definida pela organização).
     */
    public const MINUTOS_POR_AVALIACAO = 150;

    /**
     * Teto do certificado de avaliação online: 120 horas. Quem avalia além
     * disso continua avaliando, mas o certificado não passa daqui — é o limite
     * que a organização emite.
     */
    public const MAX_MINUTOS_CERTIFICADO = 120 * 60;

    protected $fillable = [
        'cpf', 'titulacao', 'area_id', 'subarea_id', 'limite_avaliacoes', 'comissao_especial',
        'estado_id', 'cidade_id',
    ];

    protected function casts(): array
    {
        return [
            'limite_avaliacoes' => 'integer',
            'comissao_especial' => 'boolean',
        ];
    }

    /**
     * Todas as áreas que este avaliador atende: a própria e as que o admin
     * liberou. Sem área no cadastro, só valem as extras.
     *
     * @return list<int>
     */
    public function areasAtendidas(): array
    {
        $extras = $this->relationLoaded('areasExtras')
            ? $this->areasExtras->pluck('area_id')->all()
            : $this->areasExtras()->pluck('area_id')->all();

        return array_values(array_unique(array_filter([$this->area_id, ...$extras])));
    }

    /**
     * Pares área+subárea que este avaliador atende, só onde há subárea — é o
     * casamento mais específico da distribuição.
     *
     * @return list<array{0:int, 1:int}>
     */
    public function paresAtendidos(): array
    {
        $extras = $this->relationLoaded('areasExtras') ? $this->areasExtras : $this->areasExtras()->get();

        $pares = [];
        if ($this->area_id && $this->subarea_id) {
            $pares[] = [$this->area_id, $this->subarea_id];
        }
        foreach ($extras as $extra) {
            if ($extra->subarea_id) {
                $pares[] = [$extra->area_id, $extra->subarea_id];
            }
        }

        return $pares;
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

    public function areasExtras(): HasMany
    {
        return $this->hasMany(AvaliadorAreaExtra::class, 'avaliador_profile_id');
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

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }

    public function cidade(): BelongsTo
    {
        return $this->belongsTo(Cidade::class);
    }
}
