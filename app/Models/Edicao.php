<?php

namespace App\Models;

use App\Enums\Categoria;
use App\Support\LimitesAvaliacao;
use App\Support\RegrasDistribuicao;
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
        'avaliacoes_max_por_avaliador', 'avaliacoes_max_por_projeto', 'avaliacoes_por_categoria',
        'distribuicao_regras', 'distribuicao_ao_cadastrar',
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
            'avaliacoes_max_por_avaliador' => 'integer',
            'avaliacoes_max_por_projeto' => 'integer',
            'avaliacoes_por_categoria' => 'array',
            'distribuicao_regras' => 'array',
            'distribuicao_ao_cadastrar' => 'boolean',
        ];
    }

    /** Edição atual (a que está com inscrições abertas). */
    public static function atual(): ?self
    {
        return static::where('inscricoes_abertas', true)->latest('ano')->first();
    }

    /**
     * Todos os limites de avaliação de uma vez. Quem precisa do número de vários
     * projetos numa mesma operação deve guardar este objeto em vez de chamar os
     * atalhos abaixo em laço — cada chamada consulta a edição atual.
     */
    public static function limites(): LimitesAvaliacao
    {
        return LimitesAvaliacao::daEdicao(static::atual());
    }

    /**
     * Quantas avaliações cada avaliador precisa concluir. É também quantos
     * projetos ele enxerga de uma vez no painel.
     */
    public static function minPorAvaliador(): int
    {
        return static::limites()->minPorAvaliador();
    }

    /** Teto total de avaliações de um mesmo avaliador (null = sem teto). */
    public static function maxPorAvaliador(): ?int
    {
        return static::limites()->maxPorAvaliador();
    }

    /** Quantas avaliações concluídas o projeto precisa receber (por categoria). */
    public static function minPorProjeto(?Categoria $categoria = null): int
    {
        return static::limites()->minPorProjeto($categoria);
    }

    /** Quantos avaliadores no máximo enxergam o projeto (por categoria). */
    public static function maxPorProjeto(?Categoria $categoria = null): int
    {
        return static::limites()->maxPorProjeto($categoria);
    }

    /**
     * Regras do algoritmo de distribuição (por categoria). Sem edição atual ou
     * sem configuração, valem os padrões — todas as categorias, sem faixa.
     */
    public static function regrasDistribuicao(): RegrasDistribuicao
    {
        return RegrasDistribuicao::deArray(static::atual()?->distribuicao_regras);
    }

    /**
     * O avaliador recém-cadastrado já recebe projetos designados? (toggle do
     * Algoritmo de distribuição). Sem edição atual, não.
     */
    public static function distribuiAoCadastrar(): bool
    {
        return (bool) static::atual()?->distribuicao_ao_cadastrar;
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
