<?php

namespace App\Models;

use App\Enums\PublicoMala;
use App\Enums\StatusFeedback;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um pedido de feedback: um questionário dirigido a um recorte da base.
 *
 * As respostas são **anônimas**: `participacoes` sabe quem foi alcançado e quem
 * respondeu; `respostas` guarda o conteúdo sem dono.
 */
class Feedback extends Model
{
    protected $table = 'feedbacks';

    protected $fillable = [
        'edicao_id', 'titulo', 'descricao', 'publicos',
        'status', 'criado_por', 'encerrado_em',
    ];

    protected function casts(): array
    {
        return [
            'publicos' => 'array',
            'status' => StatusFeedback::class,
            'encerrado_em' => 'datetime',
        ];
    }

    public function perguntas(): HasMany
    {
        return $this->hasMany(FeedbackPergunta::class)->orderBy('ordem');
    }

    public function participacoes(): HasMany
    {
        return $this->hasMany(FeedbackParticipacao::class);
    }

    public function respostas(): HasMany
    {
        return $this->hasMany(FeedbackResposta::class);
    }

    public function destinatarios(): HasMany
    {
        return $this->hasMany(FeedbackDestinatario::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /** Aceita resposta? Encerrado guarda os resultados, mas não recebe mais. */
    public function aberto(): bool
    {
        return $this->status !== StatusFeedback::Encerrado;
    }

    /** @return array<int, PublicoMala> */
    public function publicosEnum(): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => PublicoMala::tryFrom((string) $v),
            $this->publicos ?? [],
        )));
    }
}
