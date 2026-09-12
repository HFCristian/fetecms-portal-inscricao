<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EstandesProjetosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Mapa do Evento → **Estandes dos Projetos**.
 *
 * Em que número cada projeto fica, turno por turno. A entrada é a lista de
 * turnos; as regras são as faixas de estande de cada categoria.
 */
class MapaEstandesController extends Controller
{
    public function __construct(private readonly EstandesProjetosService $estandes) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->estandes->painel()]);
    }

    public function salvarConfig(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->estandes->salvarConfig($this->validar($request)),
            'meta' => ['message' => 'Faixas de estande salvas.'],
        ]);
    }

    public function gerar(Request $request): JsonResponse
    {
        $resultado = $this->estandes->gerar($this->validar($request), $request->user());
        $avisos = $resultado['avisos'];

        return response()->json([
            'data' => $resultado['lista'],
            'meta' => [
                'avisos' => $avisos,
                'message' => $avisos === []
                    ? 'Estandes distribuídos.'
                    : 'Estandes distribuídos, com ressalvas — veja os avisos abaixo.',
            ],
        ]);
    }

    /** Move um projeto de estande; ocupado, os dois trocam de lugar. */
    public function mover(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'projeto_id' => ['required', 'integer'],
            'numero' => ['required', 'integer', 'min:1', 'max:5000'],
        ]);

        return response()->json([
            'data' => $this->estandes->mover((int) $dados['projeto_id'], (int) $dados['numero'], $request->user()),
            'meta' => ['message' => 'Projeto movido de estande.'],
        ]);
    }

    public function exportar(string $formato): Response
    {
        return match ($formato) {
            'txt' => response($this->estandes->exportarTxt(), 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="estandes-projetos.txt"',
            ]),
            'csv' => response($this->estandes->exportarCsv(), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="estandes-projetos.csv"',
            ]),
            default => response($this->estandes->exportarPdf(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="estandes-projetos.pdf"',
            ]),
        };
    }

    /**
     * As faixas vindas da tela. O texto da faixa é validado no service, que é
     * quem sabe lê-lo — aqui basta garantir a forma.
     *
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        return $request->validate([
            'regras' => ['present', 'array'],
            'regras.*.ativa' => ['boolean'],
            'regras.*.faixa' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
