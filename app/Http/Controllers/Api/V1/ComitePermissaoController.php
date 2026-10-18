<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PedidoAvaliacaoComite;
use App\Services\PermissaoComiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comitê especial → Designações → **Pedidos de permissão** (Sprint 234): com a
 * regra "avaliar apenas projetos designados" ligada, o avaliador da comissão
 * pede para avaliar no estande um projeto fora das designações dele, e a
 * organização aprova ou recusa aqui.
 */
class ComitePermissaoController extends Controller
{
    public function __construct(private readonly PermissaoComiteService $permissoes) {}

    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'situacao' => ['nullable', Rule::in(['pendente', 'decididos'])],
        ]);

        return response()->json([
            'data' => $this->permissoes->listar($dados['situacao'] ?? null),
            'meta' => [
                'ativa' => $this->permissoes->ativa(),
                'pendentes' => $this->permissoes->pendentes(),
            ],
        ]);
    }

    public function decidir(Request $request, PedidoAvaliacaoComite $pedido): JsonResponse
    {
        $dados = $request->validate([
            'decisao' => ['required', Rule::in(['aprovar', 'recusar'])],
            'resposta' => ['nullable', 'string', 'max:500'],
        ], [
            'decisao.required' => 'Escolha aprovar ou recusar.',
            'decisao.in' => 'Escolha aprovar ou recusar.',
            'resposta.max' => 'A resposta pode ter até 500 caracteres.',
        ]);

        $aprovar = $dados['decisao'] === 'aprovar';
        $linha = $this->permissoes->decidir($pedido, $aprovar, $dados['resposta'] ?? null, $request->user());

        return response()->json([
            'data' => $linha,
            'meta' => ['message' => $aprovar
                ? "Permissão aprovada: {$linha['avaliador']['nome']} pode avaliar “{$linha['projeto']['titulo']}”."
                : 'Pedido recusado.'],
        ]);
    }
}
