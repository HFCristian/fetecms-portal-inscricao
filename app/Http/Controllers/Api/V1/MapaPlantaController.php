<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Models\MapaLayout;
use App\Services\MapaPlantaService;
use App\Services\MapaSituacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Mapa do Evento → **a planta do ginásio**.
 *
 * O desenho com a ocupação em cima: cada estande sabe quem apresenta nele de
 * manhã e à tarde. A planta é da edição e cada gravação cria uma versão nova.
 *
 * Durante o evento ela também **muda de cor** conforme o projeto anda —
 * credenciamento, checagem, avaliações —, e o mesmo recorte sai em lista
 * filtrável e exportável ({@see MapaSituacaoService}).
 */
class MapaPlantaController extends Controller
{
    public function __construct(
        private readonly MapaPlantaService $planta,
        private readonly MapaSituacaoService $situacao,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->planta->painel(),
            'meta' => ['filtros' => $this->situacao->opcoes()],
        ]);
    }

    /**
     * A cor de cada estande num turno, como estava no fim do dia escolhido.
     *
     * Endpoint próprio porque é o que a tela recarrega sozinha durante o
     * evento: mandar a planta inteira junto seria pagar o desenho a cada volta.
     */
    public function situacao(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'dia' => ['nullable', 'date'],
            'turno' => ['nullable', Rule::in(Turno::valores())],
        ]);

        return response()->json([
            'data' => $this->situacao->porEstande($dados['dia'] ?? null, $dados['turno'] ?? null),
        ]);
    }

    /** A lista filtrada por credenciamento, checagem ou número de avaliações. */
    public function lista(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->situacao->lista($this->filtros($request))]);
    }

    /** A mesma lista em arquivo. */
    public function exportar(Request $request, string $formato): Response
    {
        $filtros = $this->filtros($request);
        $nome = 'mapa-situacao';

        return match ($formato) {
            'txt' => response($this->situacao->exportarTxt($filtros), 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$nome}.txt\"",
            ]),
            'csv' => response($this->situacao->exportarCsv($filtros), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$nome}.csv\"",
            ]),
            default => response($this->situacao->exportarPdf($filtros), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$nome}.pdf\"",
            ]),
        };
    }

    /** @return array<string, mixed> */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'criterio' => ['nullable', Rule::in(array_keys(MapaSituacaoService::CRITERIOS))],
            // Sim/Não nos dois primeiros critérios, número nos dois de contagem.
            'valor' => ['nullable', 'string', 'max:10'],
            'dia' => ['nullable', 'date'],
            'turno' => ['nullable', Rule::in(Turno::valores())],
        ]);
    }

    public function salvar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['nullable', 'string', 'max:160'],
            // O nome de cada corredor, indexado pela chave que a detecção deu.
            'ruas' => ['array', 'max:80'],
            'ruas.*' => ['nullable', 'string', 'max:60'],
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
