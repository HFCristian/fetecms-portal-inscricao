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
 * Aba **Suporte** do orientador (Sprint 162): acompanhante e intérpretes para
 * os projetos dele que estão na lista final. `teste=1` liga o modo de teste da
 * conta demo.
 */
class SuporteOrientadorController extends Controller
{
    public function __construct(private readonly SuporteProjetoService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->painel($request)]);
    }

    public function store(SuporteProjetoRequest $request, Projeto $projeto): JsonResponse
    {
        $dados = collect($request->validated())->except('aprovar')->all();
        $this->service->salvar($projeto, $dados, $request->user(), null, $request->boolean('teste'));

        return response()->json([
            'data' => $this->painel($request),
            'meta' => ['message' => 'Pedido enviado. A organização vai analisar.'],
        ], 201);
    }

    public function update(SuporteProjetoRequest $request, SuporteProjeto $suporte): JsonResponse
    {
        abort_unless($suporte->projeto?->user_id === $request->user()->id, 404, 'Pedido não encontrado.');

        $dados = collect($request->validated())->except('aprovar')->all();
        $this->service->salvar($suporte->projeto, $dados, $request->user(), $suporte, $request->boolean('teste'));

        return response()->json([
            'data' => $this->painel($request),
            'meta' => ['message' => 'Pedido atualizado.'],
        ]);
    }

    public function destroy(Request $request, SuporteProjeto $suporte): JsonResponse
    {
        abort_unless($suporte->projeto?->user_id === $request->user()->id, 404, 'Pedido não encontrado.');

        $this->service->excluir($suporte, $request->user(), $request->boolean('teste'));

        return response()->json([
            'data' => $this->painel($request),
            'meta' => ['message' => 'Pedido excluído.'],
        ]);
    }

    /** @return array<string, mixed> */
    private function painel(Request $request): array
    {
        return [
            'janela' => $this->service->janela($request->user(), $request->boolean('teste')),
            'projetos' => $this->service->projetosDoOrientador($request->user(), $request->boolean('teste')),
        ];
    }
}
