<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TurnosPresenciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parametrização → Datas e períodos → **Turnos da avaliação presencial**
 * (Sprint 224): o horário de cada turno em cada dia do evento, e o da
 * avaliação dentro dele.
 */
class ParametrizacaoAgendaPresencialController extends Controller
{
    public function __construct(private readonly TurnosPresenciaisService $turnos) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->turnos->agenda()]);
    }

    /** Substitui a agenda inteira. */
    public function definir(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'agenda' => ['present', 'array'],
        ]);

        $this->turnos->salvarAgenda($dados['agenda']);

        return response()->json([
            'data' => $this->turnos->agenda(),
            'meta' => ['message' => 'Horários dos turnos salvos.'],
        ]);
    }
}
