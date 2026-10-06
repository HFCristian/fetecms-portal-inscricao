<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Enums\SituacaoDocumento;
use App\Enums\TipoCredencial;
use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\ItemChecagemEstande;
use App\Models\Projeto;
use App\Models\User;
use App\Services\ChecagemEstandeService;
use App\Services\CredenciaisService;
use App\Services\DistribuicaoPresencialService;
use App\Services\TurnosPresenciaisService;
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
        private readonly DistribuicaoPresencialService $distribuicao,
        private readonly TurnosPresenciaisService $turnos,
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

    // --- Distribuição presencial (Sprints 169–170) -------------------------

    /**
     * A tela da distribuição: horários dos turnos, a agenda, a ocorrência em
     * foco (dia × turno), quem está ativado nela, os projetos prontos e as
     * designações.
     */
    public function distribuicao(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'dia' => ['nullable', 'date_format:Y-m-d'],
            'turno' => ['nullable', Rule::enum(Turno::class)],
            'busca' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $this->distribuicao->painel(
            $request->user(), $request->boolean('teste'),
            $filtros['dia'] ?? null, $filtros['turno'] ?? null, $filtros['busca'] ?? null,
        )]);
    }

    /** Horário de cada turno e os dois números da distribuição. */
    public function salvarDistribuicao(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'horarios' => ['nullable', 'array'],
            'fila_avaliador' => ['nullable', 'integer', 'min:1', 'max:50'],
            'por_projeto' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $this->turnos->salvarConfig($dados);

        return response()->json([
            'data' => $this->distribuicao->painel($request->user(), $request->boolean('teste'), $request->input('dia'), $request->input('turno')),
            'meta' => ['message' => 'Turnos e números da distribuição salvos.'],
        ]);
    }

    /** Os turnos em que o avaliador está ativado (substitui a lista inteira). */
    public function turnosDoAvaliador(Request $request, User $avaliador): JsonResponse
    {
        $dados = $request->validate([
            'turnos' => ['present', 'array'],
            'turnos.*' => ['string', 'max:20'],
        ]);

        $this->turnos->definirTurnos($avaliador, $dados['turnos'], $request->user());

        return response()->json([
            'data' => $this->distribuicao->painel($request->user(), $request->boolean('teste'), $request->input('dia'), $request->input('turno')),
            'meta' => ['message' => 'Turnos do avaliador atualizados.'],
        ]);
    }

    public function distribuir(Request $request): JsonResponse
    {
        [$dia, $turno] = $this->ocorrencia($request);
        $resultado = $this->distribuicao->distribuir($dia, $turno, $this->turnos->emTeste($request->user(), $request->boolean('teste')));

        return $this->respostaDaOcorrencia($request, $dia, $turno, $resultado);
    }

    public function redistribuir(Request $request): JsonResponse
    {
        [$dia, $turno] = $this->ocorrencia($request);
        $resultado = $this->distribuicao->redistribuir($dia, $turno, $this->turnos->emTeste($request->user(), $request->boolean('teste')));

        return $this->respostaDaOcorrencia($request, $dia, $turno, $resultado);
    }

    /** Designação manual: N projetos × N avaliadores ativados na ocorrência. */
    public function designarAvaliacoes(Request $request): JsonResponse
    {
        [$dia, $turno] = $this->ocorrencia($request);
        $dados = $request->validate([
            'projeto_ids' => ['required', 'array', 'min:1'],
            'projeto_ids.*' => ['integer'],
            'avaliador_ids' => ['required', 'array', 'min:1'],
            'avaliador_ids.*' => ['integer'],
        ]);

        $resultado = $this->distribuicao->designar(
            $dados['projeto_ids'], $dados['avaliador_ids'], $dia, $turno,
            $this->turnos->emTeste($request->user(), $request->boolean('teste')),
        );

        return $this->respostaDaOcorrencia($request, $dia, $turno, $resultado);
    }

    /** Retira uma designação que ainda não virou nota. */
    public function retirarAvaliacao(Request $request, AvaliacaoPresencial $avaliacao): JsonResponse
    {
        $this->distribuicao->retirar($avaliacao);

        return response()->json([
            'data' => $this->distribuicao->painel(
                $request->user(), $request->boolean('teste'),
                $avaliacao->dia?->toDateString() ?? $request->input('dia'), $avaliacao->turno?->value ?? $request->input('turno'),
            ),
            'meta' => ['message' => 'Designação retirada.'],
        ]);
    }

    /** @return array{0: string, 1: Turno} */
    private function ocorrencia(Request $request): array
    {
        $dados = $request->validate([
            'dia' => ['required', 'date_format:Y-m-d'],
            'turno' => ['required', Rule::enum(Turno::class)],
        ]);

        return [$dados['dia'], Turno::from($dados['turno'])];
    }

    /** @param  array<string, mixed>  $resultado */
    private function respostaDaOcorrencia(Request $request, string $dia, Turno $turno, array $resultado): JsonResponse
    {
        return response()->json([
            'data' => $this->distribuicao->painel($request->user(), $request->boolean('teste'), $dia, $turno->value),
            'meta' => ['message' => $resultado['resumo'], 'resultado' => $resultado],
        ]);
    }

    // --- Credenciais (vagas de premiação) ----------------------------------

    /** As credenciais e prêmios da edição e os projetos que podem recebê-los. */
    public function credenciais(): JsonResponse
    {
        return response()->json([
            'data' => $this->credenciais->listar(),
            'meta' => [
                'candidatos' => $this->credenciais->candidatos(),
                'tipos' => TipoCredencial::opcoes(),
            ],
        ]);
    }

    public function criarCredencial(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tipo' => ['nullable', Rule::in(TipoCredencial::valores())],
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
            'tipo' => ['sometimes', Rule::in(TipoCredencial::valores())],
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
