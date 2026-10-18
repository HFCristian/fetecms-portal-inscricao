<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ItemConferencia;
use App\Models\ListaFinal;
use App\Models\MapaLayout;
use App\Models\Projeto;
use App\Models\User;
use App\Support\JanelaTurnos;
use App\Support\PlantaEvento;
use App\Support\RubricaPresencial;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial — **lado do avaliador** (aba "Avaliação presencial").
 *
 * Quem avalia no estande é quem **aceitou** avaliar presencialmente (aba
 * Presencial, Sprint 131), e só **durante um turno** do evento. Fora disso a
 * aba abre fechada, com o motivo — e o que ele já respondeu continua legível.
 * Até a Sprint 221 ele também precisava ser ativado na cabine da avaliação; a
 * cabine saiu.
 *
 * O que ele avalia chega por dois caminhos, e a designação é opcional:
 *
 * - a **distribuição** do turno (Sprint 170), ou a designação à mão do admin;
 * - a **escolha livre**, no mapa ou na lista: ele escolhe o turno, clica no
 *   estande e inicia. A escolha é restrita ao que a distribuição também
 *   respeita: projeto do turno em andamento, credenciado e com o estande
 *   checado, abaixo do teto de avaliações.
 *
 * Cada avaliação vale até o **fim do turno em que foi entregue + 30 minutos**:
 * a margem é para terminar o que se abriu, e nada novo começa nela.
 *
 * A avaliação segue o modelo da online (Sprint 171): um passo por seção da
 * rubrica presencial, balão de orientação, leitura do projeto, rascunho a
 * qualquer momento — e um passo a mais, o **checklist dos itens da checagem**
 * (banner, diário de bordo…), marcado presente/ausente e **sem nota**.
 *
 * A nota é **separada** da avaliação online: alimenta a premiação, não o
 * ranking que definiu a lista final.
 */
class AvaliacaoPresencialFluxoService
{
    public function __construct(
        private readonly AvaliacaoPresencialService $intencao,
        private readonly DistribuicaoPresencialService $distribuicao,
    ) {}

    /** A aba está aberta agora para este avaliador? */
    public function podeAvaliar(User $avaliador, bool $teste = false): bool
    {
        $modoTeste = $this->modoTeste($avaliador, $teste);

        return $this->intencao->aceitou($avaliador)
            && ListaFinal::vigente(Edicao::atual(), $modoTeste) !== null
            && ($modoTeste || JanelaTurnos::daEdicao()->abertas() !== []);
    }

    /** Motivo de a aba estar fechada, em palavras. */
    public function motivoFechado(User $avaliador, bool $teste = false): string
    {
        if (! $this->intencao->aceitou($avaliador)) {
            return 'Marque que quer avaliar presencialmente (aba Presencial) para receber os projetos do dia.';
        }

        $edicao = Edicao::atual();

        if (ListaFinal::vigente($edicao, $this->modoTeste($avaliador, $teste)) === null) {
            return 'A lista final ainda não foi publicada.';
        }

        $janela = JanelaTurnos::daEdicao($edicao);

        if (! $janela->configurada()) {
            return 'A organização ainda não definiu os dias e os horários dos turnos da avaliação presencial.';
        }

        $proxima = $janela->proxima();

        return $proxima !== null
            ? "A avaliação presencial abre durante os turnos do evento. Próximo turno: {$proxima['rotulo']}."
            : 'A avaliação presencial terminou: as suas avaliações ficam disponíveis para consulta.';
    }

