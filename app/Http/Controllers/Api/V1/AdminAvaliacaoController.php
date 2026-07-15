<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjetoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DesignarAvaliacaoRequest;
use App\Http\Requests\Admin\LiberacaoAvaliacaoRequest;
use App\Http\Requests\Admin\LimiteAvaliadorRequest;
use App\Models\Projeto;
use App\Models\User;
use App\Services\AdminAvaliacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telas de "Avaliação online" (somente admin): visão dos avaliadores por área
 * e dos projetos submetidos por área. Ver AdminAvaliacaoService (E7).
 */
class AdminAvaliacaoController extends Controller
{
    public function __construct(private readonly AdminAvaliacaoService $service) {}

    /** Avaliadores agrupados por área, com o progresso de avaliação de cada um. */
    public function avaliadores(): JsonResponse
    {
        return response()->json(['data' => $this->service->avaliadoresPorArea()]);
    }

    /** Configuração da liberação da avaliação (data + se já liberada). */
    public function config(): JsonResponse
    {
        return response()->json(['data' => $this->service->config()]);
    }

    /** Define/remove a data de liberação da avaliação (edição atual). */
    public function definirLiberacao(LiberacaoAvaliacaoRequest $request): JsonResponse
    {
        $config = $this->service->definirLiberacao($request->validated('liberada_em'));

        return response()->json(['data' => $config, 'meta' => ['message' => 'Liberação atualizada.']]);
    }

    /** Projetos submetidos por área, com realizadas/em avaliação/faltantes. */
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
}
