<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListaFinalRequest;
use App\Models\Edicao;
use App\Models\ListaPresencial;
use App\Models\ListaPresencialPremio;
use App\Models\Projeto;
use App\Services\ListaPresencialService;
use App\Services\TurnosPresenciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ranking presencial → **listas preliminares e finais** (Sprint 251).
 *
 * Da organização: as rotas ficam sob `admin.permanente`, como o Ver avaliações.
 * O modo de teste (conta demo) escolhe a trilha — a lista final demo e as
 * listas presenciais de ensaio — na criação e na relação; uma lista já criada
 * carrega a trilha dela.
 */
class ListaPresencialController extends Controller
{
    public function __construct(
        private readonly ListaPresencialService $listas,
        private readonly TurnosPresenciaisService $turnos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->listas->listas($this->emTeste($request))]);
    }

    public function opcoes(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->listas->opcoes($this->emTeste($request))]);
    }

    /** Monta o rascunho pela classificação (as cotas da tela). */
    public function store(ListaFinalRequest $request): JsonResponse
    {
        $lista = $this->listas->rascunhar(
            $request->cotas(),
            $request->user(),
            $request->input('nome'),
            (string) $request->input('tipo', ListaPresencial::TIPO_PRELIMINAR),
            $request->input('descricao'),
            $this->emTeste($request),
        );

        return response()->json([
            'data' => $this->listas->detalhar($lista),
            'meta' => ['message' => 'Rascunho montado. Revise e gere a lista.'],
        ], 201);
    }

    /** Monta o rascunho pela união de listas presenciais já geradas. */
    public function uniao(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'listas' => ['required', 'array', 'min:1'],
            'listas.*' => ['integer'],
            'tipo' => ['required', Rule::in([ListaPresencial::TIPO_PRELIMINAR, ListaPresencial::TIPO_FINAL])],
            'nome' => ['nullable', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:1000'],
        ]);

        $lista = $this->listas->rascunharDeListas(
            array_map('intval', $dados['listas']),
            $dados['tipo'],
            $request->user(),
            $dados['nome'] ?? null,
            $dados['descricao'] ?? null,
            $this->emTeste($request),
        );

        return response()->json([
            'data' => $this->listas->detalhar($lista),
            'meta' => ['message' => 'Rascunho montado a partir das listas escolhidas.'],
        ], 201);
    }

    public function show(ListaPresencial $lista): JsonResponse
    {
        return response()->json(['data' => $this->listas->detalhar($this->daEdicao($lista))]);
    }

    public function rotular(Request $request, ListaPresencial $lista): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['nullable', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:1000'],
        ]);

        $lista = $this->listas->rotular($this->daEdicao($lista), $dados['nome'] ?? null, $dados['descricao'] ?? null);

        return response()->json([
            'data' => $this->listas->detalhar($lista),
            'meta' => ['message' => 'Apelido e descrição salvos.'],
        ]);
    }

    public function gerar(Request $request, ListaPresencial $lista): JsonResponse
    {
        $lista = $this->listas->gerar($this->daEdicao($lista), $request->user());

        return response()->json([
            'data' => $this->listas->detalhar($lista),
            'meta' => ['message' => $lista->ehFinal()
                ? 'Lista final gerada. Ela é agora a lista final presencial ativa.'
                : 'Lista preliminar gerada.'],
        ]);
    }

    public function reativar(Request $request, ListaPresencial $lista): JsonResponse
    {
        $dados = $request->validate(['justificativa' => ['required', 'string', 'min:5', 'max:1000']]);

        $lista = $this->listas->reativar($this->daEdicao($lista), $request->user(), $dados['justificativa']);

        return response()->json([
            'data' => $this->listas->detalhar($lista),
            'meta' => ['message' => 'Esta é agora a lista final presencial ativa.'],
        ]);
    }

    /** Inclui um finalista na seção por nota (justificativa depois de gerada). */
    public function adicionar(Request $request, ListaPresencial $lista): JsonResponse
    {
        $dados = $request->validate([
            'projeto_id' => ['required', 'integer', 'exists:projetos,id'],
            'justificativa' => [$lista->rascunho ? 'nullable' : 'required', 'string', 'min:5', 'max:1000'],
        ]);

        $lista = $this->listas->adicionarProjeto(
            $this->daEdicao($lista), Projeto::findOrFail($dados['projeto_id']), $request->user(), $dados['justificativa'] ?? null,
        );

        return response()->json(['data' => $this->listas->detalhar($lista)]);
    }

    /** Retira um projeto da seção por nota (justificativa depois de gerada). */
    public function remover(Request $request, ListaPresencial $lista, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate([
            'justificativa' => [$lista->rascunho ? 'nullable' : 'required', 'string', 'min:5', 'max:1000'],
        ]);

        $lista = $this->listas->removerProjeto($this->daEdicao($lista), $projeto, $request->user(), $dados['justificativa'] ?? null);

        return response()->json(['data' => $this->listas->detalhar($lista)]);
    }

    /** Inclui um finalista numa credencial ou prêmio da lista (Sprint 253). */
    public function adicionarNaPremiacao(Request $request, ListaPresencial $lista, ListaPresencialPremio $premio): JsonResponse
    {
        $dados = $request->validate([
            'projeto_id' => ['required', 'integer', 'exists:projetos,id'],
            'justificativa' => [$lista->rascunho ? 'nullable' : 'required', 'string', 'min:5', 'max:1000'],
        ]);

        $lista = $this->listas->adicionarNaPremiacao(
            $this->daEdicao($lista), $premio, Projeto::findOrFail($dados['projeto_id']), $request->user(), $dados['justificativa'] ?? null,
        );

        return response()->json(['data' => $this->listas->detalhar($lista)]);
    }

    /** Retira um projeto de uma credencial ou prêmio da lista. */
    public function removerDaPremiacao(Request $request, ListaPresencial $lista, ListaPresencialPremio $premio, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate([
            'justificativa' => [$lista->rascunho ? 'nullable' : 'required', 'string', 'min:5', 'max:1000'],
        ]);

        $lista = $this->listas->removerDaPremiacao($this->daEdicao($lista), $premio, $projeto, $request->user(), $dados['justificativa'] ?? null);

        return response()->json(['data' => $this->listas->detalhar($lista)]);
    }

    /** Troca a seção de premiações pelo que está agora em Credenciais e Prêmios. */
    public function recarregarPremiacoes(Request $request, ListaPresencial $lista): JsonResponse
    {
        $dados = $request->validate([
            'justificativa' => [$lista->rascunho ? 'nullable' : 'required', 'string', 'min:5', 'max:1000'],
        ]);

        $lista = $this->listas->recarregarPremiacoes($this->daEdicao($lista), $request->user(), $dados['justificativa'] ?? null);

        return response()->json([
            'data' => $this->listas->detalhar($lista),
            'meta' => ['message' => 'Premiações recarregadas de Credenciais e Prêmios.'],
        ]);
    }

    public function exportar(ListaPresencial $lista, string $formato): Response
    {
        $arquivo = $this->listas->exportar($this->daEdicao($lista), $formato);

        return response($arquivo['conteudo'], 200, [
            'Content-Type' => $arquivo['tipo'],
            'Content-Disposition' => 'attachment; filename="'.$arquivo['nome'].'"',
        ]);
    }

    /** A apresentação da cerimônia em .pptx (Sprint 254), só de lista gerada. */
    public function slides(ListaPresencial $lista): BinaryFileResponse
    {
        $arquivo = $this->listas->slides($this->daEdicao($lista));

        return response()->download($arquivo['caminho'], $arquivo['nome'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ])->deleteFileAfterSend();
    }

    /** Lista de outra edição não existe para quem está nesta. */
    private function daEdicao(ListaPresencial $lista): ListaPresencial
    {
        abort_unless($lista->edicao_id === Edicao::atual()?->id, 404);

        return $lista;
    }

    private function emTeste(Request $request): bool
    {
        return $this->turnos->emTeste($request->user(), $request->boolean('teste'));
    }
}
