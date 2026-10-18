<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Enums\VagaPresencial;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\ChecagemEstande;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\JanelaTurnos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial → **distribuição por turno** (Sprint 170).
 *
 * Nos moldes da distribuição online, com o que o dia do evento acrescenta:
 *
 * - **o turno**: cada projeto apresenta num turno (Mapa do Evento → Turnos), e
 *   só recebe avaliador naquele turno. A distribuição trabalha uma
 *   **ocorrência** por vez — dia × turno — e alcança todos que confirmaram a
 *   avaliação presencial (a ativação na cabine saiu na Sprint 221);
 * - **o projeto pronto**: só entra quem já foi **credenciado** no balcão e
 *   teve o estande **checado**. Mandar avaliador a um estande que ninguém viu
 *   montado é mandá-lo esperar;
 * - **o prazo**: a avaliação entregue numa ocorrência vale até o fim do turno
 *   mais 30 minutos ({@see JanelaTurnos}). Passado isso ela não ocupa mais vaga
 *   no projeto, e outro avaliador pode recebê-lo.
 *
 * Do online vêm a prioridade de área (subárea → área → área correlata → o que
 * sobrar), as **rodadas iguais** (ninguém recebe o k+1-ésimo projeto antes de
 * todos terem k) e as vagas por projeto — comuns e da comissão especial
 * (Sprint 222, {@see JanelaTurnos::vagaPara()}). A designação manual passa
 * por cima do teto, como em todo o portal — mas não do credenciamento e da
 * checagem, que são fatos, não regras.
 *
 * A designação é **opcional**: sem ela, o avaliador escolhe o estande no mapa
 * ({@see AvaliacaoPresencialFluxoService}).
 */
class DistribuicaoPresencialService
{
    public function __construct(private readonly TurnosPresenciaisService $turnos) {}

    /**
     * Tudo que a tela da distribuição mostra para uma ocorrência.
     *
     * @return array<string, mixed>
     */
    public function painel(?User $admin, bool $teste = false, ?string $dia = null, ?string $turno = null, ?string $busca = null): array
    {
        $config = $this->turnos->config($admin, $teste, $dia, $turno);
        $demo = $config['modo_teste'];
        $foco = $config['foco'];

        return [
            ...$config,
            'avaliadores' => $this->turnos->avaliadores($foco, $demo, $busca),
            ...($foco === null ? ['resumo' => null, 'projetos' => [], 'designacoes' => []] : $this->situacao($foco, $demo)),
        ];
    }

    /**
     * Distribui os projetos prontos do turno entre os avaliadores que
     * confirmaram o presencial, até cada um ter a fila cheia ou não sobrar
     * projeto com vaga.
     *
     * @return array<string, mixed>
     */
    public function distribuir(string $dia, Turno $turno, bool $demo): array
    {
        $janela = $this->janelaValida($dia, $turno);
        $avaliadores = $this->avaliadoresConfirmados($demo);

        if ($avaliadores->isEmpty()) {
            throw ValidationException::withMessages([
                'avaliadores' => 'Nenhum avaliador confirmou a avaliação presencial.',
            ]);
        }

        $projetos = $this->projetosDoTurno($turno, $demo);
        $prontos = $projetos['prontos'];
        $criadas = DB::transaction(fn () => $this->rodadas($avaliadores, $prontos, $dia, $turno, $janela));

        $avisos = [];
        if ($projetos['sem_credenciamento'] > 0) {
            $avisos[] = "{$projetos['sem_credenciamento']} projeto(s) do turno ainda não credenciado(s)";
        }
        if ($projetos['sem_checagem'] > 0) {
            $avisos[] = "{$projetos['sem_checagem']} credenciado(s) sem o estande checado";
        }

        return [
            'designadas' => $criadas,
            'avaliadores' => $avaliadores->count(),
            'prontos' => $prontos->count(),
            'resumo' => "{$criadas} designação(ões) criada(s) para {$avaliadores->count()} avaliador(es)."
                .($avisos === [] ? '' : ' Ficaram de fora: '.implode('; ', $avisos).'.'),
        ];
    }

