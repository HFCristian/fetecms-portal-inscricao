<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrante\CoorientadorRequest;
use App\Http\Resources\CoorientadorResource;
use App\Models\Projeto;
use App\Services\CoorientadorService;
use Illuminate\Http\JsonResponse;

class CoorientadorController extends Controller
{
    public function __construct(private readonly CoorientadorService $coorientadores) {}

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

        return CoorientadorResource::make(
            $this->coorientadores->upsert($projeto, $request->validated())
        );
    }

    public function destroy(Projeto $projeto): JsonResponse
    {
        $this->authorize('update', $projeto);

        $projeto->coorientador()->delete();

        return response()->json(['data' => ['message' => 'Coorientador removido.']]);
    }
}
