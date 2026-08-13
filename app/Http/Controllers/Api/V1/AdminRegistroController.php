<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoRegistro;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListarRegistrosRequest;
use App\Http\Resources\RegistroAtividadeResource;
use App\Services\RegistroAtividadeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trilha de registros das submissões (painel do admin): submissões, cancelamentos,
 * exclusões e trocas de e-mail — com filtro por tipo, período e busca, e export CSV
 * do mesmo recorte que está na tela.
 */
class AdminRegistroController extends Controller
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    public function index(ListarRegistrosRequest $request): JsonResponse
    {
        $filtros = $request->filtros();
        $pagina = $this->registros->listar($filtros, (int) ($request->validated('por_pagina') ?? 25));

        return response()->json([
            'data' => RegistroAtividadeResource::collection($pagina->items())->resolve(),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'por_pagina' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'totais_por_tipo' => $this->registros->totaisPorTipo($filtros),
                'tipos' => TipoRegistro::opcoes(),
            ],
        ]);
    }

    /** Baixa o CSV (UTF-8 com BOM, ";") com os filtros aplicados na tela. */
    public function exportar(ListarRegistrosRequest $request): Response
    {
        $csv = $this->registros->exportarCsv($request->filtros());
        $arquivo = 'registros-'.now()->format('Y-m-d-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$arquivo.'"',
        ]);
    }
}
