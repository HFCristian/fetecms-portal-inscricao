<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Edicao;
use App\Services\EdicaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Edições da feira.
 *
 * A parte de cima é de **todo mundo**: qualquer usuário lista as edições e
 * troca a sua, o que muda de uma vez os projetos, os prazos e os limites que
 * ele enxerga. A parte de baixo (`admin`) é a Parametrização → Edições: criar,
 * renomear, escolher a padrão e excluir uma edição vazia.
 */
class EdicaoController extends Controller
{
    public function __construct(private readonly EdicaoService $edicoes) {}

    /** O seletor de edição: o que existe e o que este usuário está vendo. */
    public function opcoes(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->edicoes->opcoesPara($request->user())]);
    }

    /** Troca a edição do usuário autenticado (null volta a seguir a padrão). */
    public function trocar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'edicao_id' => ['nullable', 'integer', 'exists:edicoes,id'],
        ]);

        $this->edicoes->trocarDoUsuario($request->user(), $dados['edicao_id'] ?? null);

        return response()->json(['data' => $this->edicoes->opcoesPara($request->user()->fresh())]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->edicoes->listar()]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate($this->regras(), $this->mensagens());

        $this->edicoes->criar($dados);

        return response()->json(['data' => $this->edicoes->listar()], 201);
    }

    public function update(Request $request, Edicao $edicao): JsonResponse
    {
        $dados = $request->validate($this->regras($edicao), $this->mensagens());

        $this->edicoes->atualizar($edicao, $dados);

        return response()->json(['data' => $this->edicoes->listar()]);
    }

    /** Marca esta edição como a padrão de todo o portal. */
    public function padrao(Edicao $edicao): JsonResponse
    {
        $this->edicoes->definirPadrao($edicao);

        return response()->json(['data' => $this->edicoes->listar()]);
    }

    public function destroy(Edicao $edicao): JsonResponse
    {
        $this->edicoes->excluir($edicao);

        return response()->json(['data' => $this->edicoes->listar()]);
    }

    /** @return array<string, mixed> */
    private function regras(?Edicao $edicao = null): array
    {
        return [
            'nome' => [
                $edicao ? 'sometimes' : 'required', 'string', 'max:120',
                // Nome + ano identificam a edição; repetir os dois confundiria
                // o seletor de todo mundo.
                Rule::unique('edicoes', 'nome')
                    ->where(fn ($q) => $q->where('ano', request('ano', $edicao?->ano)))
                    ->ignore($edicao?->id),
            ],
            'ano' => [$edicao ? 'sometimes' : 'required', 'integer', 'min:2000', 'max:2100'],
            'inscricoes_abertas' => ['sometimes', 'boolean'],
            'copiar_de' => ['nullable', 'integer', 'exists:edicoes,id'],
        ];
    }

    /** @return array<string, string> */
    private function mensagens(): array
    {
        return [
            'nome.unique' => 'Já existe uma edição com este nome neste ano.',
            'ano.min' => 'Informe um ano válido.',
            'ano.max' => 'Informe um ano válido.',
        ];
    }
}
