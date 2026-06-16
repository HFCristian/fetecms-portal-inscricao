<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InstituicaoUpdateRequest;
use App\Http\Requests\Admin\MesclarInstituicaoRequest;
use App\Models\Instituicao;
use App\Services\InstituicaoAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parametrização das instituições de ensino (somente admin): buscar, renomear, mesclar
 * e excluir. Toda mutação devolve a lista filtrada pelo termo de busca atual para o
 * front recarregar a tela sem uma segunda requisição.
 */
class InstituicaoAdminController extends Controller
{
    public function __construct(private readonly InstituicaoAdminService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->lista($request);
    }

    public function update(InstituicaoUpdateRequest $request, Instituicao $instituicao): JsonResponse
    {
        $this->service->renomear($instituicao, $request->validated()['nome']);

        return $this->lista($request, 'Instituição renomeada.');
    }

    public function merge(MesclarInstituicaoRequest $request, Instituicao $instituicao): JsonResponse
    {
        $this->service->mesclar($instituicao, Instituicao::findOrFail($request->validated()['destino_id']));

        return $this->lista($request, 'Instituições mescladas.');
    }

    public function destroy(Request $request, Instituicao $instituicao): JsonResponse
    {
        $this->service->excluir($instituicao);

        return $this->lista($request, 'Instituição excluída.');
    }

    private function lista(Request $request, ?string $message = null): JsonResponse
    {
        $payload = ['data' => $this->service->buscar($request->query('search') ?: null)];
        if ($message !== null) {
            $payload['meta'] = ['message' => $message];
        }

        return response()->json($payload);
    }
}
