<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Enums\SituacaoDocumento;
use App\Enums\TipoPessoaCredenciamento;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Projeto;
use App\Services\CredenciamentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aba Credenciamento: o balcão do evento.
 *
 * "Credenciar" lista os finalistas (os projetos da lista final vigente) e
 * "Credenciados" mostra quem já passou — os dois saem da mesma consulta, só
 * muda o filtro de situação.
 *
 * `teste=1` liga o **modo de teste** do admin demo: além de ignorar a janela do
 * evento, ele troca a lista oficial pela lista **demo** da edição, para o
 * ensaio nunca tocar num finalista de verdade.
 */
class CredenciamentoController extends Controller
{
    public function __construct(private readonly CredenciamentoService $credenciamento) {}

    /** Janela do evento, itens a entregar e modo de teste do admin demo. */
    public function config(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->credenciamento->config($request->user(), $request->boolean('teste')),
        ]);
    }

    /** Finalistas com a situação de cada um (a mesma lista das duas seções). */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'categoria' => ['nullable', Rule::in(array_column(Categoria::cases(), 'value'))],
            'situacao' => ['nullable', Rule::in(['credenciados', 'pendentes'])],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $pagina = $this->credenciamento->finalistas(
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
                'resumo' => $this->credenciamento->resumo($filtros, $request->user(), $request->boolean('teste')),
                'areas' => Area::query()->orderBy('nome')->get(['id', 'nome']),
                'categorias' => Categoria::opcoes(),
                'config' => $this->credenciamento->config($request->user(), $request->boolean('teste')),
            ],
        ]);
    }

    /** A ficha de um finalista: cada pessoa com os documentos do papel dela. */
    public function show(Request $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $this->credenciamento->ehFinalista($projeto, $request->user(), $request->boolean('teste')),
            404,
            'Este projeto não está na lista final vigente.',
        );

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto, $request->user()) + [
                'config' => $this->credenciamento->config($request->user(), $request->boolean('teste')),
            ],
        ]);
    }

    /** Conclui o credenciamento do projeto com a conferência dos documentos. */
    public function store(Request $request, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate([
            'marcacoes' => ['present', 'array'],
            'marcacoes.*.documento_id' => ['required', 'integer', 'exists:documentos_credenciamento,id'],
            'marcacoes.*.pessoa_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'marcacoes.*.pessoa_id' => ['nullable', 'integer'],
            'marcacoes.*.situacao' => ['required', Rule::in(SituacaoDocumento::valores())],
            'observacao' => ['nullable', 'string', 'max:1000'],
            // Só chega quando o admin altera o horário sugerido: aí o fim vira
            // início + 5 minutos, em vez do instante da conclusão.
            'iniciado_em' => ['nullable', 'date'],
        ]);

        $this->credenciamento->registrar(
            $projeto,
            $request->user(),
            $dados['marcacoes'],
            $dados['observacao'] ?? null,
            $request->boolean('teste'),
            $dados['iniciado_em'] ?? null,
        );

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto->fresh(), $request->user()),
            'meta' => ['message' => 'Credenciamento concluído.'],
        ]);
    }

    /**
     * Cancela o credenciamento: a conferência é apagada e o projeto volta para
     * a fila de *Credenciar*. Mexer no credenciamento de outra conta exige
     * admin permanente — a regra vive no serviço.
     */
    public function cancelar(Request $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $this->credenciamento->ehFinalista($projeto, $request->user(), $request->boolean('teste')),
            404,
            'Este projeto não está na lista final vigente.',
        );

        $dados = $request->validate([
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->credenciamento->cancelar(
            $projeto,
            $request->user(),
            $dados['justificativa'],
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto->fresh(), $request->user()),
            'meta' => ['message' => 'Credenciamento cancelado. O projeto voltou para a fila do balcão.'],
        ]);
    }
}
