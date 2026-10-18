<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Services\AcompanhamentoComiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comitê especial → **Acompanhamento das avaliações**: cada avaliador da
 * comissão com o que está avaliando, e o mapa do evento só com as avaliações
 * do comitê. Só leitura; a tela recarrega sozinha.
 */
class ComiteAcompanhamentoController extends Controller
{
    public function __construct(private readonly AcompanhamentoComiteService $acompanhamento) {}

    public function __invoke(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'turno' => ['nullable', Rule::in(Turno::valores())],
        ], ['turno.in' => 'Turno inválido.']);

        return response()->json(['data' => $this->acompanhamento->painel($dados['turno'] ?? null)]);
    }
}
