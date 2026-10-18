<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LiberacaoPresencialService;
use App\Services\TurnosPresenciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação presencial → **Validação de avaliadores** (Sprint 223): quem
 * confirmou o presencial só avalia depois de liberado pela organização.
 */
class ValidacaoPresencialController extends Controller
{
    public function __construct(
        private readonly LiberacaoPresencialService $liberacao,
        private readonly TurnosPresenciaisService $turnos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'situacao' => ['nullable', 'in:todos,liberados,aguardando'],
        ]);

        return response()->json(['data' => $this->painel($request, $filtros)]);
    }

    /** Libera um ou mais avaliadores para o evento inteiro. */
    public function liberar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'avaliador_ids' => ['required', 'array', 'min:1', 'max:500'],
            'avaliador_ids.*' => ['integer'],
        ]);

        $resultado = $this->liberacao->liberar(
            array_map('intval', $dados['avaliador_ids']),
            $request->user(),
            $this->turnos->emTeste($request->user(), $request->boolean('teste')),
        );

        return response()->json([
            'data' => $this->painel($request, $request->only(['busca', 'situacao'])),
            'meta' => [
                'message' => "{$resultado['liberados']} avaliador(es) liberado(s) para a avaliação presencial.",
                'resultado' => $resultado,
            ],
        ]);
    }

    /** Revoga a liberação; o que estava só designado volta ao bolo. */
    public function revogar(Request $request, User $avaliador): JsonResponse
    {
        $resultado = $this->liberacao->revogar($avaliador);

        return response()->json([
            'data' => $this->painel($request, $request->only(['busca', 'situacao'])),
            'meta' => [
                'message' => "Liberação de {$avaliador->name} revogada."
                    .($resultado['devolvidas'] > 0 ? " {$resultado['devolvidas']} designação(ões) ainda não aberta(s) voltaram ao bolo." : ''),
                'resultado' => $resultado,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function painel(Request $request, array $filtros): array
    {
        $demo = $this->turnos->emTeste($request->user(), $request->boolean('teste'));

        return [
            ...$this->liberacao->listar($filtros, $demo),
            'modo_teste' => $demo,
            'is_demo' => (bool) $request->user()?->is_demo,
        ];
    }
}
