<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Enums\SituacaoDocumento;
use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\ItemChecagemEstande;
use App\Models\Projeto;
use App\Services\AvaliacaoPresencialAdminService;
use App\Services\ChecagemEstandeService;
use App\Services\CredenciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aba **Avaliação presencial** (admin): a checagem dos estandes no dia da
 * feira, o espelho dela e as orientações que o avaliador presencial lê.
 */
class AvaliacaoPresencialAdminController extends Controller
{
    public function __construct(
        private readonly ChecagemEstandeService $checagem,
        private readonly CredenciaisService $credenciais,
        private readonly AvaliacaoPresencialAdminService $avaliacoes,
    ) {}

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

    // --- Avaliações presenciais (designação e acompanhamento) --------------

    /** As avaliações presenciais da edição, com quem pode receber estande. */
    public function avaliacoes(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'status' => ['nullable', Rule::enum(StatusAvaliacao::class)],
            'avaliador_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return response()->json([
            'data' => $this->avaliacoes->listar($filtros),
            'meta' => [
                'avaliadores' => $this->avaliacoes->avaliadoresConfirmados(),
                'finalistas' => $this->avaliacoes->finalistas(),
                'max_por_projeto' => AvaliacaoPresencial::MAX_POR_PROJETO,
            ],
        ]);
    }

    /** Designa estandes: N projetos × N avaliadores que confirmaram presença. */
    public function designarAvaliacoes(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'projeto_ids' => ['required', 'array', 'min:1'],
            'projeto_ids.*' => ['integer'],
            'avaliador_ids' => ['required', 'array', 'min:1'],
            'avaliador_ids.*' => ['integer'],
        ]);

        $resultado = $this->avaliacoes->designar(
            $dados['projeto_ids'],
            $dados['avaliador_ids'],
            $request->user(),
        );

        return response()->json([
            'data' => $resultado,
            'meta' => ['message' => $resultado['resumo']],
        ]);
    }

    /** Retira uma designação que ainda não virou nota. */
    public function retirarAvaliacao(AvaliacaoPresencial $avaliacao): JsonResponse
    {
        $this->avaliacoes->retirar($avaliacao);

        return response()->json(['data' => $this->avaliacoes->listar()]);
    }

    // --- Credenciais (vagas de premiação) ----------------------------------

    /** As credenciais da edição e os projetos que podem recebê-las. */
    public function credenciais(): JsonResponse
    {
        return response()->json([
            'data' => $this->credenciais->listar(),
            'meta' => ['candidatos' => $this->credenciais->candidatos()],
        ]);
    }

    public function criarCredencial(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'orgao' => ['nullable', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:500'],
            // Em branco = sem teto: nem todo órgão fecha o número antes.
            'vagas' => ['nullable', 'integer', 'min:1', 'max:999'],
            'ordem' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $this->credenciais->criar($dados);

        return response()->json(['data' => $this->credenciais->listar()], Response::HTTP_CREATED);
    }

    public function atualizarCredencial(Request $request, Credencial $credencial): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['sometimes', 'string', 'max:120'],
            'orgao' => ['nullable', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:500'],
            'vagas' => ['nullable', 'integer', 'min:1', 'max:999'],
            'ordem' => ['nullable', 'integer', 'min:0', 'max:999'],
            'ativa' => ['sometimes', 'boolean'],
        ]);

        $this->credenciais->atualizar($credencial, $dados);

        return response()->json(['data' => $this->credenciais->listar()]);
    }

    public function excluirCredencial(Credencial $credencial): JsonResponse
    {
        $this->credenciais->excluir($credencial);

        return response()->json(['data' => $this->credenciais->listar()]);
    }

    /** Anexa a credencial a um projeto finalista. */
    public function atribuirCredencial(Request $request, Credencial $credencial): JsonResponse
    {
        $dados = $request->validate([
            'projeto_id' => ['required', 'integer', 'exists:projetos,id'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $this->credenciais->atribuir(
            $credencial,
            Projeto::findOrFail($dados['projeto_id']),
            $request->user(),
            $dados['observacao'] ?? null,
        );

        return response()->json([
            'data' => $this->credenciais->listar(),
            'meta' => ['message' => 'Credencial anexada ao projeto.'],
        ]);
    }

    public function retirarCredencial(Credencial $credencial, Projeto $projeto): JsonResponse
    {
        $this->credenciais->retirar($credencial, $projeto);

        return response()->json([
            'data' => $this->credenciais->listar(),
            'meta' => ['message' => 'Credencial retirada do projeto.'],
        ]);
    }

    /** A lista de premiação: quem recebeu o quê. */
    public function premiacao(): JsonResponse
    {
        return response()->json(['data' => $this->credenciais->premiacao()]);
    }

    /** A mesma lista, em TXT, para a cerimônia. */
    public function premiacaoTxt(): Response
    {
        return response($this->credenciais->exportarTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lista-premiacao.txt"',
        ]);
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
