<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Credencial;
use App\Models\Projeto;
use App\Models\RecomendacaoPremiacao;
use App\Services\RecomendacaoPremiacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba **Comitê especial** do avaliador (Sprint 193): quem é da comissão
 * especial recomenda finalistas para as credenciais e os prêmios da feira.
 * Os demais avaliadores recebem 403 — a aba nem aparece no menu deles.
 */
class AvaliadorComiteController extends Controller
{
    public function __construct(private readonly RecomendacaoPremiacaoService $recomendacoes) {}

    public function show(Request $request): JsonResponse
    {
        $this->garantirComissao($request);

        return response()->json(['data' => $this->recomendacoes->painel($request->user())]);
    }

    public function recomendar(Request $request): JsonResponse
    {
        $this->garantirComissao($request);

        $dados = $request->validate([
            'credencial_id' => ['required', 'integer', 'exists:credenciais,id'],
            'projeto_id' => ['required', 'integer', 'exists:projetos,id'],
            'justificativa' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->recomendacoes->recomendar(
            $request->user(),
            Credencial::findOrFail($dados['credencial_id']),
            Projeto::findOrFail($dados['projeto_id']),
            $dados['justificativa'] ?? null,
        );

        return response()->json([
            'data' => $this->recomendacoes->painel($request->user()),
            'meta' => ['message' => 'Recomendação enviada à organização.'],
        ], 201);
    }

    public function retirar(Request $request, RecomendacaoPremiacao $recomendacao): JsonResponse
    {
        $this->garantirComissao($request);

        $this->recomendacoes->retirar($request->user(), $recomendacao);

        return response()->json([
            'data' => $this->recomendacoes->painel($request->user()),
            'meta' => ['message' => 'Recomendação retirada.'],
        ]);
    }

    private function garantirComissao(Request $request): void
    {
        abort_unless(
            $this->recomendacoes->podeRecomendar($request->user()),
            403,
            'Esta aba é dos avaliadores da comissão especial.',
        );
    }
}
