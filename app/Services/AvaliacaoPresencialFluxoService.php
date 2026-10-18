<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
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
 * Antes, a organização precisa tê-lo **liberado** (Validação de avaliadores,
 * Sprint 223): uma vez, para o evento inteiro — a cabine por turno saiu na
 * Sprint 221.
 *
 * O que ele avalia chega por dois caminhos, e a designação é opcional:
 *
 * - a **distribuição** do turno (Sprint 170), ou a designação à mão do admin;
 * - a **escolha livre**, no mapa ou na lista: ele escolhe o turno, clica no
 *   estande e inicia. A escolha é restrita ao que a distribuição também
 *   respeita: projeto do turno em andamento, credenciado e com o estande
 *   checado, com vaga — comum, ou da comissão especial para quem é dela
 *   (Sprint 222).
 *
 * Cada avaliação vale até o **fim do horário de avaliação** do turno em que foi
 * entregue (Parametrização → Datas e períodos, Sprint 224). O que passa do fim
 * do turno é margem, para terminar o que se abriu — nada novo começa nela.
 *
 * A avaliação segue o modelo da online (Sprint 171): um passo por seção da
 * rubrica presencial, balão de orientação, leitura do projeto e rascunho a
 * qualquer momento. O checklist dos itens do estande que abria o wizard saiu
 * (Sprint 237): o que o estande tem é conferido na checagem, pela organização,
 * e a coluna `itens_conferidos` fica só com o que foi marcado antes disso.
 *
 * A nota é **separada** da avaliação online: alimenta a premiação, não o
 * ranking que definiu a lista final.
 *
 * Com a regra "avaliar apenas projetos designados" ligada (Sprint 234), o
 * avaliador da **comissão especial** só escolhe o que é dele; fora disso, pede
 * permissão ({@see PermissaoComiteService}).
 */
class AvaliacaoPresencialFluxoService
{
    public function __construct(
        private readonly AvaliacaoPresencialService $intencao,
        private readonly DistribuicaoPresencialService $distribuicao,
        private readonly LiberacaoPresencialService $liberacao,
        private readonly PermissaoComiteService $permissoes,
    ) {}

    /** A aba está aberta agora para este avaliador? */
    public function podeAvaliar(User $avaliador, bool $teste = false): bool
    {
        $modoTeste = $this->modoTeste($avaliador, $teste);

        return $this->intencao->aceitou($avaliador)
            && ($modoTeste || $this->liberacao->liberado($avaliador))
            && ListaFinal::vigente(Edicao::atual(), $modoTeste) !== null
            && ($modoTeste || JanelaTurnos::daEdicao()->abertas() !== []);
    }

