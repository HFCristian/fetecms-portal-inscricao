<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Edicao extends Model
{
    protected $table = 'edicoes';

    protected $fillable = [
        'nome', 'ano', 'inscricoes_abertas', 'inicio_em', 'fim_em',
        'avaliacao_liberada_em', 'avaliacao_encerrada_em', 'submissoes_de', 'submissoes_ate',
    ];

    protected function casts(): array
    {
        return [
            'inscricoes_abertas' => 'boolean',
            'inicio_em' => 'date',
            'fim_em' => 'date',
            'avaliacao_liberada_em' => 'datetime',
            'avaliacao_encerrada_em' => 'datetime',
            'submissoes_de' => 'datetime',
            'submissoes_ate' => 'datetime',
        ];
    }

    /** Edição atual (a que está com inscrições abertas). */
    public static function atual(): ?self
    {
        return static::where('inscricoes_abertas', true)->latest('ano')->first();
    }

    /** O prazo de submissão já passou? Sem prazo definido, as inscrições ficam abertas. */
    public function inscricoesEncerradas(): bool
    {
        return $this->submissoes_ate !== null && now()->greaterThan($this->submissoes_ate);
    }

    /** As inscrições ainda não abriram? Sem data de abertura, já estão abertas. */
    public function inscricoesNaoIniciadas(): bool
    {
        return $this->submissoes_de !== null && now()->lessThan($this->submissoes_de);
    }

    /** A avaliação online já foi liberada (data definida e já alcançada)? */
    public function avaliacaoLiberada(): bool
    {
        return $this->avaliacao_liberada_em !== null && now()->greaterThanOrEqualTo($this->avaliacao_liberada_em);
    }

    /**
     * O período de avaliação já se encerrou? Sem data de encerramento, a
     * avaliação segue aberta depois de liberada.
     */
    public function avaliacaoEncerrada(): bool
    {
        return $this->avaliacao_encerrada_em !== null && now()->greaterThan($this->avaliacao_encerrada_em);
    }
}
