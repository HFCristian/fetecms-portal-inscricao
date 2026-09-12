<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Rubrica;
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
        private readonly NotificacaoDesignacaoService $notificacoes,
    ) {}

    /**
     * Designa **vários projetos para vários avaliadores** de uma vez, no
     * cruzamento de tudo com tudo: 3 projetos × 2 avaliadores = 6 designações.
     *
     * É o que a organização faz na véspera — "estes cinco trabalhos precisam de
     * mais gente, mandem para estes três avaliadores" —, e fazer isso projeto a
     * projeto pela tela antiga significava abrir cinco diálogos.
     *
     * **Quem já avaliou aquele projeto não o recebe de novo**: a mesma pessoa
     * avaliando duas vezes o mesmo trabalho distorce a nota. O par é pulado, o
     * resto continua sendo designado, e a resposta diz **quais foram e por quê**
     * — sumir com o nome em silêncio deixaria o admin achando que a cobertura
     * aconteceu inteira.
     *
     * Como toda designação manual, esta passa por cima dos limites da
     * distribuição e fica protegida das devoluções automáticas
     * (`designacao_manual`). Quem teve a avaliação **devolvida pelo prazo** é
     * revivido com o rascunho intacto — é o que o admin quis dizer ao
     * escolhê-lo.
     *
     * Terminado tudo, cada avaliador recebe **um** e-mail com a lista do que
     * chegou, e o admin recebe o resumo da operação.
     *
     * @param  list<int>  $projetoIds
     * @param  list<int>  $avaliadorIds
     * @return array<string, mixed>
     */
    public function designar(array $projetoIds, array $avaliadorIds, User $admin): array
    {
        $projetos = Projeto::whereIn('id', $projetoIds)
            ->where('status', ProjetoStatus::Submetido->value)
            ->get(['id', 'titulo']);

        $avaliadores = User::whereIn('id', $avaliadorIds)
            ->where('role', Role::Avaliador->value)
            ->where('is_active', true)
            ->get(['id', 'name', 'email']);

        if ($projetos->isEmpty()) {
            throw ValidationException::withMessages([
                'projeto_ids' => 'Escolha ao menos um projeto submetido.',
            ]);
        }

        if ($avaliadores->isEmpty()) {
            throw ValidationException::withMessages([
                'avaliador_ids' => 'Escolha ao menos um avaliador ativo.',
            ]);
        }

        // Tudo que estas pessoas já têm nestes projetos, numa consulta só — as
        // devolvidas incluídas, que o escopo esconde e que são justamente as
        // que a chave única (projeto, avaliador) faria explodir num insert.
        $existentes = Avaliacao::comDevolvidas()
            ->whereIn('projeto_id', $projetos->pluck('id'))
            ->whereIn('avaliador_id', $avaliadores->pluck('id'))
            ->get()
            ->keyBy(fn (Avaliacao $a) => $a->projeto_id.':'.$a->avaliador_id);

        $resultado = [
            'designadas' => 0,
            'retomadas' => 0,
            'ignoradas' => [],
            'por_avaliador' => [],
        ];

        DB::transaction(function () use ($projetos, $avaliadores, $existentes, &$resultado) {
            foreach ($projetos as $projeto) {
                foreach ($avaliadores as $avaliador) {
                    $existente = $existentes->get($projeto->id.':'.$avaliador->id);

                    if ($existente === null) {
                        Avaliacao::create([
                            'projeto_id' => $projeto->id,
                            'avaliador_id' => $avaliador->id,
                            'status' => StatusAvaliacao::Designada,
                            'designacao_manual' => true,
                        ]);

                        $resultado['designadas']++;
                        $this->creditar($resultado, $avaliador, $projeto->titulo);

                        continue;
                    }

                    if ($existente->status === StatusAvaliacao::Concluida) {
                        $resultado['ignoradas'][] = [
                            'projeto' => $projeto->titulo,
                            'avaliador' => $avaliador->name,
                            'motivo' => 'já avaliou este projeto',
                        ];

                        continue;
                    }

                    if ($existente->foiDevolvida()) {
                        $existente->update(['devolvida_em' => null, 'designacao_manual' => true]);

                        $resultado['retomadas']++;
                        $this->creditar($resultado, $avaliador, $projeto->titulo);

                        continue;
                    }

                    // Já está com o projeto: nada muda, mas a partir de agora a
                    // designação manual o protege das devoluções automáticas.
                    if (! $existente->designacao_manual) {
                        $existente->update(['designacao_manual' => true]);
                    }

                    $resultado['ignoradas'][] = [
                        'projeto' => $projeto->titulo,
                        'avaliador' => $avaliador->name,
                        'motivo' => 'já está com este projeto',
                    ];
                }
            }
        });

        // Os e-mails saem depois da transação: nada de avisar alguém sobre uma
        // designação que um rollback desfez.
        foreach ($resultado['por_avaliador'] as $linha) {
            $this->notificacoes->avaliador($linha['avaliador'], $linha['projetos']);
        }

        $this->notificacoes->resumoParaAdmin($admin, $resultado);

        return $this->resumoParaTela($resultado);
    }

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

    /**
     * A nota que um avaliador deu a um projeto, **seção por seção**, com a soma
     * geral (Designações → Ver notas).
     *
     * A tabela de Designações mostra a situação de cada avaliação, mas não o que
     * saiu dela — e é a nota que decide a lista final. Quando um orientador
     * contesta, ou quando duas avaliações do mesmo projeto divergem muito, o
     * admin precisa abrir o detalhe sem ter de entrar na conta do avaliador.
     *
     * Só **avaliação concluída** tem nota: rascunho é resposta pela metade, e
     * mostrá-lo como "nota" seria comparar coisas diferentes no mesmo lugar.
     *
     * Ver a nota **fica registrado** (Registros → Notas). A consulta não muda
     * nada, mas o parecer é anônimo para o orientador: saber quem leu o quê é o
     * que protege esse sigilo.
     *
     * @return array<string, mixed>
     */
    public function notas(Avaliacao $avaliacao, User $admin): array
    {
        if ($avaliacao->status !== StatusAvaliacao::Concluida) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta avaliação ainda não foi enviada — só há nota depois que o avaliador conclui.',
            ]);
        }

        $avaliacao->loadMissing(['projeto.area:id,nome', 'avaliador:id,name']);
        $respostas = $avaliacao->respostas ?? [];

        $secoes = array_map(function (array $secao) use ($respostas) {
            $perguntas = array_values(array_filter(
                Rubrica::perguntas(),
                fn (array $p) => $p['secao'] === $secao['chave'],
            ));

            return [
                'chave' => $secao['chave'],
                'titulo' => $secao['titulo'],
                'pontos' => round(Rubrica::pontosDaSecao($secao['chave'], $respostas), 2),
                'maximo' => round($secao['maximo'], 2),
                'perguntas' => array_map(
                    fn (array $p) => $this->linhaDaPergunta($p, $respostas[$p['chave']] ?? null),
                    $perguntas,
                ),
            ];
        }, Rubrica::secoesPontuadas());

        // A nota gravada é a que vale (foi ela que entrou no ranking); a
        // calculada vai junto para a tela denunciar qualquer divergência em vez
        // de escondê-la — elas só divergem se a rubrica mudou depois do envio.
        $gravada = $avaliacao->nota === null ? null : round((float) $avaliacao->nota, 2);
        $calculada = round($avaliacao->notaCalculada(), 2);

        $this->registros->notasVisualizadas(
            $avaliacao->projeto,
            $admin,
            $avaliacao->avaliador?->name ?? 'Avaliador #'.$avaliacao->avaliador_id,
            $gravada ?? $calculada,
        );

        return [
            'avaliacao_id' => $avaliacao->id,
            'projeto' => [
                'id' => $avaliacao->projeto?->id,
                'titulo' => $avaliacao->projeto?->titulo,
                'area' => $avaliacao->projeto?->area?->nome,
                'categoria' => $avaliacao->projeto?->categoria?->label(),
            ],
            'avaliador' => $avaliacao->avaliador?->name,
            'concluida_em' => $avaliacao->concluida_em?->toIso8601String(),
            'concluida_em_label' => $avaliacao->concluida_em?->format('d/m/Y H:i'),
            'secoes' => $secoes,
            'nota' => $gravada ?? $calculada,
            'nota_calculada' => $calculada,
            'nota_maxima' => Rubrica::NOTA_MAXIMA,
            // O parecer escrito acompanha a nota: é o que explica o número.
            'recomendacao_video' => $avaliacao->comentario_video,
            'recomendacao_projeto' => $avaliacao->comentario_projeto,
        ];
    }

    /**
     * Uma linha do detalhe: o que foi perguntado, o que o avaliador respondeu e
     * quanto aquilo rendeu.
     *
     * A resposta sai com o **rótulo** da escala ("Bom"), e não só o número: é
     * assim que ela aparece para quem avaliou, e o admin precisa ler a mesma
     * coisa que o avaliador leu.
     *
     * @param  array<string, mixed>  $pergunta
     * @return array<string, mixed>
     */
    private function linhaDaPergunta(array $pergunta, mixed $resposta): array
    {
        $peso = round((float) $pergunta['peso'], 4);

        if ($pergunta['tipo'] === Rubrica::TIPO_SIM_NAO) {
            $rotulo = $resposta === null ? null : ($resposta ? 'Sim' : 'Não');
            $pontos = $resposta ? $peso : 0.0;
        } else {
            $valor = $resposta === null || $resposta === '' ? null : (int) $resposta;
            $rotulo = $valor === null ? null : ($valor.' — '.(Rubrica::ESCALA[$valor] ?? '—'));
            $pontos = $valor === null ? 0.0 : ($valor / max(array_keys(Rubrica::ESCALA))) * $peso;
        }

        return [
            'chave' => $pergunta['chave'],
            // `rotulo` é o nome curto do quesito e `texto` é a pergunta inteira:
            // os dois vão, porque a tela usa um como título e o outro como corpo.
            'rotulo' => $pergunta['rotulo'] ?? $pergunta['chave'],
            'pergunta' => $pergunta['texto'] ?? $pergunta['rotulo'] ?? $pergunta['chave'],
            'tipo' => $pergunta['tipo'],
            // Sem resposta é diferente de resposta zero: a tela precisa dizer
            // "não respondida" em vez de fingir um "Não possui".
            'resposta' => $rotulo,
            'respondida' => $rotulo !== null,
            'peso' => $peso,
            'pontos' => round($pontos, 2),
        ];
    }

    /**
     * As duas listas do diálogo de designação: projetos submetidos e
     * avaliadores ativos, filtrados pelo que o admin digitou.
     *
     * A busca é no servidor e limitada porque a base é grande: mandar 600
     * projetos e 300 avaliadores para a tela a cada abertura seria pagar caro
     * por uma lista que ninguém lê inteira.
     *
     * @return array<string, mixed>
     */
    public function opcoesDeDesignacao(string $projeto = '', string $avaliador = '', int $limite = 30): array
    {
        $como = fn (string $termo) => '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($termo))).'%';

        $projetos = Projeto::semDemo()
            ->where('status', ProjetoStatus::Submetido->value)
            ->when(trim($projeto) !== '', fn ($q) => $q->whereRaw('LOWER(titulo) LIKE ?', [$como($projeto)]))
            ->with('area:id,nome')
            ->withCount([
                'avaliacoes as concluidas_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->orderBy('titulo')
            ->limit($limite)
            ->get();

        $avaliadores = User::where('role', Role::Avaliador->value)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->when(trim($avaliador) !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->whereRaw('LOWER(name) LIKE ?', [$como($avaliador)])
                ->orWhereRaw('LOWER(email) LIKE ?', [$como($avaliador)])))
            ->with('avaliadorProfile.area:id,nome')
            ->withCount([
                'avaliacoes as designadas_count' => fn ($q) => $q->whereIn('status', [
                    StatusAvaliacao::Designada->value, StatusAvaliacao::EmAndamento->value,
                ]),
            ])
            ->orderBy('name')
            ->limit($limite)
            ->get();

        return [
            'projetos' => $projetos->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'categoria' => $p->categoria?->label(),
                'concluidas' => (int) $p->concluidas_count,
            ])->all(),
            'avaliadores' => $avaliadores->map(fn (User $u) => [
                'id' => $u->id,
                'nome' => $u->name,
                'email' => $u->email,
                'area' => $u->avaliadorProfile?->area?->nome,
                'na_fila' => (int) $u->designadas_count,
            ])->all(),
        ];
    }

    /**
     * Guarda, por avaliador, o que ele ganhou nesta operação — é a lista que vai
     * no e-mail dele.
     *
     * @param  array<string, mixed>  $resultado
     */
    private function creditar(array &$resultado, User $avaliador, string $titulo): void
    {
        $resultado['por_avaliador'][$avaliador->id] ??= [
            'avaliador' => $avaliador,
            'projetos' => [],
        ];

        $resultado['por_avaliador'][$avaliador->id]['projetos'][] = $titulo;
    }

    /**
     * O mesmo resultado, no formato que a tela lê: sem os models do e-mail e
     * com as frases prontas do resumo.
     *
     * @param  array<string, mixed>  $resultado
     * @return array<string, mixed>
     */
    private function resumoParaTela(array $resultado): array
    {
        return [
            'designadas' => $resultado['designadas'],
            'retomadas' => $resultado['retomadas'],
            'ignoradas' => $resultado['ignoradas'],
            'avaliadores' => array_values(array_map(fn (array $linha) => [
                'id' => $linha['avaliador']->id,
                'nome' => $linha['avaliador']->name,
                'projetos' => $linha['projetos'],
            ], $resultado['por_avaliador'])),
            'resumo' => NotificacaoDesignacaoService::descreverResumo($resultado),
            'problemas' => NotificacaoDesignacaoService::descreverProblemas($resultado),
        ];
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
