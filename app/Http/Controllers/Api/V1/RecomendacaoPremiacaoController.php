<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
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
            // Recorte pelo projeto (Sprint 194).
            'q' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'categoria' => ['nullable', Rule::enum(Categoria::class)],
        ]);

        return response()->json([
            'data' => $this->recomendacoes->listar(
                StatusRecomendacao::tryFrom((string) ($filtros['status'] ?? '')),
                [
                    'q' => $filtros['q'] ?? null,
                    'area_id' => isset($filtros['area_id']) ? (int) $filtros['area_id'] : null,
                    'categoria' => $filtros['categoria'] ?? null,
                ],
            ),
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
