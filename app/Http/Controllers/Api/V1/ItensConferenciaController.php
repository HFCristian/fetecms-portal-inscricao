<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoPessoaCredenciamento;
use App\Http\Controllers\Controller;
use App\Models\ItemConferencia;
use App\Services\ItensConferenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parametrização → **Itens do credenciamento e da checagem** (Sprint 181): a
 * lista única que os dois balcões conferem e que diz o que o orientador pode
 * mandar antes, pela aba Documentos.
 */
class ItensConferenciaController extends Controller
{
    public function __construct(private readonly ItensConferenciaService $itens) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->itens->criar($this->validar($request, true));

        return response()->json(['data' => $this->payload(), 'meta' => ['message' => 'Item criado.']], 201);
    }

    public function update(Request $request, ItemConferencia $item): JsonResponse
    {
        $this->itens->atualizar($item, $this->validar($request, false));

        return response()->json(['data' => $this->payload(), 'meta' => ['message' => 'Item atualizado.']]);
    }

    public function destroy(ItemConferencia $item): JsonResponse
    {
        $this->itens->excluir($item);

        return response()->json(['data' => $this->payload(), 'meta' => ['message' => 'Item excluído.']]);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, bool $criando): array
    {
        $exige = $criando ? 'required' : 'sometimes';

        return $request->validate([
            'nome' => [$exige, 'string', 'max:120'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:255'],
            'abrangencia' => [$exige, Rule::in([ItemConferencia::PESSOA, ItemConferencia::PROJETO])],
            'papeis' => ['sometimes', 'nullable', 'array'],
            'papeis.*' => [Rule::in(TipoPessoaCredenciamento::valores())],
            'no_credenciamento' => ['sometimes', 'boolean'],
            'na_checagem' => ['sometimes', 'boolean'],
            'receber_antes' => ['sometimes', 'boolean'],
            'ativo' => ['sometimes', 'boolean'],
            'ordem' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ], [], [
            'nome' => 'nome', 'descricao' => 'descrição', 'abrangencia' => 'de quem é',
            'papeis' => 'papéis', 'receber_antes' => 'receber antes',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'itens' => $this->itens->listar(),
            'papeis' => TipoPessoaCredenciamento::opcoes(),
        ];
    }
}
