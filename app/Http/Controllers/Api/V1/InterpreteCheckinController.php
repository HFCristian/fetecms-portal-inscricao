<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Models\Interprete;
use App\Services\InterpreteCheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A chegada dos intérpretes, por dia × turno. Registrar é da porta (a conta
 * temporária da aba também); acompanhar e desfazer são da organização.
 */
class InterpreteCheckinController extends Controller
{
    public function __construct(private readonly InterpreteCheckinService $checkins) {}

    public function config(): JsonResponse
    {
        return response()->json(['data' => $this->checkins->config()]);
    }

    public function codigo(Request $request): JsonResponse
    {
        $dados = $this->ocorrencia($request) + $request->validate(['codigo' => ['required', 'string', 'max:60']]);

        return response()->json([
            'data' => $this->checkins->porCodigo($dados['codigo'], $dados['dia'], Turno::from($dados['turno']), $request->user()),
        ]);
    }

    public function buscar(Request $request): JsonResponse
    {
        $dados = $this->ocorrencia($request);

        return response()->json([
            'data' => $this->checkins->buscar((string) $request->query('q', ''), $dados['dia'], Turno::from($dados['turno'])),
        ]);
    }

    public function registrar(Request $request, Interprete $interprete): JsonResponse
    {
        $dados = $this->ocorrencia($request);

        return response()->json([
            'data' => $this->checkins->registrar($interprete, $dados['dia'], Turno::from($dados['turno']), $request->user()),
        ]);
    }

    public function desfazer(Request $request, Interprete $interprete): JsonResponse
    {
        $dados = $this->ocorrencia($request) + $request->validate([
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->checkins->desfazer($interprete, $dados['dia'], Turno::from($dados['turno']), $dados['justificativa'], $request->user());

        return response()->json(['data' => null, 'meta' => ['message' => 'Chegada desfeita.']]);
    }

    public function acompanhamento(Request $request): JsonResponse
    {
        $dados = $this->ocorrencia($request);

        return response()->json([
            'data' => $this->checkins->acompanhamento($dados['dia'], Turno::from($dados['turno'])),
        ]);
    }

    /** @return array{dia: string, turno: string} */
    private function ocorrencia(Request $request): array
    {
        return $request->validate([
            'dia' => ['required', 'date_format:Y-m-d'],
            'turno' => ['required', Rule::in(Turno::valores())],
        ]);
    }
}
