<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Services\AvaliacaoFluxoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação online — lado do avaliador (E7). Antes da liberação nada aparece;
 * depois, o avaliador vê os projetos designados, lê cada um, inicia e conclui
 * com nota 1–10. O avaliador demo em "modo teste" ignora a data de liberação
 * (suas avaliações são dados de teste, limpáveis pelo admin).
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
            'liberada_em' => $edicao?->avaliacao_liberada_em?->toIso8601String(),
            'pode_avaliar' => $pode,
            'is_demo' => (bool) $user->is_demo,
            'modo_teste' => $teste && (bool) $user->is_demo,
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

    /** Conclui a avaliação com a nota (1–10). */
    public function concluir(Request $request, Avaliacao $avaliacao): JsonResponse
    {
        $this->garantirAcesso($request, $avaliacao);
        $nota = (int) $request->validate(['nota' => ['required', 'integer', 'min:1', 'max:10']])['nota'];
        $this->fluxo->concluir($avaliacao, $nota);

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
        return [
            'id' => $a->id,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'nota' => $a->nota,
        ];
    }
}
