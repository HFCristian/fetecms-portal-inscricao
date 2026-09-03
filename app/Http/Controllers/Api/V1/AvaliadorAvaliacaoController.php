<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusAvaliacao;
use App\Http\Controllers\Controller;
use App\Http\Requests\Avaliador\ConcluirAvaliacaoRequest;
use App\Http\Requests\Avaliador\EditarParecerRequest;
use App\Http\Requests\Avaliador\RascunhoAvaliacaoRequest;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Services\AvaliacaoFluxoService;
use App\Services\FilaAvaliadorService;
use App\Support\Rubrica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação online — lado do avaliador (E7). Antes da liberação nada aparece;
 * depois, o avaliador vê os projetos designados, lê cada um, inicia e conclui
 * respondendo às perguntas da rubrica da FETECMS, seção por seção. O avaliador
 * demo em "modo teste" ignora a data de liberação (suas avaliações são dados
 * de teste, limpáveis pelo admin).
 */
class AvaliadorAvaliacaoController extends Controller
{
    public function __construct(
        private readonly AvaliacaoFluxoService $fluxo,
        private readonly FilaAvaliadorService $fila,
    ) {}

    /** Lista os projetos designados ao avaliador (se puder avaliar agora). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $teste = $request->boolean('teste');
        $podeVer = $this->fluxo->podeVer($user, $teste);
        $pode = $this->fluxo->podeAvaliar($user, $teste);

        // Quantos projetos o avaliador enxerga de uma vez: o mínimo por avaliador
        // definido pelo admin. O teto vale só para a fila de trabalho — o que ele
        // já avaliou fica na seção de concluídos, sem limite.
        $minPorAvaliador = Edicao::minPorAvaliador();

        $projetos = [];
        $concluidos = [];

        if ($podeVer) {
            $avaliacoes = Avaliacao::query()
                ->where('avaliador_id', $user->id)
                ->with(['projeto:id,titulo,area_id', 'projeto.area:id,nome'])
                ->orderBy('id')
                ->get();

            [$concluidas, $pendentes] = $avaliacoes->partition(
                fn (Avaliacao $a) => $a->status === StatusAvaliacao::Concluida,
            );

            $projetos = $pendentes->take($minPorAvaliador)
                ->map(fn (Avaliacao $a) => $this->linha($a))
                ->values()
                ->all();

            // Mais recentes primeiro: o que ele acabou de enviar aparece no topo.
            $concluidos = $concluidas->sortByDesc(fn (Avaliacao $a) => $a->concluida_em ?? $a->updated_at)
                ->map(fn (Avaliacao $a) => $this->linha($a))
                ->values()
                ->all();
        }

        $edicao = Edicao::atual();

        return response()->json(['data' => [
            'liberada' => (bool) $edicao?->avaliacaoLiberada(),
            'liberada_em_label' => $edicao?->avaliacao_liberada_em?->format('d/m/Y H:i'),
            'encerrada' => (bool) $edicao?->avaliacaoEncerrada(),
            'encerrada_em_label' => $edicao?->avaliacao_encerrada_em?->format('d/m/Y H:i'),
            // Ver != avaliar: encerrado o período, a leitura continua liberada.
            'pode_ver' => $podeVer,
            'pode_avaliar' => $pode,
            'is_demo' => (bool) $user->is_demo,
            'modo_teste' => $teste && (bool) $user->is_demo,
            'nota_maxima' => Avaliacao::notaMaxima(),
            'min_por_avaliador' => $minPorAvaliador,
            // Fila de trabalho e histórico ficam em listas separadas: a tela do
            // avaliador mostra cada uma na sua seção.
            'projetos' => $projetos,
            'concluidos' => $concluidos,
        ]]);
    }

    /**
     * Sorteia outros projetos para a fila do avaliador. O que já está em
     * avaliação e o que o admin designou continuam onde estão.
     */
    public function roletar(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $this->fluxo->podeAvaliar($user, $request->boolean('teste')),
            403,
            $this->fluxo->motivoBloqueio()
        );

        $resultado = $this->fila->roletar($user);

