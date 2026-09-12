<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação online → Designações: TODAS as designações da edição numa tabela
 * só, com há quanto tempo cada projeto está na mão do avaliador.
 *
 * Serve para o admin enxergar o que travou — projeto designado há duas semanas
 * e ainda não aberto — e **retirar** essas designações para outra pessoa. O que
 * está apenas *designada* sai sem perda; o que está *em avaliação* sai
 * descartando o rascunho (é a única forma de destravar, já que o avaliador não
 * pode desistir sozinho). Avaliação **concluída nunca sai**: a nota já conta
 * para o ranking.
 *
 * Retirada e reposição andam juntas: o projeto volta ao bolo e o
 * {@see DistribuicaoService::designarUm()} escolhe outro avaliador na hora,
 * pelas prioridades do edital. Sem ninguém elegível, o projeto fica
 * sub-coberto e a tela avisa — a saída é a designação manual.
 */
class DesignacaoService
{
    /** Colunas ordenáveis da tabela → coluna real no banco. */
    public const ORDENACOES = [
        'projeto' => 'projetos.titulo',
        'avaliador' => 'users.name',
        'area' => 'areas.nome',
        'situacao' => 'avaliacoes.status',
        'designado_em' => 'avaliacoes.created_at',
    ];

    /** Situações que o admin pode retirar do avaliador. */
    public const RETIRAVEIS = [StatusAvaliacao::Designada, StatusAvaliacao::EmAndamento];

    public function __construct(
        private readonly DistribuicaoService $distribuicao,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /**
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, Avaliacao>
     */
    public function listar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        return $this->query($filtros)->paginate($porPagina)->withQueryString();
    }

    /** Quantas designações há em cada situação, no recorte dos filtros. */
    public function resumo(array $filtros): array
    {
        $porStatus = $this->query($filtros)
            ->reorder()
            ->get(['avaliacoes.id', 'avaliacoes.status'])
            ->countBy(fn (Avaliacao $a) => $a->status->value);

        return [
            'total' => (int) $porStatus->sum(),
            'designada' => (int) ($porStatus[StatusAvaliacao::Designada->value] ?? 0),
            'em_andamento' => (int) ($porStatus[StatusAvaliacao::EmAndamento->value] ?? 0),
            'concluida' => (int) ($porStatus[StatusAvaliacao::Concluida->value] ?? 0),
        ];
    }

    /**
     * Uma linha da tabela.
     *
     * @return array<string, mixed>
     */
    public function linha(Avaliacao $a): array
    {
        $projeto = $a->projeto;
        $desde = $a->created_at;
        // Concluída congela o relógio: o tempo que interessa é o que ela levou,
        // não o que passou desde então.
        $ate = $a->status === StatusAvaliacao::Concluida ? ($a->concluida_em ?? now()) : now();

        return [
            'id' => $a->id,
            'projeto_id' => $projeto?->id,
            'projeto' => $projeto?->titulo,
            'area' => $projeto?->area?->nome,
            'categoria' => $projeto?->categoria?->value,
            'categoria_label' => $projeto?->categoria?->label(),
            'avaliador_id' => $a->avaliador_id,
            'avaliador' => $a->avaliador?->name,
            'situacao' => $a->status->value,
            'situacao_label' => $a->status->label(),
            'designacao_manual' => (bool) $a->designacao_manual,
            'designado_em' => $desde?->toIso8601String(),
            'designado_em_label' => $desde?->format('d/m/Y H:i'),
            'horas' => $desde === null ? null : (int) $desde->diffInHours($ate),
            'tempo_label' => $desde === null ? '—' : $this->tempo((int) $desde->diffInMinutes($ate)),
            'pode_retirar' => in_array($a->status, self::RETIRAVEIS, true),
        ];
    }

