<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Services\RemanejamentoMapaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mapa do Evento → **remanejar sem refazer**: incluir no mapa o finalista que
 * chegou depois, mover de turno e de estande, trocar dois projetos de lugar e
 * tirar do mapa quem saiu da lista final. As duas telas (Turnos e planta) usam
 * estes endpoints.
 */
class MapaRemanejamentoController extends Controller
{
    public function __construct(private readonly RemanejamentoMapaService $remanejamento) {}

    /** O que está fora do lugar e o que o diálogo de destino precisa. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->remanejamento->painel()]);
    }

    /** Põe um projeto num lugar (turno + estande); ocupado, conforme o `modo`. */
    public function alocar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'projeto_id' => ['required', 'integer'],
            'turno' => ['required', Rule::in(Turno::valores())],
            'numero' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'modo' => ['nullable', Rule::in(RemanejamentoMapaService::MODOS)],
        ]);

        $resultado = $this->remanejamento->alocar(
            (int) $dados['projeto_id'],
            Turno::from($dados['turno']),
            isset($dados['numero']) ? (int) $dados['numero'] : null,
            $dados['modo'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $resultado,
            'meta' => ['message' => $this->mensagem($resultado['movimentos'])],
        ]);
    }

    /** Tira o projeto do mapa — o caminho de quem saiu da lista final. */
    public function retirar(Request $request): JsonResponse
    {
        $dados = $request->validate(['projeto_id' => ['required', 'integer']]);

        return response()->json([
            'data' => $this->remanejamento->retirar((int) $dados['projeto_id'], $request->user()),
            'meta' => ['message' => 'Projeto retirado do mapa.'],
        ]);
    }

    /** @param  list<array<string, mixed>>  $movimentos */
    private function mensagem(array $movimentos): string
    {
        if ($movimentos === []) {
            return 'O projeto já estava neste lugar.';
        }

        $ultimo = end($movimentos);

        return count($movimentos) === 1
            ? sprintf('"%s" foi para %s.', $ultimo['titulo'], $ultimo['para'])
            : sprintf('"%s" foi para %s, e %d outro(s) projeto(s) mudaram de lugar.', $ultimo['titulo'], $ultimo['para'], count($movimentos) - 1);
    }
}