        return response()->json([
            'data' => $resultado,
            'meta' => ['message' => $resultado['trocados'] === 0
                ? 'Não há projeto para sortear: os da sua fila já estão em avaliação ou foram designados pela organização.'
                : "Fila sorteada de novo: {$resultado['recebidos']} projeto(s) na sua lista."],
        ]);
    }

    /** Abre um projeto designado para leitura. */
    public function show(Request $request, Avaliacao $avaliacao): JsonResponse
    {
        $this->garantirLeitura($request, $avaliacao);

        return response()->json(['data' => [
            'pode_avaliar' => $this->fluxo->podeAvaliar($request->user(), $request->boolean('teste')),
            'avaliacao' => $this->avaliacao($avaliacao),
            'projeto' => $this->fluxo->detalhesProjeto($avaliacao->projeto),
            // Perguntas, escala e pesos: o front só desenha o que vem daqui.
            'rubrica' => Rubrica::paraApi(),
        ]]);
    }

    /** Inicia a avaliação (não pode cancelar depois). */
    public function iniciar(Request $request, Avaliacao $avaliacao): JsonResponse
    {
        $this->garantirAcesso($request, $avaliacao);
        $this->fluxo->iniciar($avaliacao);

        return response()->json(['data' => $this->avaliacao($avaliacao->fresh())]);
    }

    /** Salva o preenchimento parcial sem enviar (segue em_andamento). */
    public function rascunho(RascunhoAvaliacaoRequest $request, Avaliacao $avaliacao): JsonResponse
    {
        $this->garantirAcesso($request, $avaliacao);
        $this->fluxo->salvarRascunho($avaliacao, $request->validated());

        return response()->json([
            'data' => $this->avaliacao($avaliacao->fresh()),
            'meta' => ['message' => 'Rascunho salvo.'],
        ]);
    }

    /** Conclui a avaliação com as respostas da rubrica + recomendações. */
    public function concluir(ConcluirAvaliacaoRequest $request, Avaliacao $avaliacao): JsonResponse
    {
        $this->garantirAcesso($request, $avaliacao);
        $this->fluxo->concluir($avaliacao, $request->validated());

        return response()->json([
            'data' => $this->avaliacao($avaliacao->fresh()),
            'meta' => ['message' => 'Avaliação concluída.'],
        ]);
    }

    /**
     * Corrige o parecer final de uma avaliação já enviada (só as recomendações
     * escritas), com justificativa obrigatória.
     */
    public function parecer(EditarParecerRequest $request, Avaliacao $avaliacao): JsonResponse
    {
        $this->garantirAcesso($request, $avaliacao);

        $alterados = $this->fluxo->editarParecer($avaliacao, $request->validated(), $request->user());

        return response()->json([
            'data' => $this->avaliacao($avaliacao->fresh()),
            'meta' => [
                'message' => $alterados === []
                    ? 'Nada mudou no parecer.'
                    : 'Parecer atualizado: '.implode(' e ', $alterados).'.',
            ],
        ]);
    }

    /** Leitura: exige ser o dono e a avaliação já liberada. */
    private function garantirLeitura(Request $request, Avaliacao $avaliacao): void
    {
        abort_unless($avaliacao->avaliador_id === $request->user()->id, 403, 'Esta avaliação não é sua.');
        abort_unless(
            $this->fluxo->podeVer($request->user(), $request->boolean('teste')),
            403,
            'A avaliação ainda não está liberada.'
        );
    }

    /** Escrita: além da leitura, exige o período de avaliação em aberto. */
    private function garantirAcesso(Request $request, Avaliacao $avaliacao): void
    {
        $this->garantirLeitura($request, $avaliacao);
        abort_unless(
            $this->fluxo->podeAvaliar($request->user(), $request->boolean('teste')),
            403,
            $this->fluxo->motivoBloqueio()
        );
    }

    private function linha(Avaliacao $a): array
    {
        return [
            'avaliacao_id' => $a->id,
            'projeto_id' => $a->projeto_id,
            'titulo' => $a->projeto?->titulo,
            'area' => $a->projeto?->area?->nome,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'nota' => $a->nota,
            'concluida_em_label' => $a->concluida_em?->format('d/m/Y H:i'),
        ];
    }

    private function avaliacao(Avaliacao $a): array
    {
        $a->loadMissing(['areaSugerida:id,nome', 'subareaSugerida:id,nome']);

        return [
            'id' => $a->id,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'nota' => $a->nota,
            'nota_maxima' => Avaliacao::notaMaxima(),
            'respostas' => (object) ($a->respostas ?? []),
            'comentario_video' => $a->comentario_video,
            'comentario_projeto' => $a->comentario_projeto,
            'area_correta' => $a->area_correta,
            'area_sugerida_id' => $a->area_sugerida_id,
            'area_sugerida' => $a->areaSugerida?->nome,
            'subarea_correta' => $a->subarea_correta,
            'subarea_sugerida_id' => $a->subarea_sugerida_id,
            'subarea_sugerida' => $a->subareaSugerida?->nome,
            'rascunho_em' => $a->rascunho_em?->toIso8601String(),
        ];
    }
}
