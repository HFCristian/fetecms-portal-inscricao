<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Credencial;
use App\Models\Projeto;
use App\Services\CredenciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Comitê especial → **Premiação** (Sprint 190): quem administra o comitê diz
 * que projeto recebeu cada credencial ou prêmio da feira, e baixa a lista de
 * premiação.
 *
 * A base é **a mesma** de Avaliação presencial → Credenciais e Prêmios
 * (`credencial_projeto`): o que o comitê anexa aparece lá, no cerimonial e na
 * lista da cerimônia, e vice-versa — duas listas paralelas acabariam
 * discordando justamente no palco. O que muda é o alcance: o **cadastro** das
 * credenciais (criar, editar, desativar) continua na Avaliação presencial, e
 * aqui só aparecem as **ativas**, que são as que ainda podem ser dadas.
 */
class ComitePremiacaoController extends Controller
{
    public function __construct(private readonly CredenciaisService $credenciais) {}

    /** As credenciais ativas, com quem já as recebeu, e os finalistas candidatos. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->credenciais->listar(soAtivas: true),
            'meta' => ['candidatos' => $this->credenciais->candidatos()],
        ]);
    }

    public function atribuir(Request $request, Credencial $credencial): JsonResponse
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
            'data' => $this->credenciais->listar(soAtivas: true),
            'meta' => ['message' => 'Projeto premiado.'],
        ]);
    }

    public function retirar(Credencial $credencial, Projeto $projeto): JsonResponse
    {
        $this->credenciais->retirar($credencial, $projeto);

        return response()->json([
            'data' => $this->credenciais->listar(soAtivas: true),
            'meta' => ['message' => 'Premiação retirada do projeto.'],
        ]);
    }

    /** A lista de premiação em TXT — o mesmo arquivo da Avaliação presencial. */
    public function arquivo(): Response
    {
        return response($this->credenciais->exportarTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lista-premiacao.txt"',
        ]);
    }
}
