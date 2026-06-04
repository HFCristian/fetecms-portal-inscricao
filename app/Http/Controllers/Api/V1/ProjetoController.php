<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projeto\ProjetoRequest;
use App\Http\Resources\ProjetoListResource;
use App\Http\Resources\ProjetoResource;
use App\Models\Projeto;
use App\Services\ProjetoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjetoController extends Controller
{
    private const RELATIONS = ['instituicao', 'area', 'subarea', 'estado', 'cidade', 'edicao'];

    public function __construct(private readonly ProjetoService $projetos) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Projeto::class);

        $status = $request->string('status')->toString() ?: null;
        $lista = $this->projetos->listarDoOrientador($request->user(), $status);

        return ProjetoListResource::collection($lista);
    }

    public function store(ProjetoRequest $request): JsonResponse
    {
        $this->authorize('create', Projeto::class);

        $projeto = $this->projetos->criarRascunho($request->user(), $request->validated());

        return ProjetoResource::make($projeto->load(self::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Projeto $projeto): ProjetoResource
    {
        $this->authorize('view', $projeto);

        return ProjetoResource::make($projeto->load(self::RELATIONS));
    }

    public function update(ProjetoRequest $request, Projeto $projeto): ProjetoResource
    {
        $this->authorize('update', $projeto);

        $projeto = $this->projetos->atualizar($projeto, $request->validated());

        return ProjetoResource::make($projeto);
    }

    public function destroy(Projeto $projeto): JsonResponse
    {
        $this->authorize('delete', $projeto);

        $projeto->delete();

        return response()->json(['data' => ['message' => 'Projeto removido.']]);
    }
}
