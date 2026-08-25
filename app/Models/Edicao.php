<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Edicao extends Model
{
    protected $table = 'edicoes';

    /**
     * Mínimos usados quando não existe edição atual configurada — os mesmos
     * números que o edital praticava antes de virarem parâmetro.
     */
    public const PADRAO_MIN_POR_AVALIADOR = 3;

    public const PADRAO_MIN_POR_PROJETO = 3;

    protected $fillable = [
        'nome', 'ano', 'inscricoes_abertas', 'inicio_em', 'fim_em',
        'avaliacao_liberada_em', 'avaliacao_encerrada_em', 'submissoes_de', 'submissoes_ate',
        'avaliacoes_min_por_avaliador', 'avaliacoes_min_por_projeto',
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
            'avaliacoes_min_por_avaliador' => 'integer',
            'avaliacoes_min_por_projeto' => 'integer',
        ];
    }

    /** Edição atual (a que está com inscrições abertas). */
    public static function atual(): ?self
    {
        return static::where('inscricoes_abertas', true)->latest('ano')->first();
    }

    /**
     * Quantas avaliações cada avaliador precisa concluir. É também quantos
     * projetos ele enxerga de uma vez no painel.
     */
    public static function minPorAvaliador(): int
    {
        return static::atual()?->avaliacoes_min_por_avaliador ?? self::PADRAO_MIN_POR_AVALIADOR;
    }

    /** Quantas avaliações concluídas cada projeto precisa receber. */
    public static function minPorProjeto(): int
    {
        return static::atual()?->avaliacoes_min_por_projeto ?? self::PADRAO_MIN_POR_PROJETO;
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
