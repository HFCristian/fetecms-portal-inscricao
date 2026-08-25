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
 * Trilha de registros do painel do admin, em duas seções: "Inscrições"
 * (submissões, cancelamentos, exclusões e trocas de e-mail) e "Avaliação Online"
 * (mudanças de parâmetro do período). Filtra por tipo, período e busca, e exporta
 * o mesmo recorte que está na tela em CSV.
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
                // Só os tipos da seção aberta: as tags de filtro da tela saem daqui.
                'tipos' => TipoRegistro::opcoes($filtros['secao'] ?? null),
                'secao' => $filtros['secao'] ?? null,
            ],
        ]);
    }

    /** Baixa o CSV (UTF-8 com BOM, ";") com os filtros aplicados na tela. */
    public function exportar(ListarRegistrosRequest $request): Response
    {
        $filtros = $request->filtros();
        $csv = $this->registros->exportarCsv($filtros);
        $arquivo = 'registros-'.($filtros['secao'] ?? 'todos').'-'.now()->format('Y-m-d-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$arquivo.'"',
        ]);
    }
}
