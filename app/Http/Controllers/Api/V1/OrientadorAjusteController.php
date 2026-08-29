<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orientador\DecidirAjusteRequest;
use App\Models\Projeto;
use App\Services\AjustesOrientadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba "Ajustes" do orientador: as sugestões que os avaliadores deixaram nos
 * projetos dele, para aceitar ou não durante o período de ajustes.
 *
 * Fora da janela a aba continua listando (a tela mostra o motivo), mas nada
 * pode ser decidido. O orientador demo tem "modo teste" (?teste=1), que ignora
 * as datas — igual ao do avaliador demo.
 */
class OrientadorAjusteController extends Controller
{
    public function __construct(private readonly AjustesOrientadorService $ajustes) {}

    /** Janela + lista dos projetos submetidos com a contagem de sugestões. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $janela = $this->ajustes->janela($user, $request->boolean('teste'));

        return response()->json(['data' => [
            'janela' => $janela,
            // Fora da janela a lista vem vazia: a aba existe no menu, mas não abre.
            'projetos' => $janela['aberta'] ? $this->ajustes->projetos($user) : [],
        ]]);
    }

    /** Um projeto com as sugestões e as recomendações dos avaliadores. */
    public function show(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);
        $this->ajustes->garantirJanelaAberta($request->user(), $request->boolean('teste'));

        return response()->json(['data' => $this->ajustes->detalhe($projeto)]);
    }

    /** Aceita ou desfaz uma sugestão de reclassificação. */
    public function decidir(DecidirAjusteRequest $request, Projeto $projeto): JsonResponse
    {
        // 'view' e não 'update': o projeto está submetido (logo, não editável
        // pelas regras normais). Quem autoriza a troca aqui é a janela de
        // ajustes, conferida logo abaixo.
        $this->authorize('view', $projeto);
        $this->ajustes->garantirJanelaAberta($request->user(), $request->boolean('teste'));

        $dados = $request->validated();
        $detalhe = $this->ajustes->decidir(
            $projeto,
            (int) $dados['avaliacao_id'],
            $dados['tipo'],
            (bool) $dados['aceito'],
            $request->user(),
        );

        return response()->json([
            'data' => $detalhe,
            'meta' => ['message' => $dados['aceito']
                ? 'Sugestão aceita — a classificação do projeto foi atualizada.'
                : 'Sugestão recusada — a classificação anterior foi mantida.'],
        ]);
    }
}
