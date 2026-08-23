<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Aviso;
use App\Services\AvisoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Card de aviso do lado de quem lê. O front consulta de tempos em tempos;
 * quando há aviso no ar e a pessoa ainda não o fechou, ele vem aqui.
 */
class AvisoController extends Controller
{
    public function __construct(private readonly AvisoService $avisos) {}

    /** O aviso que este usuário deve ver agora (ou null). */
    public function ativo(Request $request): JsonResponse
    {
        $aviso = $this->avisos->paraUsuario($request->user());

        return response()->json([
            'data' => $aviso ? $this->avisos->paraTela($aviso) : null,
        ]);
    }

    /** O card chegou à tela desta pessoa. */
    public function visto(Request $request, Aviso $aviso): JsonResponse
    {
        $this->avisos->registrarVisto($aviso, $request->user());

        return response()->json(['data' => ['id' => $aviso->id, 'visto' => true]]);
    }

    /** A pessoa fechou o card — ele não volta mais para ela. */
    public function fechar(Request $request, Aviso $aviso): JsonResponse
    {
        $this->avisos->registrarFechado($aviso, $request->user());

        return response()->json(['data' => ['id' => $aviso->id, 'fechado' => true]]);
    }
}
