<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orientador\DecidirAjusteRequest;
use App\Models\Projeto;
use App\Services\AjustesOrientadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba "Ajustes e Pareceres" do orientador: o que a avaliação online disse sobre
 * cada projeto dele — as sugestões de classificação, para aceitar ou não, e o
 * parecer (etapas da rubrica em níveis e recomendações escritas), para ler.
 *
 * A **nota** não vai em nada disto, nem a de cada seção nem a que o projeto
 * recebeu: quem discute o número é a organização.
 *
 * Há **dois portões**, e só um deles volta a fechar: a aba abre quando a
 * organização libera o período (`ajustes_de`) e **não fecha mais** — o parecer
 * é a devolutiva do trabalho e fica disponível para sempre. O que o fim do
 * prazo (`ajustes_ate`) encerra é a **decisão**: depois dele a classificação do
 * projeto não muda, e as sugestões viram leitura com o que já foi decidido.
 *
 * O orientador demo tem "modo teste" (?teste=1), que ignora as datas — igual ao
 * do avaliador demo.
 */
class OrientadorAjusteController extends Controller
{
    public function __construct(private readonly AjustesOrientadorService $ajustes) {}

    /** Janela + os projetos submetidos, com a contagem de cada coisa. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $janela = $this->ajustes->janela($user, $request->boolean('teste'));

        return response()->json(['data' => [
            'janela' => $janela,
            // A lista segue a **leitura**, não a decisão: encerrado o prazo, o
            // parecer continua aqui — só os botões é que somem.
            'projetos' => $janela['leitura'] ? $this->ajustes->projetos($user) : [],
        ]]);
    }

    /**
     * Só o estado da janela, sem a lista.
     *
     * É o que a tela inicial do orientador consulta para avisar que a avaliação
     * online terminou: um aviso não justifica varrer os projetos dele e as
     * avaliações de cada um, que é o que o `index` faz.
     */
    public function janela(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->ajustes->janela($request->user(), $request->boolean('teste')),
        ]);
    }

    /** Um projeto com as sugestões, as etapas em níveis e as recomendações. */
    public function show(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);
        // Ler é o que basta aqui — decidir tem o portão próprio, no `decidir`.
        $this->ajustes->garantirLeitura($request->user(), $request->boolean('teste'));

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
