<?php

namespace App\Models;

use App\Enums\Categoria;
use App\Support\LimitesAvaliacao;
use App\Support\RegrasDistribuicao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

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
        'nome', 'ano', 'padrao', 'inscricoes_abertas', 'inicio_em', 'fim_em',
        'avaliacao_liberada_em', 'avaliacao_encerrada_em', 'submissoes_de', 'submissoes_ate',
        'ajustes_de', 'ajustes_ate', 'evento_de', 'evento_ate', 'itens_credenciamento',
        'ordem_abas',
        'avaliacoes_min_por_avaliador', 'avaliacoes_min_por_projeto',
        'avaliacoes_max_por_avaliador', 'avaliacoes_max_por_projeto', 'avaliacoes_por_categoria',
        'designacoes_por_projeto',
        'piso_fila_avaliador',
        'distribuicao_regras', 'distribuicao_ao_cadastrar',
    ];

    protected function casts(): array
    {
        return [
            'padrao' => 'boolean',
            'inscricoes_abertas' => 'boolean',
            'inicio_em' => 'date',
            'fim_em' => 'date',
            'avaliacao_liberada_em' => 'datetime',
            'avaliacao_encerrada_em' => 'datetime',
            'submissoes_de' => 'datetime',
            'submissoes_ate' => 'datetime',
            'ajustes_de' => 'datetime',
            'ajustes_ate' => 'datetime',
            'evento_de' => 'datetime',
            'evento_ate' => 'datetime',
            'itens_credenciamento' => 'array',
            'ordem_abas' => 'array',
            'avaliacoes_min_por_avaliador' => 'integer',
            'avaliacoes_min_por_projeto' => 'integer',
            'avaliacoes_max_por_avaliador' => 'integer',
            'piso_fila_avaliador' => 'integer',
            'avaliacoes_max_por_projeto' => 'integer',
            'designacoes_por_projeto' => 'integer',
            'avaliacoes_por_categoria' => 'array',
            'distribuicao_regras' => 'array',
            'distribuicao_ao_cadastrar' => 'boolean',
        ];
    }

    /**
     * A edição **padrão**: a que vale para quem não escolheu nenhuma (cadastro
     * público, e-mails, jobs da fila, CLI). Só existe uma marcada por vez.
     *
     * Sem nenhuma marcada — banco recém-criado ou base antiga —, cai na regra
     * histórica: a de inscrições abertas, mais recente.
     */
    public static function padrao(): ?self
    {
        return static::where('padrao', true)->first()
            ?? static::where('inscricoes_abertas', true)->latest('ano')->first();
    }

    /**
     * A edição **em escopo agora**: a que o usuário autenticado escolheu, ou a
     * padrão quando ele não escolheu nenhuma (ou não há usuário — fila, CLI,
     * requisição pública).
     *
     * Todo o resto do sistema pergunta por aqui, então trocar de edição troca de
     * uma vez os prazos, os limites, as regras de distribuição e os projetos que
     * a pessoa enxerga.
     */
    public static function atual(): ?self
    {
        return static::escopoDe(Auth::user());
    }

    /** A edição em escopo para um usuário específico. */
    public static function escopoDe(mixed $user): ?self
    {
        $escolhida = $user?->edicao_id;

        if ($escolhida !== null) {
            $edicao = static::find($escolhida);

            if ($edicao !== null) {
                return $edicao;
            }
        }

        return static::padrao();
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
    /**
     * O **piso da fila do avaliador**: quando as regras por categoria deixam a
     * fila dele abaixo deste número, uma segunda passada a completa ignorando
     * as regras. `null` desliga o piso — é o comportamento anterior à Sprint 85.
     */
    public static function pisoFilaAvaliador(): ?int
    {
        $piso = static::atual()?->piso_fila_avaliador;

        return $piso !== null && $piso > 0 ? (int) $piso : null;
    }

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

    /**
     * O período de ajustes já começou? Ao contrário das outras janelas, esta
     * fica FECHADA enquanto a data não for definida: a aba do orientador só
     * abre quando o admin marca o período.
     */
    public function ajustesIniciados(): bool
    {
        return $this->ajustes_de !== null && now()->greaterThanOrEqualTo($this->ajustes_de);
    }

    /** O período de ajustes acabou? Sem data de fim, segue aberto depois de começar. */
    public function ajustesEncerrados(): bool
    {
        return $this->ajustes_ate !== null && now()->greaterThan($this->ajustes_ate);
    }

    /** A aba de ajustes do orientador está aberta agora? */
    public function ajustesAbertos(): bool
    {
        return $this->ajustesIniciados() && ! $this->ajustesEncerrados();
    }

    /**
     * O evento já começou? Ao contrário das outras janelas, esta fica FECHADA
     * enquanto a data não for definida: credenciar é um ato presencial, então
     * ninguém credencia "por padrão".
     */
    public function eventoIniciado(): bool
    {
        return $this->evento_de !== null && now()->greaterThanOrEqualTo($this->evento_de);
    }

    /** O evento acabou? Sem data de fim, segue aberto depois de começar. */
    public function eventoEncerrado(): bool
    {
        return $this->evento_ate !== null && now()->greaterThan($this->evento_ate);
    }

    /** O credenciamento está aberto agora? */
    public function eventoEmAndamento(): bool
    {
        return $this->eventoIniciado() && ! $this->eventoEncerrado();
    }

    public function projetos(): HasMany
    {
        return $this->hasMany(Projeto::class);
    }
}
