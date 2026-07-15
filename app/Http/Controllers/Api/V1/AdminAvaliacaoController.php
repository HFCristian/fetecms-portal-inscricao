<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjetoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DesignarAvaliacaoRequest;
use App\Models\Projeto;
use App\Services\AdminAvaliacaoService;
use Illuminate\Http\JsonResponse;

/**
 * Telas de "Avaliação online" (somente admin): visão dos avaliadores por área
 * e dos projetos submetidos por área. Ver AdminAvaliacaoService (E7).
 */
class AdminAvaliacaoController extends Controller
{
    public function __construct(private readonly AdminAvaliacaoService $service) {}

    /** Avaliadores agrupados por área, com o progresso de avaliação de cada um. */
    public function avaliadores(): JsonResponse
    {
        return response()->json(['data' => $this->service->avaliadoresPorArea()]);
    }

    /** Projetos submetidos por área, com realizadas/em avaliação/faltantes. */
    public function projetos(): JsonResponse
    {
        return response()->json(['data' => $this->service->projetosSubmetidosPorArea()]);
    }

    /** Designa um projeto submetido a um avaliador ou a todos de uma área/subárea. */
    public function designar(DesignarAvaliacaoRequest $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $projeto->status === ProjetoStatus::Submetido,
            422,
            'Só é possível designar avaliações de projetos submetidos.'
        );

        $novas = $this->service->designar(
            $projeto,
            $request->validated('tipo'),
            (int) $request->validated('alvo_id'),
        );

        return response()->json([
            'data' => ['designadas' => $novas],
            'meta' => ['message' => $novas === 1 ? '1 designação criada.' : "{$novas} designações criadas."],
        ]);
    }
}
