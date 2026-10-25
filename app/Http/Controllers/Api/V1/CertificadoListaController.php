<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CertificadoListaRequest;
use App\Models\CertificadoLista;
use App\Models\Edicao;
use App\Services\CertificadoListaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Aba **Certificados** (Sprints 259–260): o assistente que monta a lista de
 * certificados, a conferência dos nomes e a lista gerada com as exportações.
 */
class CertificadoListaController extends Controller
{
    public function __construct(private readonly CertificadoListaService $service) {}

    public function opcoes(): JsonResponse
    {
        return response()->json(['data' => $this->service->opcoes()]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->listar()]);
    }

    public function store(CertificadoListaRequest $request): JsonResponse
    {
        $lista = $this->service->criar($request->validated(), $request->user());

        return response()->json(['data' => $this->service->detalhe($lista)], 201);
    }

    public function show(CertificadoLista $lista): JsonResponse
    {
        return response()->json(['data' => $this->service->detalhe($this->daEdicao($lista))]);
    }

    public function update(CertificadoListaRequest $request, CertificadoLista $lista): JsonResponse
    {
        $lista = $this->service->atualizar($this->daEdicao($lista), $request->validated());

        return response()->json(['data' => $this->service->detalhe($lista)]);
    }

    public function resumo(CertificadoLista $lista): JsonResponse
    {
        return response()->json(['data' => $this->service->resumo($this->daEdicao($lista))]);
    }

    public function pessoas(CertificadoLista $lista, string $categoria): JsonResponse
    {
        return response()->json(['data' => $this->service->pessoas($this->daEdicao($lista), $categoria)]);
    }

    public function nomes(Request $request, CertificadoLista $lista): JsonResponse
    {
        $dados = $request->validate([
            'nomes' => ['required', 'array', 'max:5000'],
            'nomes.*' => ['nullable', 'string', 'max:255'],
            'originais' => ['nullable', 'array'],
            'originais.*' => ['nullable', 'string', 'max:255'],
        ]);

        $chaves = array_filter(array_keys($dados['nomes']), fn ($c) => ! preg_match('/^(user|aluno|coorientador):\d+$/', (string) $c));
        abort_if($chaves !== [], 422, 'Pessoa não reconhecida na lista.');

        $lista = $this->service->salvarNomes($this->daEdicao($lista), $dados['nomes'], $dados['originais'] ?? []);

        return response()->json(['data' => $this->service->detalhe($lista)]);
    }

    public function gerar(Request $request, CertificadoLista $lista): JsonResponse
    {
        $lista = $this->service->gerar($this->daEdicao($lista), $request->user());

        return response()->json(['data' => $this->service->detalhe($lista)]);
    }

    public function linhas(Request $request, CertificadoLista $lista): JsonResponse
    {
        $dados = $request->validate([
            'categoria' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $lista = $this->daEdicao($lista);
        $pagina = $this->service->linhasGeradas($lista, $dados['categoria'] ?? null, $dados['q'] ?? '');

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'pagina' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'resumo' => $this->service->resumoGerado($lista),
            ],
        ]);
    }

    public function exportar(Request $request, CertificadoLista $lista, string $formato): Response
    {
        $dados = $request->validate(['categoria' => ['nullable', 'string', 'max:40']]);
        $arquivo = $this->service->exportar($this->daEdicao($lista), $formato, $dados['categoria'] ?? null);

        return response($arquivo['conteudo'], 200, [
            'Content-Type' => $arquivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$arquivo['nome'].'"',
        ]);
    }

    /** A lista é da edição em escopo; a de outra edição não existe aqui. */
    private function daEdicao(CertificadoLista $lista): CertificadoLista
    {
        abort_unless($lista->edicao_id === Edicao::atual()?->id, 404, 'Lista de certificados não encontrada.');

        return $lista;
    }
}
