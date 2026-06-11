<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AreaUpdateRequest;
use App\Http\Requests\Admin\MesclarAreaRequest;
use App\Http\Requests\Admin\MesclarSubareaRequest;
use App\Http\Requests\Admin\SubareaUpdateRequest;
use App\Models\Area;
use App\Models\Subarea;
use App\Services\CatalogoAdminService;
use Illuminate\Http\JsonResponse;

/**
 * Parametrização do catálogo (somente admin): renomear, mesclar e excluir áreas e
 * subáreas. Toda mutação devolve a árvore atualizada para o front recarregar a tela.
 */
class CatalogoAdminController extends Controller
{
    public function __construct(private readonly CatalogoAdminService $catalogo) {}

    public function index(): JsonResponse
    {
        return $this->arvore();
    }

    public function updateArea(AreaUpdateRequest $request, Area $area): JsonResponse
    {
        $this->catalogo->renomearArea($area, $request->validated()['nome']);

        return $this->arvore('Área renomeada.');
    }

    public function mergeArea(MesclarAreaRequest $request, Area $area): JsonResponse
    {
        $this->catalogo->mesclarAreas($area, Area::findOrFail($request->validated()['destino_id']));

        return $this->arvore('Áreas mescladas.');
    }

    public function destroyArea(Area $area): JsonResponse
    {
        $this->catalogo->excluirArea($area);

        return $this->arvore('Área excluída.');
    }

    public function updateSubarea(SubareaUpdateRequest $request, Subarea $subarea): JsonResponse
    {
        $this->catalogo->renomearSubarea($subarea, $request->validated()['nome']);

        return $this->arvore('Subárea renomeada.');
    }

    public function mergeSubarea(MesclarSubareaRequest $request, Subarea $subarea): JsonResponse
    {
        $this->catalogo->mesclarSubareas($subarea, Subarea::findOrFail($request->validated()['destino_id']));

        return $this->arvore('Subáreas mescladas.');
    }

    public function destroySubarea(Subarea $subarea): JsonResponse
    {
        $this->catalogo->excluirSubarea($subarea);

        return $this->arvore('Subárea excluída.');
    }

    private function arvore(?string $message = null): JsonResponse
    {
        $payload = ['data' => $this->catalogo->arvore()];
        if ($message !== null) {
            $payload['meta'] = ['message' => $message];
        }

        return response()->json($payload);
    }
}
