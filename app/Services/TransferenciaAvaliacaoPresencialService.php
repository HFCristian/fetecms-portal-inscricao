<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Enums\VagaPresencial;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\JanelaTurnos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ver avaliações → **Transferir** (Sprint 249): o avaliador se confundiu de
 * estande e lançou a avaliação no projeto errado. A organização a muda para o
 * projeto certo, com tudo o que ele respondeu — o que estava errado era o
 * lugar, não a avaliação.
 *
 * - O destino é **qualquer finalista com estande** da mesma lista (a oficial,
 *   ou a de demonstração no modo de teste), nos dois turnos: ele pode ter
 *   escolhido o turno errado no mapa. A avaliação passa para o turno do
 *   estande de destino; o dia fica.
 * - A **vaga é refeita** no destino pela regra de sempre
 *   ({@see JanelaTurnos::vagaPara()}). Sem vaga sobrando, a transferência
 *   acontece assim mesmo, **acima do teto** e com aviso, como a designação
 *   manual — a avaliação existe, e recusá-la deixaria o erro no lugar.
 * - O mesmo avaliador não pode ter duas avaliações do projeto: se ele já
 *   enviou ou está preenchendo a do destino, a transferência é recusada; a
 *   designação que ele ainda não abriu (ou a aberta que venceu) dá lugar à
 *   transferida.
 * - Justificativa obrigatória; o registro fica em Registros → Notas, no
 *   projeto de origem.
 */
class TransferenciaAvaliacaoPresencialService
{
    private const LIMITE_DESTINOS = 30;

    public function __construct(
        private readonly NotasPresenciaisService $notas,
        private readonly LocaisFinalistasService $locais,
        private readonly DistribuicaoPresencialService $distribuicao,
        private readonly RegistroAtividadeService $registros,
        private readonly ListaFinalService $listas,
    ) {}

    /**
     * Os projetos para onde a avaliação pode ir, com a vaga que ela ocuparia
     * em cada um. Sem busca, os do turno da avaliação primeiro, por estande.
     *
     * @return array{destinos: list<array<string, mixed>>, total: int, limite: int}
     */
    public function destinos(AvaliacaoPresencial $avaliacao, ?string $busca, bool $demo): array
    {
        $this->garantirTransferivel($avaliacao);
        $origem = $avaliacao->projeto;
        $lista = $this->notas->listaDoProjeto($origem, $demo);
        $edicao = Edicao::atual();

        $estandes = EstandeProjeto::where('edicao_id', $edicao?->id)
            ->whereIn('projeto_id', $lista->projetos()->pluck('projetos.id'))
            ->where('projeto_id', '!=', $origem->id)
            ->with(['projeto:id,titulo,area_id,categoria', 'projeto.area:id,nome'])
            ->get()
            ->filter(fn (EstandeProjeto $e) => $e->projeto !== null)
            ->keyBy('projeto_id');

        $locais = array_intersect_key($this->locais->todos($lista), $estandes->all());
        $termo = Str::lower(Str::ascii(trim((string) $busca)));

        if ($termo !== '') {
            $porLocal = array_flip($this->locais->casamBusca($locais, $termo));
            $estandes = $estandes->filter(fn (EstandeProjeto $e) => isset($porLocal[$e->projeto_id])
                || str_contains(Str::lower(Str::ascii($e->projeto->titulo)), $termo));
        }

        $turnoDaAvaliacao = $avaliacao->turno?->value;
        $ordenados = $estandes->sortBy(fn (EstandeProjeto $e) => [$e->turno?->value === $turnoDaAvaliacao ? 0 : 1, $e->numero])->values();
        $pagina = $ordenados->take(self::LIMITE_DESTINOS);

        $ids = $pagina->pluck('projeto_id')->all();
        $janela = JanelaTurnos::daEdicao();
        $ocupadas = $this->distribuicao->ocupacaoPorVaga($ids, $janela);
        $doAvaliador = AvaliacaoPresencial::where('avaliador_id', $avaliacao->avaliador_id)
            ->whereIn('projeto_id', $ids)
            ->get()
            ->keyBy('projeto_id');
        $comissao = DistribuicaoPresencialService::daComissao($avaliacao->avaliador);

        return [
            'destinos' => $pagina->map(function (EstandeProjeto $e) use ($locais, $ocupadas, $doAvaliador, $comissao, $janela) {
                // A designação do avaliador para o destino sai na transferência:
                // ela não conta como vaga ocupada.
                $existente = $doAvaliador->get($e->projeto_id);
                $ocupacao = $ocupadas[$e->projeto_id] ?? ['comum' => 0, 'comite' => 0];
                if ($existente !== null && $existente->ocupaVaga($janela) && $this->bloqueio($existente, $janela) === null) {
                    $ocupacao[($existente->vaga ?? VagaPresencial::Comum)->value]--;
                }
                $livre = $janela->vagaPara($comissao, $ocupacao);
                $vaga = $livre ?? $janela->vagaForcada($comissao, $ocupacao);

                return [
                    'projeto_id' => $e->projeto_id,
                    'titulo' => $e->projeto->titulo,
                    'codigo' => $locais[$e->projeto_id]['codigo'] ?? null,
                    'area' => $e->projeto->area?->nome,
                    'categoria' => $e->projeto->categoria?->label(),
                    'estande' => str_pad((string) $e->numero, 3, '0', STR_PAD_LEFT),
                    'turno' => $e->turno?->value,
                    'turno_label' => $e->turno?->label(),
                    'ocupadas' => ['comum' => max(0, $ocupacao['comum']), 'comite' => max(0, $ocupacao['comite'])],
                    'vagas' => ['comuns' => $janela->comunsPorProjeto(), 'comite' => $janela->comitePorProjeto()],
                    'vaga' => $vaga->value,
                    'vaga_label' => $vaga->label(),
                    'acima_do_teto' => $livre === null,
                    'bloqueio' => $existente === null ? null : $this->bloqueio($existente, $janela),
                ];
            })->all(),
            'total' => $ordenados->count(),
            'limite' => self::LIMITE_DESTINOS,
        ];
    }

