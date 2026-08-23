<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\InscricoesService;
use Illuminate\Http\JsonResponse;

/**
 * Prazo de inscrição visto por quem está logado — é o que explica, na tela do
 * orientador, por que os botões de editar/submeter sumiram.
 */
class InscricoesController extends Controller
{
    public function __construct(private readonly InscricoesService $inscricoes) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->inscricoes->config()]);
    }
}
