<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ContaTemporariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A **presença** de uma conta temporária: a pessoa marca no primeiro acesso do
 * turno e espera a organização confirmar.
 *
 * Fica fora do grupo `aba:` de propósito — enquanto a presença não é aprovada,
 * a conta não abre aba nenhuma, e é justamente aqui que ela precisa conseguir
 * entrar para se anunciar.
 */
class PresencaContaTemporariaController extends Controller
{
    public function __construct(private readonly ContaTemporariaService $contas) {}

    /** O estado da própria presença (null para quem não é conta temporária). */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->contas->presencaDe($request->user())]);
    }

    /** Marca presença no turno. */
    public function store(Request $request): JsonResponse
    {
        $conta = $request->user()->contaTemporaria;

        abort_if($conta === null, 403, 'Só contas temporárias marcam presença.');

        $this->contas->marcarPresenca($conta);

        return response()->json([
            'data' => $this->contas->presencaDe($request->user()->refresh()),
            'meta' => ['message' => 'Presença registrada — aguarde a confirmação da organização.'],
        ]);
    }
}
