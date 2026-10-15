<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Http\Controllers\Controller;
use App\Models\CerimonialEvento;
use App\Models\Edicao;
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
 *
 * Tudo é **por evento de cerimônia** (`evento_id`, na query ou no corpo): o
 * check-in, a busca e a ficha exigem um; a Visão Geral e os premiados aceitam
 * nenhum, que é a soma de todos ("Todos").
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

    /** O crachá lido (leitor ou câmera): a entrada daquela pessoa já fica registrada. */
    public function checkinCodigo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:60'],
        ]);

        return response()->json([
            'data' => $this->cerimonial->checkinPorCodigo(
                $dados['codigo'],
                $request->user(),
                $this->evento($request),
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
                $this->evento($request),
                $request->user(),
                $request->boolean('teste'),
            ),
        ]);
    }

    /** A ficha de um projeto: os integrantes e quem já entrou. */
    public function show(Request $request, Projeto $projeto): JsonResponse
    {
        return response()->json([
            'data' => $this->cerimonial->ficha($projeto, $this->evento($request), $request->user(), $request->boolean('teste')),
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
                $this->evento($request),
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
                $this->evento($request),
                $request->boolean('teste'),
            ),
        ]);
    }

    /** Os cards da Visão Geral — só os números. */
    public function visaoGeral(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->cerimonial->visaoGeral($request->user(), $request->boolean('teste'), $this->evento($request, false)),
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
            'data' => $this->cerimonial->detalhe($dados['card'], $request->user(), $request->boolean('teste'), $this->evento($request, false)),
        ]);
    }

    /** Um cartão por projeto premiado, com o ícone de cada integrante. */
    public function premiados(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->cerimonial->premiados($request->user(), $request->boolean('teste'), $this->evento($request, false)),
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

    /** Os eventos de cerimônia da edição — a porta também precisa, para escolher o seu. */
    public function eventos(): JsonResponse
    {
        return response()->json(['data' => $this->cerimonial->eventos()]);
    }

    public function criarEvento(Request $request): JsonResponse
    {
        $dados = $this->validarEvento($request);

        return response()->json([
            'data' => $this->cerimonial->salvarEvento(null, $dados['nome'], $dados['categorias']),
        ], 201);
    }

    public function atualizarEvento(Request $request, CerimonialEvento $evento): JsonResponse
    {
        $evento = $this->cerimonial->evento($evento->id);
        $dados = $this->validarEvento($request, $evento);

        return response()->json([
            'data' => $this->cerimonial->salvarEvento($evento, $dados['nome'], $dados['categorias']),
        ]);
    }

    public function excluirEvento(CerimonialEvento $evento): JsonResponse
    {
        $this->cerimonial->excluirEvento($this->cerimonial->evento($evento->id));

        return response()->json(['data' => $this->cerimonial->eventos()]);
    }

    /** @return array{nome: string, categorias: list<string>} */
    private function validarEvento(Request $request, ?CerimonialEvento $evento = null): array
    {
        return $request->validate([
            'nome' => [
                'required', 'string', 'min:3', 'max:120',
                // Dois eventos com o mesmo nome deixariam a porta sem saber qual escolher.
                Rule::unique('cerimonial_eventos', 'nome')
                    ->where('edicao_id', Edicao::atual()?->id)
                    ->ignore($evento?->id),
            ],
            'categorias' => ['required', 'array', 'min:1'],
            'categorias.*' => ['required', 'distinct', Rule::enum(Categoria::class)],
        ], [
            'nome.unique' => 'Já existe um evento de cerimônia com este nome nesta edição.',
            'categorias.required' => 'Escolha ao menos uma categoria para o público do evento.',
            'categorias.min' => 'Escolha ao menos uma categoria para o público do evento.',
        ], ['nome' => 'nome do evento', 'categorias' => 'público']);
    }

    /**
     * O evento da requisição (`evento_id`), sempre da edição em uso. Sem ele,
     * `null` — o "Todos" de quem aceita — ou 422 para quem precisa de um.
     */
    private function evento(Request $request, bool $obrigatorio = true): ?CerimonialEvento
    {
        $dados = $request->validate([
            'evento_id' => [$obrigatorio ? 'required' : 'nullable', 'integer'],
        ], [
            'evento_id.required' => 'Escolha o evento de cerimônia.',
        ]);

        return isset($dados['evento_id']) ? $this->cerimonial->evento((int) $dados['evento_id']) : null;
    }
}
