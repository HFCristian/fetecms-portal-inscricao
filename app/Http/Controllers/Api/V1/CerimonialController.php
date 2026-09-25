<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Projeto;
use App\Services\CerimonialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aba **Cerimonial**: o check-in da cerimônia de premiação e o painel de quem
 * já chegou.
 *
 * As rotas de painel (Visão Geral, premiados, intervalo de atualização) ficam
 * atrás do middleware `admin.permanente`: a conta temporária do setor atende a
 * porta e não vê a mesa de medalhas. As de check-in, não — são exatamente o
 * trabalho dela.
 */
class CerimonialController extends Controller
{
    public function __construct(private readonly CerimonialService $cerimonial) {}

    public function config(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->cerimonial->config($request->user(), $request->boolean('teste')),
        ]);
    }

    /** A leitura do crachá: o atalho para a ficha certa. */
    public function lerCodigo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:60'],
        ]);

        return response()->json([
            'data' => $this->cerimonial->resolverCodigo(
                $dados['codigo'],
                $request->user(),
                $request->boolean('teste'),
            ),
        ]);
    }

    /** Busca por nome ou CPF entre os participantes finalistas. */
    public function buscar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'busca' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->cerimonial->buscar(
                $dados['busca'],
                $request->user(),
                $request->boolean('teste'),
            ),
        ]);
    }

    /** A ficha de um projeto: os integrantes e quem já entrou. */
    public function show(Request $request, Projeto $projeto): JsonResponse
    {
        return response()->json([
            'data' => $this->cerimonial->ficha($projeto, $request->user(), $request->boolean('teste')),
            'meta' => ['config' => $this->cerimonial->config($request->user(), $request->boolean('teste'))],
        ]);
    }

    /** Check-in de uma ou mais pessoas do projeto. */
    public function checkin(Request $request, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate([
            'participantes' => ['required', 'array', 'min:1'],
            // "A45", "O12", "C3" — papel + id, a mesma chave do crachá.
            'participantes.*' => ['required', 'string', 'regex:/^[AOC]\d+$/'],
        ]);

        return response()->json([
            'data' => $this->cerimonial->registrar(
                $projeto,
                array_values(array_unique($dados['participantes'])),
                $request->user(),
                $request->boolean('teste'),
            ),
        ]);
    }

    /** Desfaz o check-in de uma pessoa, com justificativa. */
    public function desfazer(Request $request, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate([
            'participante' => ['required', 'string', 'regex:/^[AOC]\d+$/'],
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return response()->json([
            'data' => $this->cerimonial->desfazer(
                $projeto,
                $dados['participante'],
                $dados['justificativa'],
                $request->user(),
                $request->boolean('teste'),
            ),
        ]);
    }

    /** Os cards da Visão Geral — só os números. */
    public function visaoGeral(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->cerimonial->visaoGeral($request->user(), $request->boolean('teste')),
            'meta' => ['config' => $this->cerimonial->config($request->user(), $request->boolean('teste'))],
        ]);
    }

    /** A lista nominal de um card: quem chegou e quem falta. */
    public function detalhe(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'card' => ['required', Rule::in(['pessoas', 'projetos', 'premiados', 'medalhas', 'credenciais'])],
        ]);

        return response()->json([
            'data' => $this->cerimonial->detalhe($dados['card'], $request->user(), $request->boolean('teste')),
        ]);
    }

    /** Um cartão por projeto premiado, com o ícone de cada integrante. */
    public function premiados(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->cerimonial->premiados($request->user(), $request->boolean('teste')),
        ]);
    }

    /** De quantos em quantos segundos o painel se atualiza (nulo desliga). */
    public function definirAtualizacao(Request $request): JsonResponse
    {
        $dados = $request->validate([
            // O piso de 5s é o que separa "atualiza sozinho" de "bate no
            // servidor sem parar" com o painel aberto a tarde inteira.
            'segundos' => ['nullable', 'integer', 'min:5', 'max:600'],
        ]);

        return response()->json([
            'data' => ['atualizacao_segundos' => $this->cerimonial->definirAtualizacao($dados['segundos'] ?? null)],
        ]);
    }
}
