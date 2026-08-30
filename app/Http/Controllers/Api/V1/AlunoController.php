<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrante\AlunoRequest;
use App\Http\Resources\AlunoResource;
use App\Models\Aluno;
use App\Models\Projeto;
use App\Services\AdminRascunhoService;
use App\Services\AlunoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlunoController extends Controller
{
    public function __construct(
        private readonly AlunoService $alunos,
        private readonly AdminRascunhoService $rascunhos,
    ) {}

    public function index(Projeto $projeto): AnonymousResourceCollection
    {
        $this->authorize('view', $projeto);

        return AlunoResource::collection($projeto->alunos);
    }

    public function store(AlunoRequest $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('update', $projeto); // dono + projeto em rascunho

        $aluno = $this->alunos->adicionar($projeto, $request->validated());
        $this->rascunhos->registrarAlunoAdicionado($projeto, $request->user(), $aluno);

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

        $antes = $aluno->getAttributes();
        $atualizado = $this->alunos->atualizar($aluno, $request->validated());
        $this->rascunhos->registrarAlunoAlterado($aluno->projeto, $request->user(), $atualizado, $antes);

        return AlunoResource::make($atualizado);
    }

    public function destroy(Request $request, Aluno $aluno): JsonResponse
    {
        $this->authorize('update', $aluno->projeto);

        $this->rascunhos->registrarAlunoRemovido($aluno->projeto, $request->user(), $aluno);
        $aluno->delete();

        return response()->json(['data' => ['message' => 'Aluno removido.']]);
    }
}