    /**
     * Muda a avaliação para o projeto de destino e devolve a tela do projeto
     * de origem (já sem ela), o destino e o aviso de teto, se houver.
     *
     * @return array<string, mixed>
     */
    public function transferir(AvaliacaoPresencial $avaliacao, Projeto $destino, User $admin, string $justificativa, bool $demo): array
    {
        $this->garantirTransferivel($avaliacao);
        $origem = $avaliacao->projeto;
        $lista = $this->notas->listaDoProjeto($origem, $demo);

        if ($destino->id === $origem->id) {
            throw ValidationException::withMessages(['projeto_id' => 'A avaliação já está neste projeto. Escolha outro.']);
        }

        if (! $lista->projetos()->where('projetos.id', $destino->id)->exists()) {
            throw ValidationException::withMessages(['projeto_id' => 'O projeto de destino não está na lista final que vale para o evento.']);
        }

        $estande = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)->where('projeto_id', $destino->id)->first();

        if ($estande === null) {
            throw ValidationException::withMessages(['projeto_id' => 'O projeto de destino ainda não tem estande no Mapa do Evento.']);
        }

        $janela = JanelaTurnos::daEdicao();
        $nome = $avaliacao->avaliador?->name ?? 'Avaliador #'.$avaliacao->avaliador_id;

