<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MapaLayout;
use App\Services\MapaPlantaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mapa do Evento → **a planta do ginásio**.
 *
 * O desenho com a ocupação em cima: cada estande sabe quem apresenta nele de
 * manhã e à tarde. A planta é da edição e cada gravação cria uma versão nova.
 */
class MapaPlantaController extends Controller
{
    public function __construct(private readonly MapaPlantaService $planta) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->planta->painel()]);
    }

    public function salvar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['nullable', 'string', 'max:160'],
            'estandes' => ['present', 'array', 'max:2000'],
            'estandes.*.numero' => ['required', 'integer', 'min:1', 'max:9999'],
            'estandes.*.x' => ['required', 'numeric', 'min:-100', 'max:500'],
            'estandes.*.y' => ['required', 'numeric', 'min:-100', 'max:500'],
            'marcacoes' => ['array', 'max:50'],
            'marcacoes.*.rotulo' => ['required', 'string', 'max:60'],
            'marcacoes.*.x' => ['required', 'numeric'],
            'marcacoes.*.y' => ['required', 'numeric'],
            'marcacoes.*.largura' => ['required', 'numeric'],
            'marcacoes.*.altura' => ['required', 'numeric'],
        ]);

        return response()->json([
            'data' => $this->planta->salvar($dados, $request->user()),
            'meta' => ['message' => 'Planta salva como uma versão nova.'],
        ]);
    }

    public function restaurar(Request $request, MapaLayout $layout): JsonResponse
    {
        return response()->json([
            'data' => $this->planta->restaurar($layout, $request->user()),
            'meta' => ['message' => "Planta da versão {$layout->versao} restaurada como versão nova."],
        ]);
    }
}
