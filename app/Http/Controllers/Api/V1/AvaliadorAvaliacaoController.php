<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Avaliador\ConcluirAvaliacaoRequest;
use App\Http\Requests\Avaliador\RascunhoAvaliacaoRequest;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Services\AvaliacaoFluxoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação online — lado do avaliador (E7). Antes da liberação nada aparece;
 * depois, o avaliador vê os projetos designados, lê cada um, inicia e conclui
 * preenchendo a rubrica em escala Likert. O avaliador demo em "modo teste"
 * ignora a data de liberação (suas avaliações são dados de teste, limpáveis
 * pelo admin).
 */
class AvaliadorAvaliacaoController extends Controller
{
    public function __construct(private readonly AvaliacaoFluxoService $fluxo) {}

    /** Lista os projetos designados ao avaliador (se puder avaliar agora). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $teste = $request->boolean('teste');
        $pode = $this->fluxo->podeAvaliar($user, $teste);

        $projetos = [];
        if ($pode) {
            $projetos = Avaliacao::query()
                ->where('avaliador_id', $user->id)
                ->with(['projeto:id,titulo,area_id', 'projeto.area:id,nome'])
                ->get()
                ->map(fn (Avaliacao $a) => $this->linha($a))
                ->all();
        }

        $edicao = Edicao::atual();

        return response()->json(['data' => [
            'liberada' => (bool) $edicao?->avaliacaoLiberada(),
            'liberada_em_label' => $edicao?->avaliacao_liberada_em?->format('d/m/Y H:i'),
            'pode_avaliar' => $pode,
            'is_demo' => (bool) $user->is_demo,
            'modo_teste' => $teste && (bool) $user->is_demo,
            'nota_maxima' => Avaliacao::notaMaxima(),
            'projetos' => $projetos,
        ]]);
    }

    /** Abre um projeto designado para leitura. */
    public function show(Request $request, Avaliacao $avaliacao): JsonResponse
    {
        $this->garantirAcesso($request, $avaliacao);

        return response()->json(['data' => [
            'avaliacao' => $this->avaliacao($avaliacao),
            'projeto' => $this->fluxo->detalhesProjeto($avaliacao->projeto),
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

    /** Conclui a avaliação com a rubrica (quesitos em escala Likert + comentários). */
    public function concluir(ConcluirAvaliacaoRequest $request, Avaliacao $avaliacao): JsonResponse
    {
        $this->garantirAcesso($request, $avaliacao);
        $this->fluxo->concluir($avaliacao, $request->validated());

        return response()->json([
            'data' => $this->avaliacao($avaliacao->fresh()),
            'meta' => ['message' => 'Avaliação concluída.'],
        ]);
    }

    private function garantirAcesso(Request $request, Avaliacao $avaliacao): void
    {
        abort_unless($avaliacao->avaliador_id === $request->user()->id, 403, 'Esta avaliação não é sua.');
        abort_unless(
            $this->fluxo->podeAvaliar($request->user(), $request->boolean('teste')),
            403,
            'A avaliação ainda não está liberada.'
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
        ];
    }

    private function avaliacao(Avaliacao $a): array
    {
        $rubrica = [];

        foreach ([...Avaliacao::QUESITOS, Avaliacao::QUESITO_CONTINUIDADE] as $quesito) {
            $rubrica["nota_{$quesito}"] = $a->{"nota_{$quesito}"};
            $rubrica["comentario_{$quesito}"] = $a->{"comentario_{$quesito}"};
        }

        $a->loadMissing(['areaSugerida:id,nome', 'subareaSugerida:id,nome']);

        return [
            'id' => $a->id,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'nota' => $a->nota,
            'nota_maxima' => Avaliacao::notaMaxima(),
            'nota_minima_quesito' => Avaliacao::NOTA_MINIMA_QUESITO,
            'nota_maxima_quesito' => Avaliacao::NOTA_MAXIMA_QUESITO,
            // Escala Likert como o avaliador a vê (fonte única no back).
            'escala' => $this->escala(),
            ...$rubrica,
            'area_correta' => $a->area_correta,
            'area_sugerida_id' => $a->area_sugerida_id,
            'area_sugerida' => $a->areaSugerida?->nome,
            'subarea_correta' => $a->subarea_correta,
            'subarea_sugerida_id' => $a->subarea_sugerida_id,
            'subarea_sugerida' => $a->subareaSugerida?->nome,
            'rascunho_em' => $a->rascunho_em?->toIso8601String(),
        ];
    }

    /**
     * Escala Likert em formato de lista, na ordem em que aparece para o avaliador.
     *
     * @return list<array{valor:int, rotulo:string}>
     */
    private function escala(): array
    {
        return array_map(
            fn (int $valor, string $rotulo) => ['valor' => $valor, 'rotulo' => $rotulo],
            array_keys(Avaliacao::ESCALA),
            array_values(Avaliacao::ESCALA),
        );
    }
}
