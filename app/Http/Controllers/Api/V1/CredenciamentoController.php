<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Enums\SituacaoDocumento;
use App\Enums\TipoPessoaCredenciamento;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Projeto;
use App\Services\CredenciamentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Aba Credenciamento: o balcão do evento.
 *
 * "Credenciar" lista os finalistas (os projetos da lista final vigente) e
 * "Credenciados" mostra quem já passou — os dois saem da mesma consulta, só
 * muda o filtro de situação.
 *
 * `teste=1` liga o **modo de teste** do admin demo: além de ignorar a janela do
 * evento, ele troca a lista oficial pela lista **demo** da edição, para o
 * ensaio nunca tocar num finalista de verdade.
 */
class CredenciamentoController extends Controller
{
    public function __construct(private readonly CredenciamentoService $credenciamento) {}

    /** Janela do evento, itens a entregar e modo de teste do admin demo. */
    public function config(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->credenciamento->config($request->user(), $request->boolean('teste')),
        ]);
    }

    /**
     * Resolve o código lido no balcão (QR pela câmera ou barras pelo leitor USB)
     * e diz de quem ele é — a tela abre a ficha daquele projeto.
     */
    public function lerCodigo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:60'],
        ]);

        return response()->json([
            'data' => $this->credenciamento->resolverCodigo(
                $dados['codigo'],
                $request->user(),
                $request->boolean('teste'),
            ),
        ]);
    }

    /** Finalistas com a situação de cada um (a mesma lista das duas seções). */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'categoria' => ['nullable', Rule::in(array_column(Categoria::cases(), 'value'))],
            'situacao' => ['nullable', Rule::in(['credenciados', 'pendentes'])],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $pagina = $this->credenciamento->finalistas(
            $filtros,
            (int) ($filtros['por_pagina'] ?? 25),
            $request->user(),
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'resumo' => $this->credenciamento->resumo($filtros, $request->user(), $request->boolean('teste')),
                'areas' => Area::query()->orderBy('nome')->get(['id', 'nome']),
                'categorias' => Categoria::opcoes(),
                'config' => $this->credenciamento->config($request->user(), $request->boolean('teste')),
            ],
        ]);
    }

    /** A ficha de um finalista: cada pessoa com os documentos do papel dela. */
    public function show(Request $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $this->credenciamento->ehFinalista($projeto, $request->user(), $request->boolean('teste')),
            404,
            'Este projeto não está na lista final vigente.',
        );

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto, $request->user()) + [
                'config' => $this->credenciamento->config($request->user(), $request->boolean('teste')),
            ],
        ]);
    }

    /** Conclui o credenciamento do projeto com a conferência dos documentos. */
    public function store(Request $request, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate($this->regrasDaConferencia());

        $this->credenciamento->registrar(
            $projeto,
            $request->user(),
            $dados['marcacoes'],
            $dados['observacao'] ?? null,
            $request->boolean('teste'),
            $dados['iniciado_em'] ?? null,
            $dados['pessoas'] ?? [],
            $dados['kits'] ?? null,
        );

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto->fresh(), $request->user()),
            'meta' => ['message' => 'Credenciamento concluído.'],
        ]);
    }

    /**
     * As regras da conferência, iguais para o rascunho e para a conclusão: o
     * que muda entre os dois é o que o serviço faz com elas, não o formulário.
     *
     * @return array<string, mixed>
     */
    private function regrasDaConferencia(): array
    {
        return [
            'marcacoes' => ['present', 'array'],
            'marcacoes.*.documento_id' => ['required', 'integer', 'exists:documentos_credenciamento,id'],
            'marcacoes.*.pessoa_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'marcacoes.*.pessoa_id' => ['nullable', 'integer'],
            'marcacoes.*.situacao' => ['required', Rule::in(SituacaoDocumento::valores())],
            'observacao' => ['nullable', 'string', 'max:1000'],
            // Presença de cada pessoa: ausente dispensa os documentos dela e
            // deixa o projeto credenciado com pendência.
            'pessoas' => ['sometimes', 'array'],
            'pessoas.*.pessoa_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'pessoas.*.pessoa_id' => ['nullable', 'integer'],
            'pessoas.*.presente' => ['required', 'boolean'],
            // Kits que saem neste atendimento, todos no nome de um responsável.
            'kits' => ['sometimes', 'array'],
            'kits.responsavel_tipo' => ['required_with:kits.pessoas', Rule::in(TipoPessoaCredenciamento::valores())],
            'kits.responsavel_id' => ['nullable', 'integer'],
            'kits.pessoas' => ['sometimes', 'array'],
            'kits.pessoas.*.pessoa_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'kits.pessoas.*.pessoa_id' => ['nullable', 'integer'],
            // Só chega quando o admin altera o horário sugerido: aí o fim vira
            // início + 5 minutos, em vez do instante da conclusão.
            'iniciado_em' => ['nullable', 'date'],
        ];
    }

    /**
     * Salva o atendimento sem fechá-lo — o participante saiu para buscar um
     * documento e volta depois. O projeto continua na fila de *Credenciar*.
     */
    public function rascunho(Request $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $this->credenciamento->ehFinalista($projeto, $request->user(), $request->boolean('teste')),
            404,
            'Este projeto não está na lista final vigente.',
        );

        $dados = $request->validate($this->regrasDaConferencia());

        $this->credenciamento->salvarRascunho(
            $projeto,
            $request->user(),
            $dados['marcacoes'],
            $dados['pessoas'] ?? [],
            $dados['kits'] ?? null,
            $dados['observacao'] ?? null,
            $request->boolean('teste'),
            $dados['iniciado_em'] ?? null,
        );

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto->fresh(), $request->user()),
            'meta' => ['message' => 'Rascunho salvo. O projeto continua na fila de Credenciar.'],
        ]);
    }

    /**
     * Assume o rascunho de outra pessoa. A justificativa é exigida no serviço
     * quando o dono anterior é um admin permanente.
     */
    public function assumir(Request $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $this->credenciamento->ehFinalista($projeto, $request->user(), $request->boolean('teste')),
            404,
            'Este projeto não está na lista final vigente.',
        );

        $dados = $request->validate([
            'justificativa' => ['nullable', 'string', 'max:500'],
        ]);

        $this->credenciamento->assumir(
            $projeto,
            $request->user(),
            $dados['justificativa'] ?? null,
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto->fresh(), $request->user()),
            'meta' => ['message' => 'Atendimento assumido. Você pode continuar a conferência.'],
        ]);
    }

    /**
     * Registra a retirada de kits depois do credenciamento: o colega que faltou
     * no primeiro atendimento aparece mais tarde e leva o dele.
     *
     * Só acrescenta — por isso não exige a conta que credenciou, ao contrário
     * de corrigir e de cancelar.
     */
    public function kits(Request $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $this->credenciamento->ehFinalista($projeto, $request->user(), $request->boolean('teste')),
            404,
            'Este projeto não está na lista final vigente.',
        );

        $dados = $request->validate([
            'responsavel_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'responsavel_id' => ['nullable', 'integer'],
            'pessoas' => ['required', 'array', 'min:1'],
            'pessoas.*.pessoa_tipo' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'pessoas.*.pessoa_id' => ['nullable', 'integer'],
        ]);

        $this->credenciamento->retirarKits($projeto, $request->user(), $dados, $request->boolean('teste'));

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto->fresh(), $request->user()),
            'meta' => ['message' => 'Retirada de kit registrada.'],
        ]);
    }

    /**
     * Cancela o credenciamento: a conferência é apagada e o projeto volta para
     * a fila de *Credenciar*. Mexer no credenciamento de outra conta exige
     * admin permanente — a regra vive no serviço.
     */
    public function cancelar(Request $request, Projeto $projeto): JsonResponse
    {
        abort_unless(
            $this->credenciamento->ehFinalista($projeto, $request->user(), $request->boolean('teste')),
            404,
            'Este projeto não está na lista final vigente.',
        );

        $dados = $request->validate([
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->credenciamento->cancelar(
            $projeto,
            $request->user(),
            $dados['justificativa'],
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $this->credenciamento->ficha($projeto->fresh(), $request->user()),
            'meta' => ['message' => 'Credenciamento cancelado. O projeto voltou para a fila do balcão.'],
        ]);
    }
}
