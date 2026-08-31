<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AbaAdmin;
use App\Http\Controllers\Controller;
use App\Services\OrdemAbasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parametrização → **Ordem do menu**: em que ordem as abas do admin aparecem,
 * no menu lateral e na Home. A ordem é da edição em escopo.
 */
class ParametrizacaoAbasController extends Controller
{
    public function __construct(private readonly OrdemAbasService $ordem) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->ordem->config()]);
    }

    public function definir(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'ordem' => ['present', 'array'],
            'ordem.*' => [Rule::in(AbaAdmin::valores())],
        ]);

        return response()->json([
            'data' => $this->ordem->definir($dados['ordem']),
            'meta' => ['message' => 'Ordem do menu salva.'],
        ]);
    }

    /** Volta à ordem original do portal. */
    public function restaurar(): JsonResponse
    {
        return response()->json([
            'data' => $this->ordem->restaurar(),
            'meta' => ['message' => 'Ordem do menu restaurada.'],
        ]);
    }
}
