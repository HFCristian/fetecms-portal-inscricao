<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ListaFinal;
use App\Services\ExportacaoListaFinalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Lista final → **Exportar** (Sprint 160): o recorte que cada uso pede, por
 * pessoa ou por projeto, em CSV ou Excel.
 */
class ExportacaoListaFinalController extends Controller
{
    public function __construct(private readonly ExportacaoListaFinalService $service) {}

    public function opcoes(): JsonResponse
    {
        return response()->json(['data' => $this->service->opcoes()]);
    }

    public function exportar(Request $request, ListaFinal $lista): Response
    {
        $dados = $request->validate([
            'nivel' => ['required', Rule::in([ExportacaoListaFinalService::NIVEL_PESSOA, ExportacaoListaFinalService::NIVEL_PROJETO])],
            'colunas' => ['required', 'array', 'min:1'],
            'colunas.*' => ['string', 'max:40'],
            'formato' => ['required', Rule::in(['csv', 'xlsx'])],
            'modelo' => ['nullable', 'string', 'alpha_dash', 'max:30'],
        ]);

        $arquivo = $this->service->exportar($lista, $dados['nivel'], $dados['colunas'], $dados['formato'], $dados['modelo'] ?? null);

        return response($arquivo['conteudo'], 200, [
            'Content-Type' => $arquivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$arquivo['nome'].'"',
        ]);
    }
}