    /**
     * O painel: o turno em que ele está, o que está com ele e o que ainda pode
     * pegar no corredor.
     *
     * @return array<string, mixed>
     */
    public function painel(User $avaliador, bool $teste = false): array
    {
        $modoTeste = $this->modoTeste($avaliador, $teste);
        $janela = JanelaTurnos::daEdicao();
        $pode = $this->podeAvaliar($avaliador, $teste);
        $turno = $pode ? $this->ocorrenciaDeTrabalho($modoTeste) : null;

        $minhas = AvaliacaoPresencial::where('avaliador_id', $avaliador->id)
            ->with(['projeto.area:id,nome', 'projeto.instituicao:id,nome'])
            ->orderByRaw("CASE status WHEN 'em_andamento' THEN 0 WHEN 'designada' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get();
        $sinais = app(SinalizacaoProjetoService::class)->para($minhas->pluck('projeto_id'));

        return [
            'aberto' => $pode,
            'motivo_fechado' => $pode ? null : $this->motivoFechado($avaliador, $teste),
            'aceitou' => $this->intencao->aceitou($avaliador),
            'modo_teste' => $modoTeste,
            'is_demo' => (bool) $avaliador->is_demo,
            'turno' => $turno,
            'horarios' => (object) $janela->horarios(),
            'margem_minutos' => JanelaTurnos::MARGEM_MINUTOS,
            'rubrica' => RubricaPresencial::paraApi(),
            'itens' => $this->catalogo(),
            'max_por_projeto' => $janela->porProjeto(),
            'minhas' => $minhas
                ->map(fn (AvaliacaoPresencial $a) => $this->linha($a, $avaliador, $modoTeste, $janela, $sinais[$a->projeto_id] ?? null))
                ->all(),
            'disponiveis' => $turno !== null || ($pode && $modoTeste)
                ? $this->disponiveis($avaliador, $turno, $modoTeste, $janela)
                : [],
            'mapa' => $this->intencao->aceitou($avaliador)
                ? $this->mapa($turno, $pode, $modoTeste, $janela, $minhas)
                : null,
        ];
    }

    /**
     * Abre (ou retoma) a avaliação de um projeto — a designada, ou uma nova
     * escolhida no corredor.
     *
     * @return array<string, mixed>
     */
    public function iniciar(User $avaliador, Projeto $projeto, bool $teste = false): array
    {
        $this->garantirAberto($avaliador, $teste);
        $modoTeste = $this->modoTeste($avaliador, $teste);
        $this->garantirFinalista($projeto, $modoTeste);
        $janela = JanelaTurnos::daEdicao();

        $avaliacao = AvaliacaoPresencial::where('projeto_id', $projeto->id)
            ->where('avaliador_id', $avaliador->id)
            ->first();

        if ($avaliacao?->concluida()) {
            throw ValidationException::withMessages([
                'projeto' => 'Você já avaliou este projeto.',
            ]);
        }

        // Linha vigente: é a designação dele, e só precisa do prazo dela.
        if ($avaliacao !== null && $avaliacao->ocupaVaga($janela)) {
            $this->garantirEscrita($avaliacao, $avaliador, $modoTeste, $janela);
        } else {
            $avaliacao = $this->escolherNoCorredor($avaliador, $projeto, $modoTeste, $janela, $avaliacao);
        }

        if ($avaliacao->status !== StatusAvaliacao::EmAndamento) {
            $avaliacao->status = StatusAvaliacao::EmAndamento;
        }
        $avaliacao->iniciada_em ??= now();
        $avaliacao->save();

        return $this->detalhe($avaliacao->fresh(), $avaliador, $teste);
    }

    /**
     * Salva o rascunho das respostas (o avaliador anda pelo estande e volta).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function salvarRascunho(User $avaliador, AvaliacaoPresencial $avaliacao, array $dados, bool $teste = false): array
    {
        $this->garantirDono($avaliador, $avaliacao);
        $this->garantirAberto($avaliador, $teste);
        $modoTeste = $this->modoTeste($avaliador, $teste);

        if ($avaliacao->concluida()) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta avaliação já foi enviada.',
            ]);
        }

        $this->garantirEscrita($avaliacao, $avaliador, $modoTeste, JanelaTurnos::daEdicao());

        $avaliacao->forceFill([
            'status' => StatusAvaliacao::EmAndamento,
            'iniciada_em' => $avaliacao->iniciada_em ?? now(),
            'respostas' => $this->limparRespostas($dados['respostas'] ?? []),
            'itens_conferidos' => $this->limparItens($dados['itens'] ?? []),
            'comentario' => $dados['comentario'] ?? null,
        ])->save();

        return $this->detalhe($avaliacao->fresh(), $avaliador, $teste);
    }

    /**
     * Envia a avaliação: calcula a nota no servidor, fecha e repõe a fila do
     * turno, como a reposição da avaliação online.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function concluir(User $avaliador, AvaliacaoPresencial $avaliacao, array $dados, bool $teste = false): array
    {
        $this->garantirDono($avaliador, $avaliacao);
        $this->garantirAberto($avaliador, $teste);
        $modoTeste = $this->modoTeste($avaliador, $teste);

        if ($avaliacao->concluida()) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta avaliação já foi enviada.',
            ]);
        }

        $this->garantirEscrita($avaliacao, $avaliador, $modoTeste, JanelaTurnos::daEdicao());

        $respostas = $this->limparRespostas($dados['respostas'] ?? []);
        $itens = $this->limparItens($dados['itens'] ?? []);

        // Só as perguntas que se aplicam: sem diário manuscrito, a 3.2 some.
        if (RubricaPresencial::pendentes($respostas) !== []) {
            throw ValidationException::withMessages([
                'respostas' => 'Responda todas as perguntas antes de enviar.',
            ]);
        }

        if (array_diff(array_column($this->catalogo(), 'id'), array_keys($itens)) !== []) {
            throw ValidationException::withMessages([
                'itens' => 'Marque cada item do estande como presente ou ausente antes de enviar.',
            ]);
        }

        DB::transaction(function () use ($avaliacao, $respostas, $itens, $dados) {
            $avaliacao->forceFill([
                'respostas' => $respostas,
                'itens_conferidos' => $itens,
                'comentario' => $dados['comentario'] ?? null,
                // A nota é calculada no servidor: o front só desenha a rubrica.
                'nota' => RubricaPresencial::nota($respostas),
                'status' => StatusAvaliacao::Concluida,
                'concluida_em' => now(),
            ])->save();
        });

        // Enviou uma, recebe outra — se o turno ainda está acontecendo e se ela
        // veio da distribuição. A escolha livre e a designação à mão não repõem
        // fila: num turno que a organização não distribuiu, repor criaria
        // designações que ninguém pediu.
        if ($avaliacao->dia !== null && $avaliacao->turno !== null && ! $avaliacao->escolha_livre && ! $avaliacao->designacao_manual) {
            $this->distribuicao->completarFila($avaliador, $avaliacao->dia->toDateString(), $avaliacao->turno, $modoTeste);
        }

        return $this->detalhe($avaliacao->fresh(), $avaliador, $teste);
    }

    /**
     * Uma avaliação aberta para responder (ou reler): o projeto inteiro, a
     * rubrica, o checklist e o prazo.
     *
     * @return array<string, mixed>
     */
    public function detalhe(AvaliacaoPresencial $avaliacao, ?User $avaliador = null, bool $teste = false): array
    {
        $avaliador ??= $avaliacao->avaliador;
        $janela = JanelaTurnos::daEdicao();
        $modoTeste = $this->modoTeste($avaliador, $teste);
        $avaliacao->loadMissing(['projeto.user:id,name']);
        $projeto = $avaliacao->projeto;
        $leitura = app(AvaliacaoFluxoService::class)->detalhesProjeto($projeto);
        // Os termos do finalista (responsabilidade e uso de imagem) já saem da
        // leitura em detalhesProjeto(): são documentos do evento, com dados
        // pessoais, e não fazem parte do que se avalia.

        return [
            'id' => $avaliacao->id,
            'status' => $avaliacao->status?->value,
            'status_label' => $avaliacao->expirada($janela) ? 'Prazo encerrado' : $avaliacao->status?->label(),
            'respostas' => (object) ($avaliacao->respostas ?? []),
            // Enviada com a rubrica antes do documento de 2026: a nota vale, as
            // respostas não casam com as perguntas de agora.
            'rubrica_anterior' => RubricaPresencial::deOutraRubrica($avaliacao->respostas),
            'itens' => (object) ($avaliacao->itens_conferidos ?? []),
            'comentario' => $avaliacao->comentario,
            'nota' => $avaliacao->nota,
            'nota_maxima' => RubricaPresencial::NOTA_MAXIMA,
            'concluida_em' => $avaliacao->concluida_em?->toIso8601String(),
            'prazo_label' => $this->prazoLabel($avaliacao, $janela),
            'pode_escrever' => ! $avaliacao->concluida() && $this->podeEscrever($avaliacao, $modoTeste, $janela),
            'projeto' => [
                ...$leitura,
                'escola' => $leitura['instituicao'],
                'orientador' => $projeto->user?->name,
                'local' => $this->local($projeto->id),
                // Por que o estande pode estar vazio, e o suporte combinado.
                'sinalizacoes' => app(SinalizacaoProjetoService::class)->de($projeto->id),
            ],
        ];
    }

    // --- Internos ----------------------------------------------------------

    private function modoTeste(?User $avaliador, bool $teste): bool
    {
        return $teste && (bool) $avaliador?->is_demo;
    }

    /**
     * A ocorrência em que ele pode **começar** algo agora: o turno acontecendo
     * (sem a margem). No modo de teste, a da agenda em foco — o ensaio não
     * espera o relógio.
     *
     * @return array<string, mixed>|null
     */
    private function ocorrenciaDeTrabalho(bool $modoTeste): ?array
    {
        $janela = JanelaTurnos::daEdicao();

        if ($modoTeste) {
            // Turno já encerrado daria prazo vencido a tudo que o ensaio abrir.
            $foco = $janela->emFoco();

            return $foco !== null && $foco['situacao'] !== 'encerrado' ? $foco : null;
        }

        foreach (array_reverse($janela->abertas()) as $o) {
            if ($o['situacao'] === 'em_andamento') {
                return $o;
            }
        }

        return null;
    }

    /**
     * Ele pode escrever nesta avaliação agora? No prazo da ocorrência dela.
     * Designação antiga, sem ocorrência gravada, vale em qualquer turno aberto
     * do projeto.
     */
    private function podeEscrever(AvaliacaoPresencial $avaliacao, bool $modoTeste, JanelaTurnos $janela): bool
    {
        if ($modoTeste) {
            return true;
        }

        if ($avaliacao->dia !== null && $avaliacao->turno !== null) {
            return $janela->aberta($avaliacao->dia->toDateString(), $avaliacao->turno);
        }

        $turnoDoProjeto = $this->distribuicao->turnosDe([$avaliacao->projeto_id])[$avaliacao->projeto_id] ?? null;

        foreach ($janela->abertas() as $o) {
            if ($turnoDoProjeto === null || $o['turno'] === $turnoDoProjeto->value) {
                return true;
            }
        }

        return false;
    }

    private function garantirEscrita(AvaliacaoPresencial $avaliacao, User $avaliador, bool $modoTeste, JanelaTurnos $janela): void
    {
        if (! $this->podeEscrever($avaliacao, $modoTeste, $janela)) {
            throw ValidationException::withMessages([
                'periodo' => $avaliacao->expirada($janela)
                    ? 'O prazo desta avaliação acabou com o turno (e os 30 minutos de margem).'
                    : 'Esta avaliação é de um turno que ainda não começou.',
            ]);
        }
    }

    /**
     * A escolha livre (mapa ou lista): só no turno em andamento, só projeto
     * daquele turno, credenciado, checado e abaixo do teto.
     */
    private function escolherNoCorredor(User $avaliador, Projeto $projeto, bool $modoTeste, JanelaTurnos $janela, ?AvaliacaoPresencial $vencida): AvaliacaoPresencial
    {
        $ocorrencia = $this->ocorrenciaDeTrabalho($modoTeste);

        if ($ocorrencia === null && ! $modoTeste) {
            throw ValidationException::withMessages([
                'periodo' => 'Novas avaliações só começam durante o turno — nos 30 minutos de margem, termine as que já abriu.',
            ]);
        }

        $turnoDoProjeto = $this->distribuicao->turnosDe([$projeto->id])[$projeto->id] ?? null;

        if ($ocorrencia !== null && $turnoDoProjeto?->value !== $ocorrencia['turno']) {
            throw ValidationException::withMessages([
                'projeto' => 'Este projeto não apresenta no turno em andamento.',
            ]);
        }

        $turnoBusca = $ocorrencia !== null ? Turno::from($ocorrencia['turno']) : $turnoDoProjeto;
        $prontos = $turnoBusca === null ? collect() : $this->distribuicao->projetosDoTurno($turnoBusca, $modoTeste)['prontos'];

        if (! $prontos->contains('id', $projeto->id)) {
            throw ValidationException::withMessages([
                'projeto' => 'Este projeto ainda não foi credenciado e checado.',
            ]);
        }

        // A disputa se resolve aqui: quem chega depois do teto é avisado, e o
        // projeto sai da lista dele.
        if (($this->distribuicao->ocupacao([$projeto->id], $janela)[$projeto->id] ?? 0) >= $janela->porProjeto()) {
            throw ValidationException::withMessages([
                'projeto' => 'Este projeto já recebeu o número máximo de avaliações presenciais.',
            ]);
        }

        $avaliacao = $vencida ?? new AvaliacaoPresencial([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
        ]);

        $avaliacao->forceFill([
            'dia' => $ocorrencia['dia'] ?? null,
            'turno' => $ocorrencia['turno'] ?? null,
            'designacao_manual' => false,
            'escolha_livre' => true,
        ]);

        return $avaliacao;
    }

    /**
     * O mapa do evento visto pelo avaliador: a planta e, em cada estande e
     * turno, o projeto que apresenta ali e o que ele pode fazer com ele —
     * iniciar, continuar, reler ou só saber por que não pode. É o caminho da
     * avaliação sem designação: escolher o turno, clicar no estande, iniciar.
     *
     * O estande cujo número não está na planta (o ensaio usa 901–903) vem à
     * parte, para continuar alcançável.
     *
     * @param  array<string, mixed>|null  $turno  a ocorrência em que ele pode começar algo
     * @param  Collection<int, AvaliacaoPresencial>  $minhas
     * @return array<string, mixed>|null
     */
    private function mapa(?array $turno, bool $pode, bool $modoTeste, JanelaTurnos $janela, Collection $minhas): ?array
    {
        $edicao = Edicao::atual();
        $lista = ListaFinal::vigente($edicao, $modoTeste);

        if ($lista === null) {
            return null;
        }

        $planta = MapaLayout::vigente($edicao)?->dados ?? PlantaEvento::padrao();
        $layout = array_map(
            fn (array $e) => ['numero' => (int) $e['numero'], 'x' => $e['x'], 'y' => $e['y']],
            $planta['estandes'] ?? [],
        );
        $naPlanta = array_fill_keys(array_column($layout, 'numero'), true);

        $estandes = EstandeProjeto::where('edicao_id', $edicao?->id)
            ->whereIn('projeto_id', $lista->projetos()->pluck('projetos.id'))
            ->orderBy('numero')
            ->get(['projeto_id', 'turno', 'numero']);
        $ids = $estandes->pluck('projeto_id')->unique()->values()->all();
        $projetos = Projeto::whereIn('id', $ids)->with('area:id,nome')->get(['id', 'titulo', 'area_id', 'categoria'])->keyBy('id');

        $prontos = [];
        foreach (Turno::cases() as $t) {
            foreach ($this->distribuicao->projetosDoTurno($t, $modoTeste)['prontos'] as $p) {
                $prontos[$p->id] = true;
            }
        }

        $ocupacao = $this->distribuicao->ocupacao($ids, $janela);
        $teto = $janela->porProjeto();
        $minhasPorProjeto = $minhas->keyBy('projeto_id');
        // No ensaio sem agenda, qualquer turno vale (como na escolha livre).
        $trabalho = $turno['turno'] ?? null;
        $qualquerTurno = $modoTeste && $trabalho === null;

        $porEstande = [];
        $fora = [];

        foreach ($estandes as $e) {
            $projeto = $projetos[$e->projeto_id] ?? null;

            if ($projeto === null || $e->turno === null) {
                continue;
            }

            $minha = $minhasPorProjeto[$projeto->id] ?? null;
            $avaliacoes = (int) ($ocupacao[$projeto->id] ?? 0);

            $situacao = match (true) {
                (bool) $minha?->concluida() => 'avaliado',
                $minha !== null && $minha->ocupaVaga($janela) => 'comigo',
                ! isset($prontos[$projeto->id]) => 'nao_pronto',
                $avaliacoes >= $teto => 'completo',
                ! $pode || (! $qualquerTurno && $trabalho !== $e->turno->value) => 'fora_do_turno',
                default => 'disponivel',
            };

            $linha = [
                'projeto_id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'area' => $projeto->area?->nome,
                'categoria' => $projeto->categoria?->label(),
                'avaliacoes' => $avaliacoes,
                'situacao' => $situacao,
                'avaliacao_id' => in_array($situacao, ['avaliado', 'comigo'], true) ? $minha->id : null,
            ];

            if (isset($naPlanta[$e->numero])) {
                $porEstande[$e->numero][$e->turno->value] = $linha;
            } else {
                $fora[] = [...$linha, 'numero' => $e->numero, 'turno' => $e->turno->value];
            }
        }

        return [
            'layout' => $layout,
            'estandes' => (object) $porEstande,
            'fora_da_planta' => $fora,
            'turno_trabalho' => $trabalho,
            'max_por_projeto' => $teto,
        ];
    }

    /**
     * Os projetos que ainda cabem mais um avaliador, no turno em que ele está:
     * credenciados, checados, abaixo do teto e que ele ainda não pegou.
     *
     * @param  array<string, mixed>|null  $ocorrencia
     * @return list<array<string, mixed>>
     */
    private function disponiveis(User $avaliador, ?array $ocorrencia, bool $modoTeste, JanelaTurnos $janela): array
    {
        $turnos = $ocorrencia !== null ? [Turno::from($ocorrencia['turno'])] : Turno::cases();
        $prontos = collect($turnos)->flatMap(fn (Turno $t) => $this->distribuicao->projetosDoTurno($t, $modoTeste)['prontos']);

        if ($prontos->isEmpty()) {
            return [];
        }

        $ids = $prontos->pluck('id')->all();
        $ocupacao = $this->distribuicao->ocupacao($ids, $janela);
        $comigo = AvaliacaoPresencial::where('avaliador_id', $avaliador->id)->get()
            ->filter(fn (AvaliacaoPresencial $a) => $a->ocupaVaga($janela))
            ->pluck('projeto_id')
            ->all();
        $teto = $janela->porProjeto();
        $sinais = app(SinalizacaoProjetoService::class)->para($ids);
        $estandes = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)->whereIn('projeto_id', $ids)->pluck('numero', 'projeto_id');
        $turnosDosProjetos = $this->distribuicao->turnosDe($ids);

        return Projeto::whereIn('id', $ids)
            ->whereNotIn('id', $comigo)
            ->with(['area:id,nome', 'instituicao:id,nome'])
            ->get()
            ->filter(fn (Projeto $p) => (int) ($ocupacao[$p->id] ?? 0) < $teto)
            ->sortBy(fn (Projeto $p) => [$estandes[$p->id] ?? PHP_INT_MAX, $p->titulo])
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'escola' => $p->instituicao?->nome,
                'local' => [
                    'estande' => $estandes[$p->id] ?? null,
                    'turno_label' => ($turnosDosProjetos[$p->id] ?? null)?->label(),
                ],
                'avaliacoes' => (int) ($ocupacao[$p->id] ?? 0),
                'sinalizacoes' => $sinais[$p->id] ?? null,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function linha(AvaliacaoPresencial $avaliacao, User $avaliador, bool $modoTeste, JanelaTurnos $janela, ?array $sinais): array
    {
        $projeto = $avaliacao->projeto;
        $expirada = $avaliacao->expirada($janela);

        return [
            'id' => $avaliacao->id,
            'projeto_id' => $projeto?->id,
            'titulo' => $projeto?->titulo,
            'area' => $projeto?->area?->nome,
            'escola' => $projeto?->instituicao?->nome,
            'local' => $projeto === null ? null : $this->local($projeto->id),
            'sinalizacoes' => $sinais,
            'status' => $avaliacao->status?->value,
            'status_label' => $expirada ? 'Prazo encerrado' : $avaliacao->status?->label(),
            'expirada' => $expirada,
            'prazo_label' => $this->prazoLabel($avaliacao, $janela),
            'pode_escrever' => ! $avaliacao->concluida() && $this->podeEscrever($avaliacao, $modoTeste, $janela),
            'designacao_manual' => $avaliacao->designacao_manual,
            'nota' => $avaliacao->nota,
            'nota_maxima' => RubricaPresencial::NOTA_MAXIMA,
            'concluida_em' => $avaliacao->concluida_em?->toIso8601String(),
        ];
    }

    private function prazoLabel(AvaliacaoPresencial $avaliacao, JanelaTurnos $janela): ?string
    {
        return $avaliacao->prazo($janela)?->format('d/m H:i');
    }

    /**
     * O catálogo da checagem de estandes, ativo — o checklist do avaliador.
     *
     * @return list<array{id: int, nome: string, descricao: ?string}>
     */
    private function catalogo(): array
    {
        // Os itens da checagem que são do projeto (a lista única, Sprint 181):
        // o avaliador no estande não confere documento de cada pessoa, nem o
        // que o orientador manda antes pelo portal — isso é da organização.
        return ItemConferencia::naChecagem()
            ->where('abrangencia', ItemConferencia::PROJETO)
            ->where('receber_antes', false)
            ->get(['id', 'nome', 'descricao'])
            ->map(fn (ItemConferencia $i) => ['id' => $i->id, 'nome' => $i->nome, 'descricao' => $i->descricao])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function local(int $projetoId): array
    {
        $estande = EstandeProjeto::where('projeto_id', $projetoId)->first();
        $turno = $this->distribuicao->turnosDe([$projetoId])[$projetoId] ?? null;

        return [
            'estande' => $estande?->numero,
            'turno_label' => $turno?->label(),
        ];
    }

    /**
     * Só o que a rubrica conhece, só valores que cada pergunta aceita e nada
     * nas condicionais que deixaram de se aplicar.
     *
     * @param  array<string, mixed>  $respostas
     * @return array<string, int|string>
     */
    private function limparRespostas(array $respostas): array
    {
        return RubricaPresencial::normalizar($respostas);
    }

    /**
     * Só itens do catálogo ativo, e só presente/ausente.
     *
     * @param  array<int|string, mixed>  $itens
     * @return array<int, string>
     */
    private function limparItens(array $itens): array
    {
        $validos = array_column($this->catalogo(), 'id');
        $limpos = [];

        foreach ($itens as $id => $situacao) {
            if (in_array((int) $id, $validos, true) && in_array($situacao, ['presente', 'ausente'], true)) {
                $limpos[(int) $id] = $situacao;
            }
        }

        return $limpos;
    }

    private function garantirAberto(User $avaliador, bool $teste): void
    {
        if (! $this->podeAvaliar($avaliador, $teste)) {
            throw ValidationException::withMessages([
                'periodo' => $this->motivoFechado($avaliador, $teste),
            ]);
        }
    }

    private function garantirDono(User $avaliador, AvaliacaoPresencial $avaliacao): void
    {
        abort_unless($avaliacao->avaliador_id === $avaliador->id, 403);
    }

    private function garantirFinalista(Projeto $projeto, bool $demo): void
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $demo);

        if ($lista === null || ! $lista->projetos()->whereKey($projeto->id)->exists()) {
            abort(404);
        }
    }
}
