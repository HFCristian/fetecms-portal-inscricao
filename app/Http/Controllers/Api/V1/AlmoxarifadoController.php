<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoPessoaCredenciamento;
use App\Http\Controllers\Controller;
use App\Models\AlmoxarifadoGuarda;
use App\Services\AlmoxarifadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Aba Almoxarifado: a guarda de volumes dos finalistas durante a feira.
 *
 * `teste=1` liga o modo de teste da conta demo: além de ignorar a janela do
 * evento, ele troca a lista final pela **demo** e isola os registros do ensaio,
 * para treinar o balcão nunca alcançar o material de um finalista de verdade.
 */
class AlmoxarifadoController extends Controller
{
    public function __construct(private readonly AlmoxarifadoService $almoxarifado) {}

    /** Janela do evento, lista vigente e modo de teste. */
    public function config(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->almoxarifado->config($request->user(), $request->boolean('teste')),
        ]);
    }

    /** A tabela de registros, com o resumo do que ainda está guardado. */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'situacao' => ['nullable', Rule::in(['guardados', 'retirados'])],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $pagina = $this->almoxarifado->listar(
            $filtros,
            (int) ($filtros['por_pagina'] ?? 25),
            $request->user(),
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'resumo' => $this->almoxarifado->resumo($filtros, $request->user(), $request->boolean('teste')),
                'config' => $this->almoxarifado->config($request->user(), $request->boolean('teste')),
            ],
        ]);
    }

    /**
     * Os finalistas que o balcão pode atender, com as pessoas de cada um — o
     * primeiro passo do assistente.
     */
    public function projetos(Request $request): JsonResponse
    {
        $dados = $request->validate(['busca' => ['nullable', 'string', 'max:120']]);

        return response()->json([
            'data' => $this->almoxarifado->projetos(
                $request->user(),
                $request->boolean('teste'),
                (string) ($dados['busca'] ?? ''),
            ),
        ]);
    }

    /** Grava a guarda confirmada no último passo do assistente. */
    public function store(Request $request): JsonResponse
    {
        $this->exigirBalcaoAberto($request);

        $dados = $request->validate([
            'projeto_id' => ['required', 'integer'],
            'responsavel_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'responsavel_id' => ['nullable', 'integer'],
            'itens' => ['required', 'array', 'min:1', 'max:50'],
            // Linha em branco é o normal num campo "um item por linha": ela é
            // descartada no serviço, que recusa só quando não sobra nada.
            'itens.*' => ['nullable', 'string', 'max:200'],
        ]);

        $guarda = $this->almoxarifado->registrar($dados, $request->user(), $request->boolean('teste'));

        return response()->json([
            'data' => $this->almoxarifado->detalhe($guarda),
            'meta' => ['message' => 'Material guardado.'],
        ], 201);
    }

    /** Um registro em detalhe (a tela de resumo e os diálogos). */
    public function show(Request $request, AlmoxarifadoGuarda $guarda): JsonResponse
    {
        $this->exigirMesmoLado($request, $guarda);

        return response()->json(['data' => $this->almoxarifado->detalhe($guarda)]);
    }

    /**
     * Retirada. Sem `itens`, sai tudo o que ainda está guardado (a completa);
     * com eles, só os escolhidos (a parcial).
     */
    public function retirar(Request $request, AlmoxarifadoGuarda $guarda): JsonResponse
    {
        $this->exigirMesmoLado($request, $guarda);

        $dados = $request->validate([
            'responsavel_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'responsavel_id' => ['nullable', 'integer'],
            'itens' => ['sometimes', 'array', 'min:1'],
            'itens.*' => ['integer'],
        ]);

        $atualizada = $this->almoxarifado->retirar($guarda, $dados, $request->user(), $request->boolean('teste'));

        return response()->json([
            'data' => $this->almoxarifado->detalhe($atualizada),
            'meta' => ['message' => 'Retirada registrada.'],
        ]);
    }

    /** Correção do registro: quem deixou e a composição dos itens. */
    public function update(Request $request, AlmoxarifadoGuarda $guarda): JsonResponse
    {
        $this->exigirMesmoLado($request, $guarda);

        $dados = $request->validate([
            'responsavel_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'responsavel_id' => ['nullable', 'integer'],
            'itens' => ['required', 'array', 'min:1', 'max:50'],
            'itens.*.id' => ['nullable', 'integer'],
            'itens.*.descricao' => ['nullable', 'string', 'max:200'],
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $atualizada = $this->almoxarifado->editar($guarda, $dados, $request->user(), $request->boolean('teste'));

        return response()->json([
            'data' => $this->almoxarifado->detalhe($atualizada),
            'meta' => ['message' => 'Registro corrigido.'],
        ]);
    }

    /** Exclusão com justificativa (soft delete: a trilha continua de pé). */
    public function destroy(Request $request, AlmoxarifadoGuarda $guarda): JsonResponse
    {
        $this->exigirMesmoLado($request, $guarda);

        $dados = $request->validate([
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->almoxarifado->excluir($guarda, $dados['justificativa'], $request->user(), $request->boolean('teste'));

        return response()->json(['meta' => ['message' => 'Registro excluído.']]);
    }

    /** O balcão fechado (fora da janela do evento) só deixa consultar. */
    private function exigirBalcaoAberto(Request $request): void
    {
        if (! $this->almoxarifado->podeOperar($request->user(), $request->boolean('teste'))) {
            throw ValidationException::withMessages([
                'almoxarifado' => $this->almoxarifado->motivoFechado(),
            ]);
        }
    }

    /**
     * Registro de ensaio só é acessível em modo de teste, e o de verdade só
     * fora dele — a mesma trava do credenciamento demo.
     */
    private function exigirMesmoLado(Request $request, AlmoxarifadoGuarda $guarda): void
    {
        abort_unless(
            $guarda->demo === $this->almoxarifado->emTeste($request->user(), $request->boolean('teste')),
            404,
            'Registro não encontrado.',
        );
    }
}
