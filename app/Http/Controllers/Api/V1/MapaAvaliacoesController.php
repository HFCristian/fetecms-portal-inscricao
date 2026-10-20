<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Services\MapaAvaliacoesService;
use App\Services\MapaSituacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Mapa do Evento → **avaliações por projeto** (Sprint 248): quais avaliações
 * faltam em cada projeto do turno, comuns e do comitê, no mapa e em lista.
 */
class MapaAvaliacoesController extends Controller
{
    public function __construct(
        private readonly MapaAvaliacoesService $avaliacoes,
        private readonly MapaSituacaoService $situacao,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'dia' => ['nullable', 'date'],
            'turno' => ['nullable', Rule::in(Turno::valores())],
        ]);

        return response()->json([
            'data' => $this->avaliacoes->painel($dados['dia'] ?? null, $dados['turno'] ?? null),
            'meta' => ['dias' => $this->situacao->dias(), 'turnos' => Turno::opcoes()],
        ]);
    }

    /** A lista ou o mapa em PDF, no recorte dos filtros da tela. */
    public function exportar(Request $request, string $tipo): Response
    {
        $filtros = $request->validate([
            'dia' => ['nullable', 'date'],
            'turno' => ['nullable', Rule::in(Turno::valores())],
            'categorias' => ['nullable', 'array', 'max:50'],
            'categorias.*' => ['string', 'max:40'],
            'em_andamento' => ['nullable', 'boolean'],
            // A ordem da tela vale para a lista em PDF (Sprint 249).
            'ordem' => ['nullable', Rule::in(['estande', 'situacao'])],
            'direcao' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $pdf = $tipo === 'mapa'
            ? $this->avaliacoes->exportarMapa($filtros)
            : $this->avaliacoes->exportarLista($filtros);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"avaliacoes-{$tipo}.pdf\"",
        ]);
    }
}