    /** Motivo de a aba estar fechada, em palavras. */
    public function motivoFechado(User $avaliador, bool $teste = false): string
    {
        if (! $this->intencao->aceitou($avaliador)) {
            return 'Marque que quer avaliar presencialmente (aba Presencial) para receber os projetos do dia.';
        }

        if (! $this->modoTeste($avaliador, $teste) && ! $this->liberacao->liberado($avaliador)) {
            return 'Sua participação na avaliação presencial ainda não foi liberada pela organização: procure a equipe da avaliação presencial no evento.';
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
            'liberado' => $this->liberacao->liberado($avaliador),
            'modo_teste' => $modoTeste,
            'is_demo' => (bool) $avaliador->is_demo,
            'turno' => $turno,
            'rubrica' => RubricaPresencial::paraApi(),
            'max_por_projeto' => $janela->porProjeto(),
            // As vagas de cada projeto e o peso da comissão (Sprint 222): quem é
            // da comissão precisa saber que pesa mais — e quando não pesa.
            'vagas' => [
                'comuns' => $janela->comunsPorProjeto(),
                'comite' => $janela->comitePorProjeto(),
                'peso_comite' => $janela->pesoComite(),
            ],
            'comissao' => DistribuicaoPresencialService::daComissao($avaliador),
            // A regra do comitê (Sprint 234): só o designado, o resto por pedido.
            'so_designados' => $this->permissoes->seAplica($avaliador),
            'pedidos' => $this->permissoes->pedidosDe($avaliador)
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'projeto_id' => $p->projeto_id,
                    'titulo' => $p->projeto?->titulo,
                    'status' => $p->status->value,
                    'status_label' => $p->status->label(),
                    'resposta' => $p->resposta,
                ])
                ->values()
                ->all(),
            'minhas' => $minhas
                ->map(fn (AvaliacaoPresencial $a) => $this->linha($a, $avaliador, $modoTeste, $janela, $sinais[$a->projeto_id] ?? null))
                ->all(),
            // Os finalistas designados a ele em Comitê → Designações que ainda
            // não viraram avaliação no estande (Sprint 235): a seção "Designados
            // a você" mostra os dois caminhos juntos.
            'designados_comite' => $this->designadosDoComite($avaliador, $modoTeste, $minhas),
            'disponiveis' => $turno !== null || ($pode && $modoTeste)
                ? $this->disponiveis($avaliador, $turno, $modoTeste, $janela)
                : [],
            'mapa' => $this->intencao->aceitou($avaliador)
                ? $this->mapa($avaliador, $turno, $pode, $modoTeste, $janela, $minhas)
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
     * Com a regra do comitê ligada, pede à organização para avaliar um projeto
     * fora das designações (Sprint 234). Não depende do turno: dá para pedir
     * antes, e a permissão vale para quando o projeto estiver pronto.
     *
     * @return array<string, mixed>
     */
    public function pedirPermissao(User $avaliador, Projeto $projeto, ?string $justificativa, bool $teste = false): array
    {
        if (! $this->intencao->aceitou($avaliador)) {
            throw ValidationException::withMessages([
                'permissao' => 'Marque que quer avaliar presencialmente (aba Presencial) antes de pedir.',
            ]);
        }

        $this->garantirFinalista($projeto, $this->modoTeste($avaliador, $teste));
        $pedido = $this->permissoes->solicitar($avaliador, $projeto, $justificativa);

        return ['id' => $pedido->id, 'status' => $pedido->status->value, 'status_label' => $pedido->status->label()];
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

        // Só as perguntas que se aplicam: sem diário manuscrito, a 3.2 some.
        if (RubricaPresencial::pendentes($respostas) !== []) {
            throw ValidationException::withMessages([
                'respostas' => 'Responda todas as perguntas antes de enviar.',
            ]);
        }

        DB::transaction(function () use ($avaliacao, $respostas, $dados) {
            $avaliacao->forceFill([
                'respostas' => $respostas,
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
     * rubrica e o prazo.
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
            'comentario' => $avaliacao->comentario,
            'vaga' => $avaliacao->vaga?->value,
            'vaga_label' => $avaliacao->vaga?->label(),
            'nota' => $avaliacao->nota,
            'nota_maxima' => RubricaPresencial::NOTA_MAXIMA,
            'concluida_em' => $avaliacao->concluida_em?->toIso8601String(),
            'prazo_label' => $this->prazoLabel($avaliacao, $janela),
            'pode_escrever' => ! $avaliacao->concluida() && $this->podeEscrever($avaliacao, $avaliador, $modoTeste, $janela),
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
     * (dentro do horário de avaliação, sem a margem). No modo de teste, a da agenda em foco — o ensaio não
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
     * Ele pode escrever nesta avaliação agora? Liberado e no prazo da
     * ocorrência dela. Designação antiga, sem ocorrência gravada, vale em
     * qualquer turno aberto do projeto.
     */
    private function podeEscrever(AvaliacaoPresencial $avaliacao, User $avaliador, bool $modoTeste, JanelaTurnos $janela): bool
    {
        if ($modoTeste) {
            return true;
        }

        // Revogada a liberação, o que ele abriu fica em leitura.
        if (! $this->liberacao->liberado($avaliador)) {
            return false;
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
        if (! $this->podeEscrever($avaliacao, $avaliador, $modoTeste, $janela)) {
            throw ValidationException::withMessages([
                'periodo' => $avaliacao->expirada($janela)
                    ? 'O prazo desta avaliação acabou: o horário de avaliação do turno terminou.'
                    : ($modoTeste || $this->liberacao->liberado($avaliador)
                ? 'Esta avaliação é de um turno que ainda não começou.'
                : 'Sua participação na avaliação presencial não está liberada.'),
            ]);
        }
    }

    /**
     * A escolha livre (mapa ou lista): só no turno em andamento, só projeto
     * daquele turno, credenciado, checado e abaixo do teto.
     */
    private function escolherNoCorredor(User $avaliador, Projeto $projeto, bool $modoTeste, JanelaTurnos $janela, ?AvaliacaoPresencial $vencida): AvaliacaoPresencial
    {
        // O comitê com a regra ligada só escolhe o que é dele (Sprint 234).
        $this->permissoes->garantir($avaliador, $projeto);

        $ocorrencia = $this->ocorrenciaDeTrabalho($modoTeste);

        if ($ocorrencia === null && ! $modoTeste) {
            throw ValidationException::withMessages([
                'periodo' => 'Novas avaliações só começam no horário de avaliação do turno — depois do fim do turno, termine as que já abriu.',
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
        // projeto sai da lista dele. O teto é por vaga (Sprint 222): o da
        // comissão ocupa a dela e, tomada, uma comum; o comum, só uma comum.
        $vaga = $janela->vagaPara(
            DistribuicaoPresencialService::daComissao($avaliador),
            $this->distribuicao->ocupacaoPorVaga([$projeto->id], $janela)[$projeto->id] ?? [],
        );

        if ($vaga === null) {
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
            'vaga' => $vaga,
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
    private function mapa(User $avaliador, ?array $turno, bool $pode, bool $modoTeste, JanelaTurnos $janela, Collection $minhas): ?array
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
        $porVaga = $this->distribuicao->ocupacaoPorVaga($ids, $janela);
        $comissao = DistribuicaoPresencialService::daComissao($avaliador);
        $teto = $janela->porProjeto();
        $minhasPorProjeto = $minhas->keyBy('projeto_id');
        // No ensaio sem agenda, qualquer turno vale (como na escolha livre).
        $trabalho = $turno['turno'] ?? null;
        $qualquerTurno = $modoTeste && $trabalho === null;
        $liberado = $modoTeste || $this->liberacao->liberado($avaliador);
        // A regra do comitê (Sprint 234): fora das designações, pede permissão.
        $soDesignados = $this->permissoes->seAplica($avaliador);
        $designados = $soDesignados ? $this->permissoes->liberados($avaliador) : [];
        $pedidos = $soDesignados ? $this->permissoes->pedidosDe($avaliador) : collect();

        $porEstande = [];
        $fora = [];

        foreach ($estandes as $e) {
            $projeto = $projetos[$e->projeto_id] ?? null;

            if ($projeto === null || $e->turno === null) {
                continue;
            }

            $minha = $minhasPorProjeto[$projeto->id] ?? null;
            $avaliacoes = (int) ($ocupacao[$projeto->id] ?? 0);
            $permissao = $soDesignados ? $this->permissoes->situacao($projeto->id, $designados, $pedidos) : null;

            $situacao = match (true) {
                (bool) $minha?->concluida() => 'avaliado',
                $minha !== null && $minha->ocupaVaga($janela) => 'comigo',
                ! isset($prontos[$projeto->id]) => 'nao_pronto',
                $janela->vagaPara($comissao, $porVaga[$projeto->id] ?? []) === null => 'completo',
                ! $liberado => 'nao_liberado',
                $permissao === 'pendente' => 'permissao_pendente',
                $permissao !== null => 'pedir_permissao',
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
                // Recusado antes: a resposta da organização, para ele ler antes de pedir de novo.
                'permissao_resposta' => $permissao === 'recusada' ? $pedidos->get($projeto->id)?->resposta : null,
                'permissao_recusada' => $permissao === 'recusada',
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
            'so_designados' => $soDesignados,
        ];
    }

    /**
     * Os projetos que ainda cabem mais um avaliador, no turno em que ele está:
     * credenciados, checados, com vaga para ele e que ele ainda não pegou.
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
        $porVaga = $this->distribuicao->ocupacaoPorVaga($ids, $janela);
        $comissao = DistribuicaoPresencialService::daComissao($avaliador);
        $comigo = AvaliacaoPresencial::where('avaliador_id', $avaliador->id)->get()
            ->filter(fn (AvaliacaoPresencial $a) => $a->ocupaVaga($janela))
            ->pluck('projeto_id')
            ->all();
        $sinais = app(SinalizacaoProjetoService::class)->para($ids);
        $estandes = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)->whereIn('projeto_id', $ids)->pluck('numero', 'projeto_id');
        $turnosDosProjetos = $this->distribuicao->turnosDe($ids);
        $soDesignados = $this->permissoes->seAplica($avaliador);
        $designados = $soDesignados ? $this->permissoes->liberados($avaliador) : [];
        $pedidos = $soDesignados ? $this->permissoes->pedidosDe($avaliador) : collect();

        return Projeto::whereIn('id', $ids)
            ->whereNotIn('id', $comigo)
            ->with(['area:id,nome', 'instituicao:id,nome'])
            ->get()
            ->filter(fn (Projeto $p) => $janela->vagaPara($comissao, $porVaga[$p->id] ?? []) !== null)
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
                // Nulo: pode avaliar. Senão `necessaria`, `pendente` ou `recusada` (Sprint 234).
                'permissao' => $soDesignados ? $this->permissoes->situacao($p->id, $designados, $pedidos) : null,
                'permissao_resposta' => $soDesignados ? $pedidos->get($p->id)?->resposta : null,
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
            'pode_escrever' => ! $avaliacao->concluida() && $this->podeEscrever($avaliacao, $avaliador, $modoTeste, $janela),
            'designacao_manual' => $avaliacao->designacao_manual,
            // Escolhida no corredor (mapa ou lista) — o resto foi designado.
            'escolha_livre' => (bool) $avaliacao->escolha_livre,
            'vaga' => $avaliacao->vaga?->value,
            'vaga_label' => $avaliacao->vaga?->label(),
            'nota' => $avaliacao->nota,
            'nota_maxima' => RubricaPresencial::NOTA_MAXIMA,
            'concluida_em' => $avaliacao->concluida_em?->toIso8601String(),
        ];
    }

    /**
     * Os finalistas designados ao avaliador em Comitê → Designações (online)
     * que ainda não têm avaliação dele no estande — esses já vêm em `minhas`.
     * Fora da lista final não há estande, então não entram.
     *
     * @param  Collection<int, AvaliacaoPresencial>  $minhas
     * @return list<array<string, mixed>>
     */
    private function designadosDoComite(User $avaliador, bool $modoTeste, Collection $minhas): array
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $modoTeste);

        if ($lista === null) {
            return [];
        }

        $noEstande = $minhas->pluck('projeto_id')->flip();
        $designados = Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('designada_pelo_comite', true)
            ->whereHas('projeto')
            ->pluck('projeto_id')
            ->reject(fn ($id) => $noEstande->has($id))
            ->unique()
            ->values();

        $ids = $designados->isEmpty() ? [] : $lista->projetos()->whereIn('projetos.id', $designados)->pluck('projetos.id')->all();

        if ($ids === []) {
            return [];
        }

        $sinais = app(SinalizacaoProjetoService::class)->para($ids);

        return Projeto::whereIn('id', $ids)
            ->with(['area:id,nome', 'instituicao:id,nome'])
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'escola' => $p->instituicao?->nome,
                'local' => $this->local($p->id),
                'sinalizacoes' => $sinais[$p->id] ?? null,
            ])
            ->sortBy(fn (array $p) => [$p['local']['estande'] ?? PHP_INT_MAX, $p['titulo']])
            ->values()
            ->all();
    }

    private function prazoLabel(AvaliacaoPresencial $avaliacao, JanelaTurnos $janela): ?string
    {
        return $avaliacao->prazo($janela)?->format('d/m H:i');
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
