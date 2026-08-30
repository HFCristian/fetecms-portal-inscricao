<?php

namespace App\Models;

use App\Enums\TipoPerguntaFeedback;
use App\Enums\UnidadeLimiteResposta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma pergunta do questionário. Alternativa (com as opções já copiadas do
 * modelo, se veio de um) ou dissertativa, com limite em palavras ou caracteres.
 */
class FeedbackPergunta extends Model
{
    protected $table = 'feedback_perguntas';

    protected $fillable = [
        'feedback_id', 'ordem', 'tipo', 'enunciado',
        'obrigatoria', 'opcoes', 'unidade', 'minimo', 'maximo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoPerguntaFeedback::class,
            'unidade' => UnidadeLimiteResposta::class,
            'opcoes' => 'array',
            'obrigatoria' => 'boolean',
            'minimo' => 'integer',
            'maximo' => 'integer',
        ];
    }

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    public function respostas(): HasMany
    {
        return $this->hasMany(FeedbackResposta::class, 'pergunta_id');
    }

    public function ehAlternativa(): bool
    {
        return $this->tipo === TipoPerguntaFeedback::Alternativa;
    }

    /** O texto de ajuda do limite ("entre 10 e 100 palavras"), quando há um. */
    public function limiteDescrito(): ?string
    {
        if ($this->ehAlternativa() || ($this->minimo === null && $this->maximo === null)) {
            return null;
        }

        $unidade = ($this->unidade ?? UnidadeLimiteResposta::Palavras)->label();

        return match (true) {
            $this->minimo !== null && $this->maximo !== null => "Entre {$this->minimo} e {$this->maximo} {$unidade}.",
            $this->minimo !== null => "No mínimo {$this->minimo} {$unidade}.",
            default => "No máximo {$this->maximo} {$unidade}.",
        };
    }

    /** @return array<string, mixed> */
    public function paraApi(): array
    {
        return [
            'id' => $this->id,
            'ordem' => $this->ordem,
            'tipo' => $this->tipo->value,
            'enunciado' => $this->enunciado,
            'obrigatoria' => $this->obrigatoria,
            'opcoes' => $this->opcoes ?? [],
            'unidade' => $this->unidade?->value,
            'minimo' => $this->minimo,
            'maximo' => $this->maximo,
            'limite' => $this->limiteDescrito(),
        ];
    }
}
