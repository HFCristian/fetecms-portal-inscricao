<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PermissaoComiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parametrização → **Avaliação presencial** (Sprint 234). Por ora, a subseção
 * do comitê especial: a regra "avaliar apenas projetos designados".
 */
class ParametrizacaoPresencialController extends Controller
{
    public function __construct(private readonly PermissaoComiteService $permissoes) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->dados()]);
    }

    public function definir(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'comite_so_designados' => ['required', 'boolean'],
        ], ['comite_so_designados.required' => 'Diga se a regra fica ligada ou desligada.']);

        $this->permissoes->definir((bool) $dados['comite_so_designados'], $request->user());

        return response()->json([
            'data' => $this->dados(),
            'meta' => ['message' => $dados['comite_so_designados']
                ? 'O comitê passa a avaliar só os projetos designados.'
                : 'O comitê volta a avaliar qualquer projeto com vaga.'],
        ]);
    }

    /** @return array<string, mixed> */
    private function dados(): array
    {
        return [
            'comite_so_designados' => $this->permissoes->ativa(),
            'pedidos_pendentes' => $this->permissoes->pendentes(),
        ];
    }
}
