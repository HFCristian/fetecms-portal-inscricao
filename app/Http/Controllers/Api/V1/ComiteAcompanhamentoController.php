<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AcompanhamentoComiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Comitê especial → **Acompanhamento das avaliações**: cada avaliador da
 * comissão com o que está avaliando, e o mapa do evento só com as avaliações
 * do comitê; a tela recarrega sozinha. A única escrita é tirar de um avaliador
 * as designações que ele ainda não abriu.
 */
class ComiteAcompanhamentoController extends Controller
{
    public function __construct(private readonly AcompanhamentoComiteService $acompanhamento) {}

    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'turno' => ['nullable', Rule::in(Turno::valores())],
        ], ['turno.in' => 'Turno inválido.']);

        return response()->json(['data' => $this->acompanhamento->painel($dados['turno'] ?? null)]);
    }

    /** Retira do avaliador as designações escolhidas (online do comitê e no estande). */
    public function retirar(Request $request, User $avaliador): JsonResponse
    {
        $dados = $request->validate([
            'online_ids' => ['array', 'max:200', 'required_without:estande_ids'],
            'online_ids.*' => ['integer'],
            'estande_ids' => ['array', 'max:200', 'required_without:online_ids'],
            'estande_ids.*' => ['integer'],
        ], [
            'online_ids.required_without' => 'Escolha ao menos uma designação.',
            'estande_ids.required_without' => 'Escolha ao menos uma designação.',
        ]);

        $resultado = $this->acompanhamento->retirar(
            $avaliador,
            array_map('intval', $dados['online_ids'] ?? []),
            array_map('intval', $dados['estande_ids'] ?? []),
            $request->user(),
        );

        return response()->json([
            'data' => $resultado,
            'meta' => ['message' => $resultado['retiradas'].' designação(ões) retirada(s) de '.$avaliador->name.'.'],
        ]);
    }
}
