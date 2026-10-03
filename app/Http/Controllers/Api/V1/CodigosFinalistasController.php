<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ListaFinal;
use App\Services\CodigosFinalistasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lista final → **Código do projeto** (Sprint 159): fixar os códigos e
 * mandá-los por e-mail à equipe de cada finalista.
 */
class CodigosFinalistasController extends Controller
{
    public function __construct(private readonly CodigosFinalistasService $service) {}

    public function show(ListaFinal $lista): JsonResponse
    {
        return response()->json(['data' => $this->service->painel($lista)]);
    }

    public function fixar(Request $request, ListaFinal $lista): JsonResponse
    {
        $gravados = $this->service->fixar($lista, $request->user());

        return response()->json([
            'data' => $this->service->painel($lista->fresh()),
            'meta' => ['message' => $gravados === 0
                ? 'Os códigos já estavam fixados.'
                : "{$gravados} código(s) fixado(s). Daqui em diante eles não mudam."],
        ]);
    }

    public function enviar(Request $request, ListaFinal $lista): JsonResponse
    {
        $mala = $this->service->enviar($lista, $request->user());

        return response()->json([
            'data' => $this->service->painel($lista->fresh()),
            'meta' => [
                'message' => 'Envio iniciado: os e-mails saem pela fila. Acompanhe o relatório na mala direta.',
                'mala_id' => $mala->id,
            ],
        ], 201);
    }
}
