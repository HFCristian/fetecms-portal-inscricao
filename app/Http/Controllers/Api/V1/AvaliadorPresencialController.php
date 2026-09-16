<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AvaliacaoPresencialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba "Presencial" do avaliador: a intenção de avaliar no dia da feira e, para
 * quem aceita, as orientações da organização.
 */
class AvaliadorPresencialController extends Controller
{
    public function __construct(private readonly AvaliacaoPresencialService $presencial) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->presencial->situacao($request->user(), $request->boolean('teste')),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $dados = $request->validate(['presencial' => ['required', 'boolean']]);

        $situacao = $this->presencial->responder(
            $request->user(),
            (bool) $dados['presencial'],
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $situacao,
            'meta' => ['message' => $dados['presencial']
                ? 'Participação presencial confirmada.'
                : 'Tudo bem — você pode mudar de ideia até o início do evento.'],
        ]);
    }
}
