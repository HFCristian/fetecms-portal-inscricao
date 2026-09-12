<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlmoxarifadoGuarda;
use App\Models\ListaFinal;
use App\Models\User;
use App\Services\DadosDemoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parametrização → **Dados de demonstração**.
 *
 * A tela de manutenção do ensaio: mostra tudo que o portal guarda de mentira
 * (contas, projetos, avaliações, listas, credenciamentos, guardas, ajustes e a
 * trilha que eles geraram) e deixa desmarcar ou apagar.
 *
 * Todo `DELETE` daqui confere a marca de demonstração antes de tocar na linha —
 * a trava mora no service, para valer também fora do HTTP.
 */
class DadosDemoController extends Controller
{
    public function __construct(private readonly DadosDemoService $demo) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->demo->panorama(),
            'meta' => ['papeis' => DadosDemoService::papeis()],
        ]);
    }

    /** Liga/desliga a marca de demonstração de uma conta. */
    public function definirDemo(Request $request, User $usuario): JsonResponse
    {
        $dados = $request->validate(['demo' => ['required', 'boolean']]);

        return response()->json([
            'data' => $this->demo->definirDemo($usuario, (bool) $dados['demo']),
            'meta' => [
                'message' => $dados['demo']
                    ? 'Conta marcada como demonstração.'
                    : 'Conta não é mais de demonstração — os projetos dela voltam a contar.',
            ],
        ]);
    }

    public function excluirConta(User $usuario): JsonResponse
    {
        $this->demo->excluirConta($usuario);

        return response()->json([
            'data' => $this->demo->panorama(),
            'meta' => ['message' => 'Conta de demonstração excluída com os dados dela.'],
        ]);
    }

    /**
     * O projeto vem por id, e não por model binding: o `EdicaoScope` esconderia
     * o projeto de ensaio de outra edição, que é justamente o que esta tela
     * precisa alcançar.
     */
    public function excluirProjeto(int $projeto): JsonResponse
    {
        $this->demo->excluirProjeto($projeto);

        return response()->json([
            'data' => $this->demo->panorama(),
            'meta' => ['message' => 'Projeto de demonstração excluído.'],
        ]);
    }

    public function excluirLista(ListaFinal $lista): JsonResponse
    {
        $this->demo->excluirLista($lista);

        return response()->json([
            'data' => $this->demo->panorama(),
            'meta' => ['message' => 'Lista final de demonstração excluída.'],
        ]);
    }

    public function excluirGuarda(AlmoxarifadoGuarda $guarda): JsonResponse
    {
        $this->demo->excluirGuarda($guarda);

        return response()->json([
            'data' => $this->demo->panorama(),
            'meta' => ['message' => 'Guarda de demonstração excluída.'],
        ]);
    }

    /** Apaga tudo que é de demonstração de uma vez. */
    public function limpar(): JsonResponse
    {
        $totais = $this->demo->limparTudo();

        return response()->json([
            'data' => $this->demo->panorama(),
            'meta' => [
                'totais' => $totais,
                'message' => sprintf(
                    'Demonstração apagada: %d conta(s), %d projeto(s), %d lista(s) e %d guarda(s).',
                    $totais['contas'],
                    $totais['projetos'],
                    $totais['listas'],
                    $totais['guardas'],
                ),
            ],
        ]);
    }
}
