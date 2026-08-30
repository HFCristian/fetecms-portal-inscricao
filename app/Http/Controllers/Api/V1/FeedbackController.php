<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lado de quem responde: o balão que aparece ao entrar no portal e o
 * questionário em si.
 *
 * As respostas são anônimas — o serviço grava a participação (que esta pessoa
 * respondeu) separada do conteúdo.
 */
class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackService $feedbacks) {}

    /** O feedback pendente desta pessoa — `null` quando não há nenhum. */
    public function pendente(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->feedbacks->pendentePara($request->user())]);
    }

    /** Todos os pendentes, inclusive os dispensados — a lista do perfil. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->feedbacks->listarPara($request->user())]);
    }

    /** Registra que o balão apareceu (é o "viram" do relatório). */
    public function visto(Request $request, Feedback $feedback): JsonResponse
    {
        $this->feedbacks->marcarVisto($feedback, $request->user());

        return response()->json(['data' => ['ok' => true]]);
    }

    /** A pessoa fechou o balão; ela ainda pode responder pelo perfil. */
    public function dispensar(Request $request, Feedback $feedback): JsonResponse
    {
        $this->feedbacks->dispensar($feedback, $request->user());

        return response()->json(['data' => ['ok' => true]]);
    }

    public function responder(Request $request, Feedback $feedback): JsonResponse
    {
        $dados = $request->validate([
            'respostas' => ['present', 'array'],
            'respostas.*' => ['nullable', 'string', 'max:20000'],
        ]);

        $this->feedbacks->responder($feedback, $request->user(), $dados['respostas']);

        return response()->json([
            'data' => ['ok' => true],
            'meta' => ['message' => 'Obrigado! Sua resposta foi registrada.'],
        ]);
    }
}
