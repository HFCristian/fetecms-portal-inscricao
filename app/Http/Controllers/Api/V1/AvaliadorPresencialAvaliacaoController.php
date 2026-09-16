<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AvaliacaoPresencial;
use App\Models\Projeto;
use App\Services\AvaliacaoPresencialFluxoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação presencial — lado do avaliador: os estandes que ele avalia no dia
 * da feira e a rubrica que responde em cada um.
 */
class AvaliadorPresencialAvaliacaoController extends Controller
{
    public function __construct(private readonly AvaliacaoPresencialFluxoService $fluxo) {}

    /** O painel: o que está com ele e o que ainda pode pegar. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->fluxo->painel($request->user(), $request->boolean('teste')),
        ]);
    }

    /** Abre (ou retoma) a avaliação de um projeto. */
    public function iniciar(Request $request, Projeto $projeto): JsonResponse
    {
        return response()->json([
            'data' => $this->fluxo->iniciar($request->user(), $projeto, $request->boolean('teste')),
            'meta' => ['message' => 'Avaliação aberta.'],
        ]);
    }

    /** Uma avaliação para responder (ou reler, depois de enviada). */
    public function show(Request $request, AvaliacaoPresencial $avaliacao): JsonResponse
    {
        abort_unless($avaliacao->avaliador_id === $request->user()->id, 403);

        return response()->json(['data' => $this->fluxo->detalhe($avaliacao)]);
    }

    public function rascunho(Request $request, AvaliacaoPresencial $avaliacao): JsonResponse
    {
        $dados = $this->validar($request);

        return response()->json([
            'data' => $this->fluxo->salvarRascunho($request->user(), $avaliacao, $dados, $request->boolean('teste')),
            'meta' => ['message' => 'Rascunho salvo.'],
        ]);
    }

    public function concluir(Request $request, AvaliacaoPresencial $avaliacao): JsonResponse
    {
        $dados = $this->validar($request);

        return response()->json([
            'data' => $this->fluxo->concluir($request->user(), $avaliacao, $dados, $request->boolean('teste')),
            'meta' => ['message' => 'Avaliação enviada.'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        return $request->validate([
            'respostas' => ['nullable', 'array'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