    /**
     * Devolve ao bolo o que foi distribuído nesta ocorrência e ainda não foi
     * aberto, e distribui de novo. O que está em avaliação, o enviado e o que
     * a organização designou à mão não se mexem.
     *
     * @return array<string, mixed>
     */
    public function redistribuir(string $dia, Turno $turno, bool $demo): array
    {
        $this->janelaValida($dia, $turno);

        $devolvidas = AvaliacaoPresencial::where('edicao_id', Edicao::atual()?->id)
            ->whereDate('dia', $dia)
            ->where('turno', $turno->value)
            ->where('status', StatusAvaliacao::Designada->value)
            ->where('designacao_manual', false)
            ->whereIn('avaliador_id', $this->avaliadoresConfirmados($demo)->pluck('id'))
            ->delete();

        $resultado = $this->distribuir($dia, $turno, $demo);

        return [
            ...$resultado,
            'devolvidas' => $devolvidas,
            'resumo' => "{$devolvidas} designação(ões) devolvida(s) ao bolo. ".$resultado['resumo'],
        ];
    }

    /**
     * Repõe a fila de **um** avaliador na ocorrência — o que acontece quando ele
     * envia uma avaliação que veio da distribuição, como na reposição da fila
     * online. Fora do turno, ou sem ter confirmado o presencial, não faz nada.
     */
    public function completarFila(User $avaliador, string $dia, Turno $turno, bool $teste = false): int
    {
        $janela = JanelaTurnos::daEdicao();
        $demo = $teste && (bool) $avaliador->is_demo;

        if (! $janela->existe($dia, $turno)
            || (! $demo && ! $janela->emAndamento($dia, $turno))
            || $avaliador->avaliadorProfile()->first()?->presencial !== true) {
            return 0;
        }

        $avaliador->loadMissing('avaliadorProfile.areasExtras');

        return DB::transaction(fn () => $this->rodadas(
            collect([$avaliador]), $this->projetosDoTurno($turno, $demo)['prontos'], $dia, $turno, $janela,
        ));
    }

    /**
     * Designação manual: N projetos × N avaliadores na ocorrência. Passa por
     * cima do teto por projeto; não passa por cima do credenciamento, da
     * checagem, do turno do projeto e da confirmação do presencial.
     *
     * @param  list<int>  $projetoIds
     * @param  list<int>  $avaliadorIds
     * @return array<string, mixed>
     */
    public function designar(array $projetoIds, array $avaliadorIds, string $dia, Turno $turno, bool $demo): array
    {
        $janela = $this->janelaValida($dia, $turno);
        $projetos = $this->projetosDoTurno($turno, $demo, comTodos: true);
        $todos = $projetos['todos']->keyBy('id');
        $prontos = $projetos['prontos']->keyBy('id');
        $confirmados = $this->avaliadoresConfirmados($demo)->keyBy('id');
        $ocupadas = $this->ocupacaoPorVaga($prontos->keys()->all(), $janela);

        $criadas = 0;
        $ignoradas = [];

        DB::transaction(function () use ($projetoIds, $avaliadorIds, $todos, $prontos, $confirmados, $dia, $turno, $janela, &$ocupadas, &$criadas, &$ignoradas) {
            foreach (array_unique($projetoIds) as $projetoId) {
                $projeto = $todos[$projetoId] ?? null;

                if ($projeto === null) {
                    $ignoradas[] = "Projeto {$projetoId}: não é finalista deste turno.";

                    continue;
                }

                if (! $prontos->has($projetoId)) {
                    $ignoradas[] = "{$projeto->titulo}: ainda não foi credenciado e checado.";

                    continue;
                }

                foreach (array_unique($avaliadorIds) as $avaliadorId) {
                    $avaliador = $confirmados[$avaliadorId] ?? null;

                    if ($avaliador === null) {
                        $nome = User::find($avaliadorId)?->name ?? "Avaliador {$avaliadorId}";
                        $ignoradas[] = "{$nome}: não confirmou a avaliação presencial.";

                        continue;
                    }

                    $existente = AvaliacaoPresencial::where('projeto_id', $projetoId)->where('avaliador_id', $avaliadorId)->first();

                    if ($existente !== null && $existente->ocupaVaga($janela)) {
                        $ignoradas[] = "{$projeto->titulo} → {$avaliador->name}: ".($existente->concluida() ? 'já avaliou.' : 'já está com ele.');

                        continue;
                    }

                    $vaga = $janela->vagaForcada(self::daComissao($avaliador), $ocupadas[$projetoId] ?? []);
                    $this->entregar($projetoId, $avaliador, $dia, $turno, $vaga, manual: true, existente: $existente);
                    $ocupadas[$projetoId] ??= ['comum' => 0, 'comite' => 0];
                    $ocupadas[$projetoId][$vaga->value]++;
                    $criadas++;
                }
            }
        });

        return [
            'designadas' => $criadas,
            'ignoradas' => array_values(array_unique($ignoradas)),
            'resumo' => "{$criadas} designação(ões) criada(s).",
        ];
    }

