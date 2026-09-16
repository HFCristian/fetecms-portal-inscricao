<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Projeto;
use App\Services\PareceresOrientadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba "Pareceres" do orientador: a nota média de cada projeto dele, o que os
 * avaliadores escreveram e em que a rubrica foi bem ou mal — em níveis, nunca
 * em pontos.
 *
 * A janela é a mesma da aba Ajustes, e pela mesma razão: as duas mostram o
 * resultado da avaliação online, que só se abre para o orientador quando a
 * organização decide. O orientador demo tem "modo teste" (?teste=1).
 */
class OrientadorParecerController extends Controller
{
    public function __construct(private readonly PareceresOrientadorService $pareceres) {}

    /** Janela + os projetos submetidos, com a média e quantos pareceres têm. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $janela = $this->pareceres->janela($user, $request->boolean('teste'));

        return response()->json(['data' => [
            'janela' => $janela,
            // Fora da janela a lista vem vazia: a aba existe no menu e explica
            // o motivo, mas não mostra parecer nenhum.
            'projetos' => $janela['aberta'] ? $this->pareceres->projetos($user) : [],
        ]]);
    }

    /** O parecer de um projeto: média, seções em níveis e recomendações. */
    public function show(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);

        // Mesma trava da aba Ajustes: fora da janela, nem por URL direta.
        if (! $this->pareceres->janela($request->user(), $request->boolean('teste'))['aberta']) {
            abort(403, 'O período de consulta aos pareceres não está aberto.');
        }

        return response()->json(['data' => $this->pareceres->detalhe($projeto)]);
    }
}