        $resultado = DB::transaction(function () use ($avaliacao, $destino, $origem, $estande, $lista, $admin, $justificativa, $janela, $nome) {
            $existente = AvaliacaoPresencial::where('projeto_id', $destino->id)
                ->where('avaliador_id', $avaliacao->avaliador_id)
                ->lockForUpdate()
                ->first();

            if ($existente !== null) {
                $bloqueio = $this->bloqueio($existente, $janela);

                if ($bloqueio !== null) {
                    throw ValidationException::withMessages(['projeto_id' => "{$nome} {$bloqueio}. Não dá para transferir esta avaliação para lá."]);
                }

                // A designação que ele não abriu (ou a aberta que venceu) dá
                // lugar à avaliação que de fato aconteceu.
                $existente->delete();
            }

            $ocupadas = $this->distribuicao->ocupacaoPorVaga([$destino->id], $janela)[$destino->id] ?? ['comum' => 0, 'comite' => 0];
            $comissao = DistribuicaoPresencialService::daComissao($avaliacao->avaliador);
            $vaga = $janela->vagaPara($comissao, $ocupadas);
            $acimaDoTeto = $vaga === null;
            $vaga ??= $janela->vagaForcada($comissao, $ocupadas);
            $vagaAntes = $avaliacao->vaga ?? VagaPresencial::Comum;

            $avaliacao->update([
                'projeto_id' => $destino->id,
                'turno' => $estande->turno?->value ?? $avaliacao->turno?->value,
                'vaga' => $vaga->value,
            ]);

            $rotuloDestino = $this->rotulo($destino, $estande, $lista);

            $this->registros->avaliacaoPresencialTransferida($origem, $admin, [
                'avaliador' => $nome,
                'nota' => $avaliacao->concluida() ? $avaliacao->nota : null,
                'situacao' => $avaliacao->concluida() ? 'enviada' : 'em andamento',
                'vaga_de' => $vagaAntes->label(),
                'vaga_para' => $vaga->label(),
                'destino' => $rotuloDestino,
                'acima_do_teto' => $acimaDoTeto,
            ], $justificativa);

            return ['destino' => $rotuloDestino, 'vaga' => $vaga, 'acima_do_teto' => $acimaDoTeto];
        });

        return [
            'origem' => $this->notas->doProjeto($origem, $admin, $demo, registrar: false),
            'destino' => [
                'projeto_id' => $destino->id,
                'rotulo' => $resultado['destino'],
                'vaga_label' => $resultado['vaga']->label(),
            ],
            'aviso' => $resultado['acima_do_teto']
                ? "O projeto de destino já não tinha vaga para {$nome}: a avaliação entrou acima do teto, como {$resultado['vaga']->label()}."
                : null,
            'mensagem' => "Avaliação de {$nome} transferida para {$resultado['destino']} ({$resultado['vaga']->label()}).",
        ];
    }

    /** Só a enviada e a em andamento se transferem; a só designada sai na Distribuição presencial. */
    private function garantirTransferivel(AvaliacaoPresencial $avaliacao): void
    {
        $avaliacao->loadMissing(['projeto', 'avaliador.avaliadorProfile']);
        abort_if($avaliacao->projeto === null, 404);

        if (! in_array($avaliacao->status, [StatusAvaliacao::Concluida, StatusAvaliacao::EmAndamento], true)) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta avaliação ainda não foi aberta — a designação é retirada na Distribuição presencial.',
            ]);
        }
    }

    /**
     * Por que o avaliador não pode receber a transferência neste projeto, ou
     * null: ele já enviou a avaliação de lá, ou está preenchendo agora.
     */
    private function bloqueio(AvaliacaoPresencial $existente, JanelaTurnos $janela): ?string
    {
        return match (true) {
            $existente->concluida() => 'já enviou uma avaliação deste projeto',
            $existente->status === StatusAvaliacao::EmAndamento && $existente->ocupaVaga($janela) => 'está avaliando este projeto agora',
            default => null,
        };
    }

    /** "estande 042 · Turno A · FET.AGR-002 · Título". */
    private function rotulo(Projeto $projeto, EstandeProjeto $estande, ListaFinal $lista): string
    {
        $codigo = $this->listas->codigosDaLista($lista)[$projeto->id] ?? null;

        return implode(' · ', array_filter([
            'estande '.str_pad((string) $estande->numero, 3, '0', STR_PAD_LEFT),
            $estande->turno instanceof Turno ? 'Turno '.$estande->turno->value : null,
            $codigo,
            $projeto->titulo,
        ]));
    }
}
