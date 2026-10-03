<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjetoManualRequest;
use App\Models\Projeto;
use App\Services\ProjetoManualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação online → Listas finais → **Cadastro manual de projetos** (Sprint
 * 157): projetos que vão ao evento sem ter passado pela inscrição.
 */
class ProjetoManualController extends Controller
{
    public function __construct(private readonly ProjetoManualService $service) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listar(),
            'meta' => ['categorias' => Categoria::opcoes()],
        ]);
    }

    public function show(Projeto $projeto): JsonResponse
    {
        return response()->json(['data' => $this->service->detalhe($projeto)]);
    }

    public function store(ProjetoManualRequest $request): JsonResponse
    {
        $projeto = $this->service->criar($request->validated(), $request->user());

        return response()->json([
            'data' => $this->service->listar(),
            'meta' => [
                'message' => "Projeto \"{$projeto->titulo}\" cadastrado e incluído na lista final.",
                'projeto_id' => $projeto->id,
            ],
        ], 201);
    }

    public function update(ProjetoManualRequest $request, Projeto $projeto): JsonResponse
    {
        $this->service->atualizar($projeto, $request->validated(), $request->user());

        return response()->json([
            'data' => $this->service->listar(),
            'meta' => ['message' => 'Projeto atualizado.'],
        ]);
    }

    public function destroy(Request $request, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate(['justificativa' => ['required', 'string', 'min:5', 'max:1000']]);

        $this->service->excluir($projeto, $request->user(), $dados['justificativa']);

        return response()->json([
            'data' => $this->service->listar(),
            'meta' => ['message' => 'Projeto excluído e retirado da lista final.'],
        ]);
    }
}
