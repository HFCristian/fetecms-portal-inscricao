<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrante\CoorientadorRequest;
use App\Http\Resources\CoorientadorResource;
use App\Models\Projeto;
use App\Services\AdminRascunhoService;
use App\Services\CoorientadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoorientadorController extends Controller
{
    public function __construct(
        private readonly CoorientadorService $coorientadores,
        private readonly AdminRascunhoService $rascunhos,
    ) {}

    public function show(Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);

        return response()->json([
            'data' => $projeto->coorientador
                ? CoorientadorResource::make($projeto->coorientador)->resolve()
                : null,
        ]);
    }

    public function upsert(CoorientadorRequest $request, Projeto $projeto): CoorientadorResource
    {
        $this->authorize('update', $projeto);

        $antes = $projeto->coorientador?->nome;
        $coorientador = $this->coorientadores->upsert($projeto, $request->validated());
        $this->rascunhos->registrarCoorientador($projeto, $request->user(), $antes, $coorientador);

        return CoorientadorResource::make($coorientador);
    }

    public function destroy(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('update', $projeto);

        $this->rascunhos->registrarCoorientadorRemovido($projeto, $request->user(), $projeto->coorientador?->nome);
        $projeto->coorientador()->delete();

        return response()->json(['data' => ['message' => 'Coorientador removido.']]);
    }
}
