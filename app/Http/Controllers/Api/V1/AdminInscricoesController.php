<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PrazoInscricoesRequest;
use App\Services\InscricoesService;
use Illuminate\Http\JsonResponse;

/**
 * Aba "Inscrições" do admin: a data-limite de submissão dos projetos.
 */
class AdminInscricoesController extends Controller
{
    public function __construct(private readonly InscricoesService $inscricoes) {}

    /** Estado atual do prazo. */
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
}
