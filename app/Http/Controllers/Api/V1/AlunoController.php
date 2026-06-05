<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrante\AlunoRequest;
use App\Http\Resources\AlunoResource;
use App\Models\Aluno;
use App\Models\Projeto;
use App\Services\AlunoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlunoController extends Controller
{
    public function __construct(private readonly AlunoService $alunos) {}

    public function index(Projeto $projeto): AnonymousResourceCollection
    {
        $this->authorize('view', $projeto);

        return AlunoResource::collection($projeto->alunos);
    }

    public function store(AlunoRequest $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('update', $projeto); // dono + projeto em rascunho

        $aluno = $this->alunos->adicionar($projeto, $request->validated());

        return AlunoResource::make($aluno)->response()->setStatusCode(201);
    }

    public function show(Aluno $aluno): AlunoResource
    {
        $this->authorize('view', $aluno->projeto);

        return AlunoResource::make($aluno);
    }

    public function update(AlunoRequest $request, Aluno $aluno): AlunoResource
    {
        $this->authorize('update', $aluno->projeto);

        return AlunoResource::make($this->alunos->atualizar($aluno, $request->validated()));
    }

    public function destroy(Aluno $aluno): JsonResponse
    {
        $this->authorize('update', $aluno->projeto);

        $aluno->delete();

        return response()->json(['data' => ['message' => 'Aluno removido.']]);
    }
}
