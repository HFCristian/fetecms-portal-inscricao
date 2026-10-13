<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusRecomendacao;
use App\Http\Controllers\Controller;
use App\Models\RecomendacaoPremiacao;
use App\Services\RecomendacaoPremiacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * As recomendações do comitê especial, para a organização aprovar ou
 * rejeitar (Sprint 193). Abrem nas duas abas que mexem na premiação: Comitê
 * especial e Avaliação presencial.
 */
class RecomendacaoPremiacaoController extends Controller
{
    public function __construct(private readonly RecomendacaoPremiacaoService $recomendacoes) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'status' => ['nullable', Rule::enum(StatusRecomendacao::class)],
        ]);

        return response()->json([
            'data' => $this->recomendacoes->listar(StatusRecomendacao::tryFrom((string) ($filtros['status'] ?? ''))),
        ]);
    }

    public function decidir(Request $request, RecomendacaoPremiacao $recomendacao): JsonResponse
    {
        $dados = $request->validate([
            'status' => ['required', Rule::in([StatusRecomendacao::Aprovada->value, StatusRecomendacao::Rejeitada->value])],
        ]);

        $status = StatusRecomendacao::from($dados['status']);
        $this->recomendacoes->decidir($recomendacao, $status, $request->user());

        return response()->json([
            'meta' => ['message' => $status === StatusRecomendacao::Aprovada ? 'Recomendação aprovada.' : 'Recomendação rejeitada.'],
        ]);
    }
}
