<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AplicarReclassificacaoRequest;
use App\Http\Requests\Admin\DesignarAvaliacaoRequest;
use App\Http\Requests\Admin\EncerramentoAvaliacaoRequest;
use App\Http\Requests\Admin\LiberacaoAvaliacaoRequest;
use App\Http\Requests\Admin\LimiteAvaliadorRequest;
use App\Http\Requests\Admin\ListaFinalRequest;
use App\Http\Requests\Admin\ListarAvaliadoresRequest;
use App\Http\Requests\Admin\ListarProjetosAvaliacaoRequest;
use App\Http\Requests\Admin\MinimosAvaliacaoRequest;
use App\Http\Requests\Admin\RegrasDistribuicaoRequest;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Services\AdminAvaliacaoService;
use App\Services\DistribuicaoService;
use App\Services\ListaFinalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        private readonly ListaFinalService $listaFinal,
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
    public function avaliadoresOpcoes(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->opcoesAvaliadores($request->boolean('comissao')),
        ]);
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

    /** Define os limites de avaliações (mínimo/máximo por avaliador e por projeto). */
    public function definirMinimos(MinimosAvaliacaoRequest $request): JsonResponse
    {
        $config = $this->service->definirMinimos($request->limites(), $request->user());

        return response()->json(['data' => $config, 'meta' => ['message' => 'Limites atualizados.']]);
    }

    /** Regras do algoritmo de distribuição (uma por categoria). */
    public function distribuicaoConfig(): JsonResponse
    {
        return response()->json(['data' => $this->service->configDistribuicao()]);
    }

    /** Grava as regras do algoritmo de distribuição. */
    public function definirRegrasDistribuicao(RegrasDistribuicaoRequest $request): JsonResponse
    {
        $config = $this->service->definirRegrasDistribuicao($request->validated('regras'), $request->user());

        return response()->json([
            'data' => $config,
            'meta' => ['message' => 'Regras da distribuição atualizadas.'],
        ]);
    }

    /** Liga/desliga a designação automática ao cadastrar um avaliador. */
    public function definirDistribuicaoAoCadastrar(Request $request): JsonResponse
    {
        $ativo = $request->validate(['ao_cadastrar' => ['required', 'boolean']])['ao_cadastrar'];
        $config = $this->service->definirDistribuicaoAoCadastrar($ativo, $request->user());

        return response()->json([
            'data' => $config,
            'meta' => ['message' => $ativo
                ? 'Avaliador novo passa a receber projetos no cadastro.'
                : 'Avaliador novo não recebe mais projetos no cadastro.'],
        ]);
    }

    /** Devolve ao bolo as designações não iniciadas e sorteia outras no lugar. */
    public function redistribuir(): JsonResponse
    {
        $relatorio = $this->distribuicao->redistribuir();

        return response()->json([
            'data' => $relatorio,
            'meta' => ['message' => $relatorio['devolvidas'] === 0
                ? 'Não havia designação para trocar.'
                : "{$relatorio['devolvidas']} designação(ões) trocadas por {$relatorio['recebidas']} nova(s)."],
        ]);
    }

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
            'categoria' => ['nullable', Rule::enum(Categoria::class)],
        ]);

        return response()->json([
            'data' => $this->service->rankingProjetos($filtros),
            'meta' => ['categorias' => Categoria::opcoes()],
        ]);
    }

    /** O que a lista final tem para oferecer: categorias, áreas e quantos projetos há em cada. */
    public function opcoesListaFinal(): JsonResponse
    {
        return response()->json(['data' => $this->listaFinal->opcoes()]);
    }

    /** Gera a lista final em TXT, no recorte de cotas escolhido pelo admin. */
    public function gerarListaFinal(ListaFinalRequest $request): Response
    {
        $txt = $this->listaFinal->exportarTxt($request->cotas());

        return response($txt, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lista-final-'.now()->format('Y-m-d-His').'.txt"',
        ]);
    }

    /** Ranking dos avaliadores que mais concluíram avaliações. */
    public function rankingAvaliadores(): JsonResponse
    {
        return response()->json(['data' => $this->service->rankingAvaliadores()]);
    }

    /** Projetos submetidos por área, com realizadas/em avaliação/faltantes. */
    public function projetos(ListarProjetosAvaliacaoRequest $request): JsonResponse
    {
        $filtros = $request->filtros();
        $pagina = $this->service->projetos($filtros, (int) ($request->validated('por_pagina') ?? 50));
        $limites = Edicao::limites();

        return response()->json([
            'data' => array_map(fn ($p) => $this->service->linhaProjeto($p, $limites), $pagina->items()),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'por_pagina' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'areas' => $this->service->areasComProjeto(),
                'categorias' => Categoria::opcoes(),
                // Cards do topo da tela: mesmo recorte de filtros da tabela.
                'resumo_areas' => $this->service->resumoProjetosPorArea($filtros),
                'min_por_projeto' => $limites->minPorProjeto(),
                // Com mínimos diferentes por categoria, o resumo não crava um número.
                'min_por_projeto_uniforme' => $limites->minUniforme(),
                'ordenar' => $filtros['ordenar'],
                'direcao' => $filtros['direcao'],
            ],
        ]);
    }

    /** CSV da tabela de projetos, no mesmo recorte de filtros da tela. */
    public function exportarProjetos(ListarProjetosAvaliacaoRequest $request): Response
    {
        $csv = $this->service->exportarProjetosCsv($request->filtros());

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="projetos-submetidos-'.now()->format('Y-m-d-His').'.csv"',
        ]);
    }

    /** Designa um projeto submetido a um avaliador ou a todos de uma área/subárea. */
    public function designar(DesignarAvaliacaoRequest $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $projeto->status === ProjetoStatus::Submetido,
            422,
            'Só é possível designar avaliações de projetos submetidos.'
        );

        $alvoId = $request->validated('alvo_id');

        $novas = $this->service->designar(
            $projeto,
            $request->validated('tipo'),
            $alvoId === null ? null : (int) $alvoId,
            $request->avaliadorIds(),
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

    /** Marca/desmarca o avaliador como membro da comissão especial. */
    public function comissao(Request $request, User $avaliador): JsonResponse
    {
        abort_unless($avaliador->isAvaliador(), 404, 'Avaliador não encontrado.');
        $comissao = $request->validate(['comissao_especial' => ['required', 'boolean']])['comissao_especial'];

        $this->service->definirComissao($avaliador, $comissao);

        return response()->json([
            'data' => ['comissao_especial' => $comissao],
            'meta' => ['message' => $comissao
                ? 'Avaliador incluído na comissão especial.'
                : 'Avaliador removido da comissão especial.'],
        ]);
    }

    /**
     * Libera uma área (e, opcionalmente, subárea) a mais para o avaliador — só o
     * admin pode ampliar o alcance de um avaliador.
     */
    public function adicionarAreaExtra(Request $request, User $avaliador): JsonResponse
    {
        abort_unless($avaliador->isAvaliador(), 404, 'Avaliador não encontrado.');

        $dados = $request->validate([
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'subarea_id' => ['nullable', 'integer', 'exists:subareas,id'],
        ]);

        $this->service->adicionarAreaExtra($avaliador, (int) $dados['area_id'], $dados['subarea_id'] ?? null);

        return response()->json([
            'data' => $this->service->linhaAvaliador($this->recarregar($avaliador)),
            'meta' => ['message' => 'Área liberada para o avaliador.'],
        ]);
    }

    /** Remove uma área extra do avaliador. */
    public function removerAreaExtra(User $avaliador, int $extra): JsonResponse
    {
        abort_unless($avaliador->isAvaliador(), 404, 'Avaliador não encontrado.');

        $this->service->removerAreaExtra($avaliador, $extra);

        return response()->json([
            'data' => $this->service->linhaAvaliador($this->recarregar($avaliador)),
            'meta' => ['message' => 'Área removida.'],
        ]);
    }

    /** Recarrega o avaliador com o que a linha da tabela precisa mostrar. */
    private function recarregar(User $avaliador): User
    {
        return User::query()
            ->with([
                'avaliadorProfile.area:id,nome',
                'avaliadorProfile.subarea:id,nome',
                'avaliadorProfile.areasExtras.area:id,nome',
                'avaliadorProfile.areasExtras.subarea:id,nome',
            ])
            ->withCount([
                'avaliacoes as em_avaliacao_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
                'avaliacoes as avaliou_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->findOrFail($avaliador->id);
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
