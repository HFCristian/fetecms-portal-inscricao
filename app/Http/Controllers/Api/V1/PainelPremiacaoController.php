<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PainelPremiacaoService;
use Illuminate\Http\JsonResponse;

/**
 * O painel da premiação para a organização (Sprint 196): o que falta dar e o
 * ranking dos premiados por área. A comissão especial lê o mesmo pela aba
 * dela ({@see AvaliadorComiteController::painel()}).
 */
class PainelPremiacaoController extends Controller
{
    public function __invoke(PainelPremiacaoService $painel): JsonResponse
    {
        return response()->json(['data' => $painel->painel()]);
    }
}
