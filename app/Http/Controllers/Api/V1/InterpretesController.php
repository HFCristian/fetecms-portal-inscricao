<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SalvarInterpreteRequest;
use App\Models\Interprete;
use App\Models\SuporteProjeto;
use App\Services\InterpretesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Avaliação presencial → **Intérpretes**: o cadastro, os vínculos, o mapa de
 * designação e a exportação (lista e etiquetas). Só admin permanente — é dado
 * pessoal, e a conta temporária da porta só registra a chegada.
 */
class InterpretesController extends Controller
{
    public function __construct(private readonly InterpretesService $interpretes) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->interpretes->painel()]);
    }

    /** Finalistas com as pessoas, para escolher o projeto inteiro ou alguém dele. */
    public function finalistas(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->interpretes->finalistas((string) $request->query('q', '')),
        ]);
    }

    public function store(SalvarInterpreteRequest $request): JsonResponse
    {
        $interprete = $this->interpretes->criar($request->validated(), $request->user());

        return response()->json([
            'data' => $this->interpretes->linha($interprete, $this->interpretes->contexto()),
            'meta' => ['message' => 'Intérprete cadastrado.'],
        ], 201);
    }

    public function update(SalvarInterpreteRequest $request, Interprete $interprete): JsonResponse
    {
        $interprete = $this->interpretes->atualizar($interprete, $request->validated(), $request->user());

        return response()->json([
            'data' => $this->interpretes->linha($interprete, $this->interpretes->contexto()),
            'meta' => ['message' => 'Intérprete atualizado.'],
        ]);
    }

    public function destroy(Request $request, Interprete $interprete): JsonResponse
    {
        $this->interpretes->excluir($interprete, $request->user());

        return response()->json(['data' => null, 'meta' => ['message' => 'Intérprete excluído.']]);
    }

    /** Atende um pedido de intérprete da aba Suporte no evento. */
    public function atenderPedido(Request $request, Interprete $interprete, SuporteProjeto $suporte): JsonResponse
    {
        $this->interpretes->atenderPedido($interprete, $suporte, $request->user());

        return response()->json([
            'data' => $this->interpretes->painel(),
            'meta' => ['message' => 'Intérprete vinculado ao pedido.'],
        ]);
    }

    public function mapa(): JsonResponse
    {
        return response()->json(['data' => $this->interpretes->mapa()]);
    }

    /** Designa (ou tira) o intérprete no estande de um turno. */
    public function designar(Request $request, Interprete $interprete): JsonResponse
    {
        $dados = $request->validate([
            'numero' => ['required', 'integer', 'min:1', 'max:5000'],
            'turno' => ['required', Rule::in(Turno::valores())],
            'designado' => ['required', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->interpretes->designar(
                $interprete,
                (int) $dados['numero'],
                Turno::from($dados['turno']),
                (bool) $dados['designado'],
                $request->user(),
            ),
        ]);
    }

    /** A lista com os códigos, em Excel ou CSV. */
    public function exportar(string $formato): Response
    {
        $arquivo = $this->interpretes->exportar($formato);

        return response($arquivo['conteudo'], 200, [
            'Content-Type' => $arquivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$arquivo['nome'].'"',
        ]);
    }

    /** As etiquetas de identificação em PDF — todas, ou as dos ids pedidos. */
    public function etiquetas(Request $request): Response
    {
        $dados = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);

        return response($this->interpretes->etiquetasPdf(isset($dados['ids']) ? array_map('intval', $dados['ids']) : null), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="etiquetas-interpretes.pdf"',
        ]);
    }
}
