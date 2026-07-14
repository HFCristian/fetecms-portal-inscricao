<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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

    /** Projetos submetidos por área, com o total de avaliações recebidas. */
    public function projetos(): JsonResponse
    {
        return response()->json(['data' => $this->service->projetosSubmetidosPorArea()]);
    }
}
