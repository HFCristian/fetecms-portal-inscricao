<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SaidaAntecipada;
use App\Services\SaidaAntecipadaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mapa do Evento → **Marcar saída** (Sprint 243): a equipe que chegou e foi
 * embora antes do fim do turno. A planta pinta o estande no dia da saída.
 */
class MapaSaidaController extends Controller
{
    public function __construct(private readonly SaidaAntecipadaService $saidas) {}

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'projeto_id' => ['required', 'integer'],
            'saiu_em' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ], [], ['saiu_em' => 'horário da saída', 'observacao' => 'observação']);

        $saida = $this->saidas->marcar(
            (int) $dados['projeto_id'],
            $dados['saiu_em'] ?? null,
            $dados['observacao'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => ['id' => $saida->id, 'projeto_id' => $saida->projeto_id, 'saiu_em' => $saida->saiu_em->toIso8601String()],
            'meta' => ['message' => $this->saidas->rotulo(CarbonImmutable::parse($saida->saiu_em)).': saída registrada.'],
        ], 201);
    }

    public function destroy(Request $request, SaidaAntecipada $saida): JsonResponse
    {
        // Projeto de outra edição some pelo escopo: a saída dele não é desta tela.
        abort_if($saida->projeto === null, 404);

        $this->saidas->desfazer($saida, $request->user());

        return response()->json(['data' => null, 'meta' => ['message' => 'Saída desfeita.']]);
    }
}
