<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Services\AdminRascunhoService;
use App\Services\InscricoesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Projetos em rascunho" (Projetos por área → botão que só aparece depois do
 * fim das inscrições): a lista das inscrições que ficaram pela metade, para o
 * admin terminar e submeter.
 *
 * Aqui só se LÊ. A edição reaproveita as telas e as rotas do orientador — o
 * admin passa pela Policy e não é barrado pelo prazo —, e a submissão sai pelo
 * `ProjetoSubmissaoController`, que exige a justificativa.
 */
class AdminRascunhoController extends Controller
{
    public function __construct(
        private readonly AdminRascunhoService $rascunhos,
        private readonly InscricoesService $inscricoes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'categoria' => ['nullable', 'string', 'in:'.implode(',', array_column(Categoria::cases(), 'value'))],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $pagina = $this->rascunhos->listar($validado, (int) ($validado['por_pagina'] ?? 25));

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'por_pagina' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'areas' => Area::query()->orderBy('nome')->get(['id', 'nome']),
                'categorias' => array_map(
                    fn (Categoria $c) => ['value' => $c->value, 'label' => $c->label()],
                    Categoria::cases(),
                ),
                // A tela usa isto para explicar por que o botão existe: o
                // recurso é o escape do prazo, não a rotina do dia a dia.
                'inscricoes' => $this->inscricoes->config(),
            ],
        ]);
    }
}
