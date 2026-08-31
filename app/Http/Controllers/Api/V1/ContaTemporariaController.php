<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContaTemporariaRequest;
use App\Models\ContaTemporaria;
use App\Services\ContaTemporariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Credenciamento → Contas temporárias: as contas de prazo curto que atendem o
 * balcão do evento.
 *
 * Toda ação devolve a lista inteira: ela é pequena e a tela precisa dela
 * atualizada de qualquer forma (criar, renovar e desativar mudam a situação de
 * uma linha e podem vencer outras na mesma passada).
 *
 * **Quem administra as contas não pode ser uma delas.** A rota está sob
 * `aba:credenciamento`, que a conta temporária tem — sem esta trava ela
 * renovaria o próprio prazo e criaria colegas, o que esvaziaria o "temporário".
 */
class ContaTemporariaController extends Controller
{
    public function __construct(private readonly ContaTemporariaService $contas) {}

    /** Conta temporária atende o balcão; quem gere as contas é a organização. */
    private function garantirGestor(Request $request): void
    {
        abort_if(
            (bool) $request->user()?->ehContaTemporaria(),
            403,
            'Contas temporárias não administram outras contas temporárias.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->garantirGestor($request);

        return response()->json(['data' => $this->contas->listar()]);
    }

    public function store(ContaTemporariaRequest $request): JsonResponse
    {
        $this->garantirGestor($request);

        $this->contas->criar($request->validated(), $request->user());

        return response()->json([
            'data' => $this->contas->listar(),
            'meta' => ['message' => 'Conta temporária criada.'],
        ], 201);
    }

    /**
     * Janela nova e conta reativada — o caminho de quem venceu, e também o de
     * quem só quer **reagendar** um acesso que ainda não começou.
     */
    public function renovar(Request $request, ContaTemporaria $conta): JsonResponse
    {
        $this->garantirGestor($request);

        $dados = $request->validate([
            'valido_de' => ['nullable', 'date'],
            'expira_em' => ['nullable', 'date'],
            'horas' => ['nullable', 'integer', 'min:1', 'max:'.ContaTemporariaService::HORAS_MAX],
        ]);

        $this->contas->renovar($conta, $dados);

        return response()->json([
            'data' => $this->contas->listar(),
            'meta' => ['message' => 'Acesso renovado.'],
        ]);
    }

    /** Desativa antes do prazo (a pessoa saiu da equipe, por exemplo). */
    public function desativar(Request $request, ContaTemporaria $conta): JsonResponse
    {
        $this->garantirGestor($request);

        $this->contas->desativar($conta);

        return response()->json([
            'data' => $this->contas->listar(),
            'meta' => ['message' => 'Acesso encerrado.'],
        ]);
    }
}
