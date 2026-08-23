<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InicioInscricoesRequest;
use App\Http\Requests\Admin\PrazoInscricoesRequest;
use App\Services\InscricoesService;
use Illuminate\Http\JsonResponse;

/**
 * Parametrização → Inscrições (admin): a janela de inscrição, da abertura
 * ao prazo final de submissão.
 */
class AdminInscricoesController extends Controller
{
    public function __construct(private readonly InscricoesService $inscricoes) {}

    /** Estado atual da janela (abertura + prazo). */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->inscricoes->config()]);
    }

    /** Define ou remove a data-limite de submissão. */
    public function definirPrazo(PrazoInscricoesRequest $request): JsonResponse
    {
        $config = $this->inscricoes->definirPrazo($request->validated('prazo'));

        return response()->json([
            'data' => $config,
            'meta' => ['message' => $config['prazo_label'] ? 'Prazo de inscrição salvo.' : 'Prazo removido.'],
        ]);
    }

    /** Define ou remove a data de abertura das inscrições. */
    public function definirInicio(InicioInscricoesRequest $request): JsonResponse
    {
        $config = $this->inscricoes->definirInicio($request->validated('inicio'));

        return response()->json([
            'data' => $config,
            'meta' => ['message' => $config['inicio_label']
                ? 'Abertura das inscrições salva.'
                : 'Data de abertura removida — as inscrições abrem imediatamente.'],
        ]);
    }
}
