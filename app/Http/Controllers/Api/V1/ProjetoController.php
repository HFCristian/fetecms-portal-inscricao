<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projeto\ProjetoRequest;
use App\Http\Resources\ProjetoListResource;
use App\Http\Resources\ProjetoResource;
use App\Models\Projeto;
use App\Services\ProjetoService;
use App\Services\SubmissaoService;
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

    /**
     * Descarta o rascunho ou exclui a inscrição já submetida. No segundo caso a
     * janela é verificada (avaliação não iniciada e período de avaliação não
     * começado) e a exclusão entra na trilha de auditoria do admin.
     */
    public function destroy(Request $request, Projeto $projeto, SubmissaoService $submissoes): JsonResponse
    {
        $this->authorize('delete', $projeto);

        $submetido = ! $projeto->status->editavel();
        $motivos = $submissoes->impedimentosPara($projeto, $request->user());

        if (! empty($motivos)) {
            return response()->json([
                'message' => 'Não é mais possível excluir esta inscrição.',
                'motivos' => $motivos,
                'code' => 'SUBMISSAO_BLOQUEADA',
            ], 422);
        }

        $submissoes->excluir($projeto, $request->user());

        return response()->json(['data' => [
            'message' => $submetido ? 'Inscrição excluída.' : 'Projeto removido.',
        ]]);
    }
}
