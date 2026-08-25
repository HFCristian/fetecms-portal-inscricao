<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjetoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AplicarReclassificacaoRequest;
use App\Http\Requests\Admin\DesignarAvaliacaoRequest;
use App\Http\Requests\Admin\EncerramentoAvaliacaoRequest;
use App\Http\Requests\Admin\LiberacaoAvaliacaoRequest;
use App\Http\Requests\Admin\LimiteAvaliadorRequest;
use App\Http\Requests\Admin\ListarAvaliadoresRequest;
use App\Http\Requests\Admin\MinimosAvaliacaoRequest;
use App\Models\Projeto;
use App\Models\User;
use App\Services\AdminAvaliacaoService;
use App\Services\DistribuicaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas de "Avaliação online" (somente admin): visão dos avaliadores por área
 * e dos projetos submetidos por área. Ver AdminAvaliacaoService (E7).
 */
class AdminAvaliacaoController extends Controller
{
    public function __construct(
        private readonly AdminAvaliacaoService $service,
        private readonly DistribuicaoService $distribuicao,
    ) {}

    /** Avaliadores agrupados por área, com o progresso de avaliação de cada um. */
    public function avaliadores(ListarAvaliadoresRequest $request): JsonResponse
    {
        $filtros = $request->filtros();
        $pagina = $this->service->avaliadores($filtros, (int) ($request->validated('por_pagina') ?? 50));

        return response()->json([
            'data' => array_map(fn ($u) => $this->service->linhaAvaliador($u), $pagina->items()),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'por_pagina' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'areas' => $this->service->areasComAvaliador(),
                'ordenar' => $filtros['ordenar'],
                'direcao' => $filtros['direcao'],
            ],
        ]);
    }

    /** Lista enxuta (id, nome, área) para os seletores de designação. */
    public function avaliadoresOpcoes(): JsonResponse
    {
        return response()->json(['data' => $this->service->opcoesAvaliadores()]);
    }

    /** CSV da tabela de avaliadores, no mesmo recorte de filtros da tela. */
    public function exportarAvaliadores(ListarAvaliadoresRequest $request): Response
    {
        $csv = $this->service->exportarAvaliadoresCsv($request->filtros());

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="avaliadores-'.now()->format('Y-m-d-His').'.csv"',
        ]);
    }

    /** Configuração do período de avaliação (liberação + encerramento). */
    public function config(): JsonResponse
    {
        return response()->json(['data' => $this->service->config()]);
    }

    /** Define/remove a data de liberação da avaliação (edição atual). */
    public function definirLiberacao(LiberacaoAvaliacaoRequest $request): JsonResponse
    {
        $config = $this->service->definirLiberacao($request->validated('liberada_em'), $request->user());

        return response()->json(['data' => $config, 'meta' => ['message' => 'Liberação atualizada.']]);
    }

    /** Define/remove a data de encerramento da avaliação (edição atual). */
    public function definirEncerramento(EncerramentoAvaliacaoRequest $request): JsonResponse
    {
        $config = $this->service->definirEncerramento($request->validated('encerrada_em'), $request->user());

        return response()->json([
            'data' => $config,
            'meta' => ['message' => $config['encerrada_em_label']
                ? 'Encerramento da avaliação salvo.'
                : 'Encerramento removido — a avaliação segue aberta.'],
        ]);
    }

    /** Define os mínimos de avaliações (por avaliador e por projeto). */
    public function definirMinimos(MinimosAvaliacaoRequest $request): JsonResponse
    {
        $config = $this->service->definirMinimos($request->validated(), $request->user());

        return response()->json(['data' => $config, 'meta' => ['message' => 'Mínimos atualizados.']]);
    }

    /** Projetos submetidos por área, com realizadas/em avaliação/faltantes. */
    /** Projetos com sugestão de reclassificação de área/subárea (com filtros). */
    public function reclassificacoes(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
        ]);

        return response()->json(['data' => $this->service->reclassificacoesSugeridas($filtros)]);
    }

    /** Aceita as sugestões escolhidas, trocando a área/subárea dos projetos. */
    public function aplicarReclassificacoes(AplicarReclassificacaoRequest $request): JsonResponse
    {
        $aplicados = $this->service->aplicarReclassificacoes($request->validated('itens'));
        $total = count($aplicados);

        return response()->json([
            'data' => $aplicados,
            'meta' => ['message' => $total === 1
                ? 'Reclassificação aplicada em 1 projeto.'
                : "Reclassificação aplicada em {$total} projetos."],
        ]);
    }

    /** Ranking dos projetos já avaliados, pela média das notas finais. */
    public function ranking(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
        ]);

        return response()->json(['data' => $this->service->rankingProjetos($filtros)]);
    }

    public function projetos(): JsonResponse
    {
        return response()->json(['data' => $this->service->projetosSubmetidosPorArea()]);
    }

    /** Designa um projeto submetido a um avaliador ou a todos de uma área/subárea. */
    public function designar(DesignarAvaliacaoRequest $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $projeto->status === ProjetoStatus::Submetido,
            422,
            'Só é possível designar avaliações de projetos submetidos.'
        );

        $novas = $this->service->designar(
            $projeto,
            $request->validated('tipo'),
            (int) $request->validated('alvo_id'),
        );

        return response()->json([
            'data' => ['designadas' => $novas],
            'meta' => ['message' => $novas === 1 ? '1 designação criada.' : "{$novas} designações criadas."],
        ]);
    }

    /** Define ou remove (limite null) o limite individual de avaliações de um avaliador. */
    public function limitar(LimiteAvaliadorRequest $request, User $avaliador): JsonResponse
    {
        abort_unless($avaliador->isAvaliador(), 404, 'Avaliador não encontrado.');

        $limite = $request->validated('limite');
        $limite = $limite === null ? null : (int) $limite;
        $this->service->definirLimite($avaliador, $limite);

        return response()->json([
            'data' => ['limite' => $limite],
            'meta' => ['message' => $limite === null ? 'Limite removido.' : "Limite definido em {$limite}."],
        ]);
    }

    /** Marca/desmarca o avaliador como "demo" (fora do escopo real da avaliação). */
    public function demo(Request $request, User $avaliador): JsonResponse
    {
        abort_unless($avaliador->isAvaliador(), 404, 'Avaliador não encontrado.');
        $demo = $request->validate(['is_demo' => ['required', 'boolean']])['is_demo'];

        $this->service->definirDemo($avaliador, $demo);

        return response()->json([
            'data' => ['is_demo' => $demo],
            'meta' => ['message' => $demo ? 'Avaliador marcado como demo.' : 'Avaliador não é mais demo.'],
        ]);
    }

    /** Apaga todas as avaliações dos avaliadores demo (dados de teste). */
    public function limparTestes(): JsonResponse
    {
        $apagadas = $this->service->limparDadosDeTeste();

        return response()->json([
            'data' => ['apagadas' => $apagadas],
            'meta' => ['message' => "{$apagadas} avaliação(ões) de teste apagada(s)."],
        ]);
    }

    /** Roda a distribuição automática (idempotente) e devolve o relatório. */
    public function distribuir(): JsonResponse
    {
        $relatorio = $this->distribuicao->distribuir();

        return response()->json([
            'data' => $relatorio,
            'meta' => ['message' => "{$relatorio['designadas_criadas']} designação(ões) criada(s)."],
        ]);
    }
}
