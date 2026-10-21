<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Uma lista do **ranking presencial** (Sprint 251): os premiados pela nota do
 * estande, num recorte de cotas por categoria e área.
 *
 * Funciona como a lista da avaliação online ({@see ListaFinal}): preliminares
 * convivem à vontade; das finais, uma só é a **ativa** (`vigente`) por edição.
 * Toda lista nasce **rascunho**, editável sem justificativa, e gerar é o que
 * fecha. A diferença está no que a ativa define: aqui ela é o resultado
 * oficial da premiação, e não quem é finalista.
 *
 * `demo` separa a trilha do modo de teste, que lê a lista final demo.
 */
class ListaPresencial extends Model
{
    protected $table = 'listas_presenciais';

    public const TIPO_PRELIMINAR = 'preliminar';

    public const TIPO_FINAL = 'final';

    protected $fillable = [
        'edicao_id', 'nome', 'descricao', 'tipo', 'vigente', 'rascunho', 'demo', 'versao',
        'cotas', 'origens', 'gerada_em', 'gerada_por',
    ];

    protected $attributes = ['tipo' => self::TIPO_FINAL];

    protected function casts(): array
    {
        return [
            'vigente' => 'boolean',
            'rascunho' => 'boolean',
            'demo' => 'boolean',
            'versao' => 'integer',
            'cotas' => 'array',
            'origens' => 'array',
            'gerada_em' => 'datetime',
        ];
    }

    public function ehFinal(): bool
    {
        return $this->tipo === self::TIPO_FINAL;
    }

    public function ehPreliminar(): bool
    {
        return $this->tipo === self::TIPO_PRELIMINAR;
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerada_por');
    }

    /** A seção por nota: os projetos escolhidos pela classificação. */
    public function projetos(): BelongsToMany
    {
        return $this->belongsToMany(Projeto::class, 'lista_presencial_projetos', 'lista_presencial_id', 'projeto_id')
            ->withPivot('manual')
            ->withTimestamps();
    }

    /** A final ativa da edição em escopo, na trilha oficial ou na de teste. */
    public static function vigente(?Edicao $edicao = null, bool $demo = false): ?self
    {
        $edicao ??= Edicao::atual();

        return $edicao === null
            ? null
            : static::where('edicao_id', $edicao->id)
                ->where('tipo', self::TIPO_FINAL)
                ->where('vigente', true)
                ->where('demo', $demo)
                ->latest('id')
                ->first();
    }
}
