<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Enums\SituacaoDocumento;
use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\ItemChecagemEstande;
use App\Models\Projeto;
use App\Services\ChecagemEstandeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aba **Avaliação presencial** (admin): a checagem dos estandes no dia da
 * feira, o espelho dela e as orientações que o avaliador presencial lê.
 */
class AvaliacaoPresencialAdminController extends Controller
{
    public function __construct(private readonly ChecagemEstandeService $checagem) {}

    /** Janela, lista em uso, catálogo de itens e as orientações publicadas. */
    public function config(Request $request): JsonResponse
    {
        $config = $this->checagem->config($request->user(), $request->boolean('teste'));

        return response()->json(['data' => [
            ...$config,
            'motivo_fechado' => $config['aberto']
                ? null
                : $this->checagem->motivoFechado($request->user(), $request->boolean('teste')),
            'informacoes_avaliador' => Edicao::atual()?->info_avaliacao_presencial,
            'areas' => Area::orderBy('nome')->get(['id', 'nome']),
            'categorias' => Categoria::opcoes(),
            'turnos' => Turno::opcoes(),
        ]]);
    }

    /** A lista de estandes a conferir, paginada. */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'categoria' => ['nullable', Rule::enum(Categoria::class)],
            'situacao' => ['nullable', Rule::in(['conferidos', 'pendentes'])],
            'turno' => ['nullable', Rule::enum(Turno::class)],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $teste = $request->boolean('teste');
        $pagina = $this->checagem->finalistas(
            $filtros,
            (int) ($filtros['por_pagina'] ?? 25),
            $request->user(),
            $teste,
        );

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'por_pagina' => $pagina->perPage(),
                'resumo' => $this->checagem->resumo($filtros, $request->user(), $teste),
            ],
        ]);
    }

    /** A ficha de um estande. */
    public function show(Request $request, Projeto $projeto): JsonResponse
    {
        return response()->json([
            'data' => $this->checagem->ficha($projeto, $request->user(), $request->boolean('teste')),
        ]);
    }

    /** Grava a conferência de um estande. */
    public function store(Request $request, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate([
            'itens' => ['nullable', 'array'],
            'itens.*.item_id' => ['required', 'integer', 'exists:itens_checagem_estande,id'],
            'itens.*.situacao' => ['required', Rule::enum(SituacaoDocumento::class)],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $ficha = $this->checagem->registrar(
            $projeto,
            $dados,
            $request->user(),
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $ficha,
            'meta' => ['message' => 'Checagem do estande registrada.'],
        ]);
    }

    /** O espelho: todos os finalistas com tudo o que foi conferido. */
    public function espelho(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'categoria' => ['nullable', Rule::enum(Categoria::class)],
            'situacao' => ['nullable', Rule::in(['conferidos', 'pendentes'])],
            'turno' => ['nullable', Rule::enum(Turno::class)],
        ]);

        return response()->json([
            'data' => $this->checagem->espelho($filtros, $request->user(), $request->boolean('teste')),
            'meta' => ['itens' => $this->checagem->config($request->user(), $request->boolean('teste'))['itens']],
        ]);
    }

    // --- Catálogo de itens conferidos --------------------------------------

    public function itens(): JsonResponse
    {
        return response()->json(['data' => $this->checagem->catalogo()]);
    }

    public function criarItem(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120', 'unique:itens_checagem_estande,nome'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $this->checagem->criarItem($dados);

        return response()->json(['data' => $this->checagem->catalogo()], 201);
    }

    public function atualizarItem(Request $request, ItemChecagemEstande $item): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['sometimes', 'string', 'max:120', Rule::unique('itens_checagem_estande', 'nome')->ignore($item->id)],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem' => ['nullable', 'integer', 'min:0', 'max:999'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        $this->checagem->atualizarItem($item, $dados);

        return response()->json(['data' => $this->checagem->catalogo()]);
    }

    public function excluirItem(ItemChecagemEstande $item): JsonResponse
    {
        $this->checagem->excluirItem($item);

        return response()->json(['data' => $this->checagem->catalogo()]);
    }

    /**
     * As orientações que o avaliador presencial lê depois de aceitar
     * (`edicoes.info_avaliacao_presencial`).
     */
    public function definirInformacoes(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'informacoes' => ['nullable', 'string', 'max:5000'],
        ]);

        $edicao = Edicao::atual();

        abort_if($edicao === null, 422, 'Nenhuma edição em curso.');

        $edicao->forceFill(['info_avaliacao_presencial' => $dados['informacoes'] ?: null])->save();

        return response()->json([
            'data' => ['informacoes_avaliador' => $edicao->info_avaliacao_presencial],
            'meta' => ['message' => 'Orientações salvas — elas aparecem para quem aceitou avaliar presencialmente.'],
        ]);
    }
}
