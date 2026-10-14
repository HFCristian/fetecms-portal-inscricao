<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StatusRecomendacao;
use App\Http\Controllers\Controller;
use App\Models\RecomendacaoPremiacao;
use App\Services\CredenciaisService;
use App\Services\RecomendacaoPremiacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A decisão da organização sobre uma recomendação do comitê especial
 * (Sprint 193): aprovar ou rejeitar. Desde a Sprint 201 as recomendações são
 * listadas dentro do cartão de cada credencial ({@see CredenciaisService::listar()}).
 */
class RecomendacaoPremiacaoController extends Controller
{
    public function __construct(private readonly RecomendacaoPremiacaoService $recomendacoes) {}

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