    /** Retira uma designação que ainda não virou nota. */
    public function retirar(AvaliacaoPresencial $avaliacao): void
    {
        if ($avaliacao->concluida()) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Avaliação enviada não é retirada — a nota já existe.',
            ]);
        }

        $avaliacao->delete();
    }

    /**
     * Os finalistas do turno, separados em prontos (credenciados e checados) e
     * o que falta a cada um.
     *
     * @return array{prontos: Collection<int, Projeto>, todos: Collection<int, Projeto>, sem_credenciamento: int, sem_checagem: int, credenciados: array<int, true>, checados: array<int, true>}
     */
    public function projetosDoTurno(Turno $turno, bool $demo, bool $comTodos = false): array
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $demo);
        $vazio = ['prontos' => collect(), 'todos' => collect(), 'sem_credenciamento' => 0, 'sem_checagem' => 0, 'credenciados' => [], 'checados' => []];

        if ($lista === null) {
            return $vazio;
        }

        $ids = $lista->projetos()->pluck('projetos.id')->all();
        $turnos = $this->turnosDe($ids);
        $doTurno = array_keys(array_filter($turnos, fn (Turno $t) => $t === $turno));

        if ($doTurno === []) {
            return $vazio;
        }

        $credenciados = array_fill_keys(Credenciamento::whereIn('projeto_id', $doTurno)
            ->whereNotNull('finalizado_em')->pluck('projeto_id')->all(), true);
        $checados = array_fill_keys(ChecagemEstande::where('demo', $demo)->whereIn('projeto_id', $doTurno)
            ->whereNotNull('verificado_em')->pluck('projeto_id')->all(), true);

        $todos = Projeto::whereIn('id', $doTurno)
            ->with(['area:id,nome,grupo_correlato', 'subarea:id,nome'])
            ->orderBy('titulo')
            ->get(['id', 'titulo', 'area_id', 'subarea_id', 'categoria']);

        $prontos = $todos->filter(fn (Projeto $p) => isset($credenciados[$p->id], $checados[$p->id]))->values();

        return [
            'prontos' => $prontos,
            'todos' => $todos,
            'sem_credenciamento' => $todos->filter(fn (Projeto $p) => ! isset($credenciados[$p->id]))->count(),
            'sem_checagem' => $todos->filter(fn (Projeto $p) => isset($credenciados[$p->id]) && ! isset($checados[$p->id]))->count(),
            'credenciados' => $credenciados,
            'checados' => $checados,
        ];
    }

    /**
     * O turno de cada projeto: o da lista de turnos e, sem ela, o do estande.
     *
     * @param  list<int>  $ids
     * @return array<int, Turno>
     */
    public function turnosDe(array $ids): array
    {
        $edicaoId = Edicao::atual()?->id;
        $turnos = [];

        foreach (EstandeProjeto::where('edicao_id', $edicaoId)->whereIn('projeto_id', $ids)->get(['projeto_id', 'turno']) as $e) {
            $turnos[$e->projeto_id] = $e->turno;
        }

        foreach (TurnoApresentacao::where('edicao_id', $edicaoId)->whereIn('projeto_id', $ids)->get(['projeto_id', 'turno']) as $t) {
            $turnos[$t->projeto_id] = $t->turno;
        }

        return array_filter($turnos, fn ($t) => $t instanceof Turno);
    }

    /**
     * Quantas avaliações ocupam vaga em cada projeto: as enviadas e as que
     * ainda estão no prazo.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    public function ocupacao(array $ids, ?JanelaTurnos $janela = null): array
    {
        $janela ??= JanelaTurnos::daEdicao();
        $contagem = [];

        foreach (AvaliacaoPresencial::whereIn('projeto_id', $ids)->get() as $a) {
            if ($a->ocupaVaga($janela)) {
                $contagem[$a->projeto_id] = ($contagem[$a->projeto_id] ?? 0) + 1;
            }
        }

        return $contagem;
    }

    /**
     * As vagas ocupadas em cada projeto, separadas em comuns e da comissão
     * especial (Sprint 222) — a mesma conta de {@see ocupacao()}.
     *
     * @param  list<int>  $ids
     * @return array<int, array{comum: int, comite: int}>
     */
    public function ocupacaoPorVaga(array $ids, ?JanelaTurnos $janela = null): array
    {
        $janela ??= JanelaTurnos::daEdicao();
        $contagem = [];

        foreach (AvaliacaoPresencial::whereIn('projeto_id', $ids)->get() as $a) {
            if ($a->ocupaVaga($janela)) {
                $contagem[$a->projeto_id] ??= ['comum' => 0, 'comite' => 0];
                $contagem[$a->projeto_id][($a->vaga ?? VagaPresencial::Comum)->value]++;
            }
        }

        return $contagem;
    }

    /** O avaliador é da comissão especial? É o que decide a vaga que ele ocupa. */
    public static function daComissao(User $avaliador): bool
    {
        return (bool) $avaliador->avaliadorProfile?->comissao_especial;
    }

    // --- Internos ----------------------------------------------------------

    /**
     * O resumo da ocorrência, os projetos do turno e as designações dela.
     *
     * @param  array<string, mixed>  $foco
     * @return array<string, mixed>
     */
    private function situacao(array $foco, bool $demo): array
    {
        $turno = Turno::from($foco['turno']);
        $janela = JanelaTurnos::daEdicao();
        $projetos = $this->projetosDoTurno($turno, $demo, comTodos: true);
        $todos = $projetos['todos'];
        $ids = $todos->pluck('id')->all();
        $ocupacao = $this->ocupacao($ids, $janela);
        $porVaga = $this->ocupacaoPorVaga($ids, $janela);
        $estandes = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)->whereIn('projeto_id', $ids)->pluck('numero', 'projeto_id');
        $teto = $janela->porProjeto();

        $designacoes = AvaliacaoPresencial::query()
            ->whereDate('dia', $foco['dia'])
            ->where('turno', $foco['turno'])
            ->whereIn('projeto_id', $ids)
            ->with(['projeto:id,titulo', 'avaliador:id,name'])
            ->orderBy('avaliador_id')
            ->orderBy('id')
            ->get();

        return [
            'resumo' => [
                'projetos' => $todos->count(),
                'prontos' => $projetos['prontos']->count(),
                'sem_credenciamento' => $projetos['sem_credenciamento'],
                'sem_checagem' => $projetos['sem_checagem'],
                'cobertos' => $projetos['prontos']->filter(fn (Projeto $p) => ($ocupacao[$p->id] ?? 0) >= $teto)->count(),
                'designacoes' => $designacoes->count(),
                'concluidas' => $designacoes->filter->concluida()->count(),
                'teto' => $teto,
            ],
            'projetos' => $todos->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'estande' => $estandes[$p->id] ?? null,
                'credenciado' => isset($projetos['credenciados'][$p->id]),
                'checado' => isset($projetos['checados'][$p->id]),
                'pronto' => isset($projetos['credenciados'][$p->id], $projetos['checados'][$p->id]),
                'avaliacoes' => (int) ($ocupacao[$p->id] ?? 0),
                'comuns' => (int) ($porVaga[$p->id]['comum'] ?? 0),
                'comite' => (int) ($porVaga[$p->id]['comite'] ?? 0),
            ])->values()->all(),
            'designacoes' => $designacoes->map(fn (AvaliacaoPresencial $a) => [
                'id' => $a->id,
                'projeto_id' => $a->projeto_id,
                'projeto' => $a->projeto?->titulo,
                'estande' => $estandes[$a->projeto_id] ?? null,
                'avaliador_id' => $a->avaliador_id,
                'avaliador' => $a->avaliador?->name,
                'status' => $a->status?->value,
                'status_label' => $a->expirada($janela) ? 'Prazo encerrado' : $a->status?->label(),
                'expirada' => $a->expirada($janela),
                'designacao_manual' => $a->designacao_manual,
                'vaga' => $a->vaga?->value,
                'vaga_label' => $a->vaga?->label(),
                'nota' => $a->nota,
            ])->values()->all(),
        ];
    }

    /**
     * As rodadas iguais: a cada volta, quem está com menos recebe mais um,
     * pela prioridade de área, até a fila de todos encher ou acabar o que dar.
     *
     * @param  Collection<int, User>  $avaliadores
     * @param  Collection<int, Projeto>  $prontos
     */
    private function rodadas(Collection $avaliadores, Collection $prontos, string $dia, Turno $turno, JanelaTurnos $janela): int
    {
        if ($avaliadores->isEmpty() || $prontos->isEmpty()) {
            return 0;
        }

        $fila = $janela->fila();
        $ids = $prontos->pluck('id')->all();
        $ocupacao = $this->ocupacaoPorVaga($ids, $janela);
        $estandes = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)->whereIn('projeto_id', $ids)->pluck('numero', 'projeto_id');

        // O que cada avaliador já tem: não recebe de novo o que avaliou ou está
        // com ele; uma linha vencida pode ser reaproveitada.
        $linhas = AvaliacaoPresencial::whereIn('avaliador_id', $avaliadores->pluck('id'))->get()->groupBy('avaliador_id');
        $carga = [];
        $bloqueados = [];
        $vencidas = [];

        foreach ($avaliadores as $u) {
            $carga[$u->id] = 0;
            foreach ($linhas[$u->id] ?? [] as $a) {
                if ($a->ocupaVaga($janela)) {
                    $bloqueados[$u->id][$a->projeto_id] = true;
                } else {
                    $vencidas[$u->id][$a->projeto_id] = $a;
                }

                // A fila é o que ele tem para fazer neste turno.
                if (! $a->concluida() && $a->ocorrencia() === $dia.'|'.$turno->value && ! $a->expirada($janela)) {
                    $carga[$u->id]++;
                }
            }
        }

        $correlatas = $this->correlatasPorArea();
        $criadas = 0;

        // Uma volta por posição da fila: quem não achou projeto numa volta
        // ainda pode achar na seguinte, quando outro já tiver saído da frente.
        for ($rodada = 1; $rodada <= $fila; $rodada++) {
            $ordem = $avaliadores->sortBy(fn (User $u) => [$carga[$u->id], mb_strtolower($u->name)]);

            foreach ($ordem as $u) {
                if ($carga[$u->id] >= $rodada) {
                    continue;
                }

                $escolhido = $this->escolher($u, $prontos, $ocupacao, $janela, $bloqueados[$u->id] ?? [], $estandes, $correlatas);

                if ($escolhido === null) {
                    continue;
                }

                $vaga = $janela->vagaPara(self::daComissao($u), $ocupacao[$escolhido->id] ?? []);
                $this->entregar($escolhido->id, $u, $dia, $turno, $vaga, manual: false, existente: $vencidas[$u->id][$escolhido->id] ?? null);
                $carga[$u->id]++;
                $ocupacao[$escolhido->id] ??= ['comum' => 0, 'comite' => 0];
                $ocupacao[$escolhido->id][$vaga->value]++;
                $bloqueados[$u->id][$escolhido->id] = true;
                $criadas++;
            }
        }

        return $criadas;
    }

    /**
     * O melhor projeto para este avaliador: subárea → área → área correlata →
     * o que sobrar; dentro da faixa, o que tem menos avaliações e, depois, o
     * menor número de estande (o corredor se percorre em ordem). Só entra
     * projeto com vaga para ele (comum, ou da comissão para quem é dela).
     *
     * @param  Collection<int, Projeto>  $prontos
     * @param  array<int, array{comum: int, comite: int}>  $ocupacao
     * @param  array<int, true>  $bloqueados
     * @param  Collection<int, int>  $estandes
     * @param  array<int, list<int>>  $correlatas
     */
    private function escolher(User $u, Collection $prontos, array $ocupacao, JanelaTurnos $janela, array $bloqueados, Collection $estandes, array $correlatas): ?Projeto
    {
        $comissao = self::daComissao($u);
        $total = fn (int $id) => array_sum($ocupacao[$id] ?? []);
        $candidatos = $prontos->filter(fn (Projeto $p) => ! isset($bloqueados[$p->id])
            && $janela->vagaPara($comissao, $ocupacao[$p->id] ?? []) !== null);

        if ($candidatos->isEmpty()) {
            return null;
        }

        $perfil = $u->avaliadorProfile;
        $areas = $perfil?->areasAtendidas() ?? [];
        $pares = $perfil?->paresAtendidos() ?? [];
        $irmas = array_values(array_unique(array_merge(...array_map(fn (int $a) => $correlatas[$a] ?? [], $areas ?: [0]))));

        $faixas = [
            fn (Projeto $p) => $p->subarea_id !== null && in_array([$p->area_id, $p->subarea_id], $pares, true),
            fn (Projeto $p) => in_array($p->area_id, $areas, true),
            fn (Projeto $p) => in_array($p->area_id, $irmas, true),
            fn (Projeto $p) => true,
        ];

        foreach ($faixas as $faixa) {
            $naFaixa = $candidatos->filter($faixa);

            if ($naFaixa->isNotEmpty()) {
                return $naFaixa
                    ->sortBy(fn (Projeto $p) => [$total($p->id), $estandes[$p->id] ?? PHP_INT_MAX, $p->id])
                    ->first();
            }
        }

        return null;
    }

    /** Grava a entrega — nova, ou revivendo uma linha vencida (com o rascunho dela). */
    private function entregar(int $projetoId, User $avaliador, string $dia, Turno $turno, VagaPresencial $vaga, bool $manual, ?AvaliacaoPresencial $existente): AvaliacaoPresencial
    {
        $avaliacao = $existente ?? new AvaliacaoPresencial([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projetoId,
            'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada,
        ]);

        $avaliacao->forceFill([
            'dia' => $dia,
            'turno' => $turno,
            'designacao_manual' => $manual,
            'escolha_livre' => false,
            'vaga' => $vaga,
        ])->save();

        return $avaliacao;
    }

    /**
     * A agenda precisa ter esta ocorrência, e ela não pode ter terminado.
     */
    private function janelaValida(string $dia, Turno $turno): JanelaTurnos
    {
        $janela = JanelaTurnos::daEdicao();

        if (! $janela->existe($dia, $turno)) {
            throw ValidationException::withMessages([
                'turno' => 'Este turno não está na agenda: confira as datas do evento e o horário dos turnos.',
            ]);
        }

        if (now()->greaterThan($janela->prazo($dia, $turno))) {
            throw ValidationException::withMessages([
                'turno' => 'Este turno já terminou — distribua no turno em andamento ou num dos próximos.',
            ]);
        }

        return $janela;
    }

    /** @return Collection<int, User> quem confirmou a avaliação presencial */
    private function avaliadoresConfirmados(bool $demo): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('is_demo', $demo)
            ->whereHas('avaliadorProfile', fn ($q) => $q->where('presencial', true))
            ->with('avaliadorProfile.areasExtras')
            ->orderBy('name')
            ->get();
    }

    /** @return array<int, list<int>> área => as irmãs dela (mesmo grupo de correlação) */
    private function correlatasPorArea(): array
    {
        $areas = Area::whereNotNull('grupo_correlato')->get(['id', 'grupo_correlato']);
        $mapa = [];

        foreach ($areas as $area) {
            $mapa[$area->id] = $areas
                ->filter(fn (Area $irma) => $irma->grupo_correlato === $area->grupo_correlato && $irma->id !== $area->id)
                ->pluck('id')
                ->all();
        }

        return $mapa;
    }
}
