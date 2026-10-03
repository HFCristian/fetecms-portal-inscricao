<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuporteProjetoRequest;
use App\Models\Projeto;
use App\Models\SuporteProjeto;
use App\Services\SuporteProjetoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Credenciamento → **Suporte e acessibilidade** (Sprint 162): a organização
 * decide os pedidos dos orientadores e registra os que recebeu por fora.
 */
class SuporteAdminController extends Controller
{
    public function __construct(private readonly SuporteProjetoService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->listarAdmin($this->filtros($request))]);
    }

    public function store(SuporteProjetoRequest $request, Projeto $projeto): JsonResponse
    {
        $this->service->salvar($projeto, $request->validated(), $request->user());

        return response()->json([
            'data' => $this->service->listarAdmin($this->filtros($request)),
            'meta' => ['message' => 'Pedido registrado.'],
        ], 201);
    }

    public function update(SuporteProjetoRequest $request, SuporteProjeto $suporte): JsonResponse
    {
        $this->service->salvar($suporte->projeto, $request->validated(), $request->user(), $suporte);

        return response()->json([
            'data' => $this->service->listarAdmin($this->filtros($request)),
            'meta' => ['message' => 'Pedido atualizado.'],
        ]);
    }

    public function decidir(Request $request, SuporteProjeto $suporte): JsonResponse
    {
        $dados = $request->validate([
            'aprovar' => ['required', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->decidir($suporte, (bool) $dados['aprovar'], $dados['motivo'] ?? null, $request->user());

        return response()->json([
            'data' => $this->service->listarAdmin($this->filtros($request)),
            'meta' => ['message' => $dados['aprovar'] ? 'Pedido aprovado.' : 'Pedido recusado.'],
        ]);
    }

    public function destroy(Request $request, SuporteProjeto $suporte): JsonResponse
    {
        $this->service->excluir($suporte, $request->user());

        return response()->json([
            'data' => $this->service->listarAdmin($this->filtros($request)),
            'meta' => ['message' => 'Pedido excluído.'],
        ]);
    }

    /** @return array<string, mixed> */
    private function filtros(Request $request): array
    {
        return $request->only(['status', 'tipo', 'q']);
    }
}