    /**
     * Retira as designações escolhidas e repõe cada projeto com outro avaliador.
     *
     * @param  list<int>  $ids
     * @return array{retiradas:int, redesignadas:int, sem_avaliador: list<string>}
     */
    public function retirar(array $ids, User $admin): array
    {
        $avaliacoes = Avaliacao::query()
            ->whereIn('id', $ids)
            ->whereIn('status', array_map(fn (StatusAvaliacao $s) => $s->value, self::RETIRAVEIS))
            ->with(['projeto.area:id,nome', 'avaliador:id,name'])
            ->get();

        if ($avaliacoes->isEmpty()) {
            throw ValidationException::withMessages([
                'avaliacao_ids' => 'Nenhuma designação retirável foi selecionada. Avaliação concluída não sai do avaliador.',
            ]);
        }

        $redesignadas = 0;
        $semAvaliador = [];

        DB::transaction(function () use ($avaliacoes, $admin, &$redesignadas, &$semAvaliador) {
            foreach ($avaliacoes as $avaliacao) {
                $projeto = $avaliacao->projeto;
                $anterior = $avaliacao->avaliador;
                $situacao = $avaliacao->status->label();

                $avaliacao->delete();

                if ($projeto === null) {
                    continue;
                }

                // Nunca devolve para quem acabou de sair: a retirada existe
                // justamente para o projeto trocar de mãos.
                $novo = $this->distribuicao->designarUm($projeto, array_filter([$anterior?->id]));

                if ($novo !== null) {
                    Avaliacao::create([
                        'projeto_id' => $projeto->id,
                        'avaliador_id' => $novo->id,
                        'status' => StatusAvaliacao::Designada,
                    ]);
                    $redesignadas++;
                } else {
                    $semAvaliador[] = $projeto->titulo;
                }

                $this->registros->designacaoRetirada(
                    $projeto,
                    $admin,
                    $anterior?->name ?? '(avaliador removido)',
                    $novo?->name,
                    $situacao,
                );
            }
        });

        return [
            'retiradas' => $avaliacoes->count(),
            'redesignadas' => $redesignadas,
            'sem_avaliador' => $semAvaliador,
        ];
    }

    /** O que os filtros da tela oferecem. */
    public function opcoes(): array
    {
        return [
            'situacoes' => array_map(
                fn (StatusAvaliacao $s) => ['value' => $s->value, 'label' => $s->label()],
                StatusAvaliacao::cases(),
            ),
            'categorias' => array_map(
                fn (Categoria $c) => ['value' => $c->value, 'label' => $c->label()],
                Categoria::cases(),
            ),
            'ordenacoes' => array_keys(self::ORDENACOES),
            'min_por_avaliador' => Edicao::limites()->minPorAvaliador(),
        ];
    }

    /** @return Builder<Avaliacao> */
    private function query(array $filtros): Builder
    {
        $ordenar = $filtros['ordenar'] ?? 'designado_em';
        $ordenar = isset(self::ORDENACOES[$ordenar]) ? $ordenar : 'designado_em';
        // O padrão é o mais antigo primeiro: é a designação parada que o admin
        // precisa ver.
        $direcao = ($filtros['direcao'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $busca = trim((string) ($filtros['q'] ?? ''));

        return Avaliacao::query()
            ->join('projetos', 'projetos.id', '=', 'avaliacoes.projeto_id')
            ->join('users', 'users.id', '=', 'avaliacoes.avaliador_id')
            ->leftJoin('areas', 'areas.id', '=', 'projetos.area_id')
            // O join é só para buscar e ordenar; quem aplica o escopo de edição,
            // o soft delete e o corte dos projetos de demonstração é o
            // `whereHas`, que passa pelo model do projeto.
            ->whereHas('projeto', fn (Builder $q) => $q->semDemo())
            ->select('avaliacoes.*')
            ->with(['projeto.area:id,nome', 'avaliador:id,name'])
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(fn ($sub) => $sub
                    ->whereRaw('LOWER(projetos.titulo) LIKE ?', [$termo])
                    ->orWhereRaw('LOWER(users.name) LIKE ?', [$termo]));
            })
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('projetos.area_id', $areaId))
            ->when($filtros['categoria'] ?? null, fn ($q, $categoria) => $q->where('projetos.categoria', $categoria))
            ->when($filtros['situacao'] ?? null, fn ($q, $situacao) => $q->where('avaliacoes.status', $situacao))
            ->when($filtros['avaliador_id'] ?? null, fn ($q, $id) => $q->where('avaliacoes.avaliador_id', $id))
            ->orderBy(self::ORDENACOES[$ordenar], $direcao)
            ->orderBy('avaliacoes.id');
    }

    /** "há 3 dias", "há 5 horas" — o tempo que a tela mostra na coluna. */
    private function tempo(int $minutos): string
    {
        if ($minutos < 60) {
            return $minutos <= 1 ? 'agora' : "há {$minutos} min";
        }

        $horas = intdiv($minutos, 60);

        if ($horas < 24) {
            return $horas === 1 ? 'há 1 hora' : "há {$horas} horas";
        }

        $dias = intdiv($horas, 24);

        return $dias === 1 ? 'há 1 dia' : "há {$dias} dias";
    }
}
