<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContaTemporariaRequest;
use App\Models\ContaTemporaria;
use App\Services\ContaTemporariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contas temporárias: as contas de prazo curto que atendem os balcões do
 * evento — o do **credenciamento** e o do **almoxarifado**.
 *
 * Toda ação devolve a lista inteira: ela é pequena e a tela precisa dela
 * atualizada de qualquer forma (criar, renovar e desativar mudam a situação de
 * uma linha e podem vencer outras na mesma passada).
 *
 * As duas abas mantêm **listas independentes**: o `setor` vem do grupo de rotas
 * (`defaults('setor', …)`), e cada aba só enxerga, renova e encerra as contas
 * dela. Uma conta de balcão do credenciamento não abre o almoxarifado e
 * vice-versa — é a própria linha que decide, por cima de qualquer escopo.
 *
 * **Quem administra as contas não pode ser uma delas.** A rota está sob a aba
 * que a conta temporária tem — sem esta trava ela renovaria o próprio prazo e
 * criaria colegas, o que esvaziaria o "temporário".
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

    /** O setor desta aba, definido no grupo de rotas. */
    private function setor(Request $request): string
    {
        $setor = (string) $request->route('setor');

        return in_array($setor, ContaTemporaria::setores(), true)
            ? $setor
            : ContaTemporaria::SETOR_CREDENCIAMENTO;
    }

    /** Cada aba mexe só nas contas dela: a de outra nem existe daqui. */
    private function garantirMesmoSetor(Request $request, ContaTemporaria $conta): void
    {
        abort_unless($conta->setor === $this->setor($request), 404, 'Conta não encontrada.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->garantirGestor($request);

        return response()->json(['data' => $this->contas->listar($this->setor($request))]);
    }

    public function store(ContaTemporariaRequest $request): JsonResponse
    {
        $this->garantirGestor($request);

        $this->contas->criar($request->validated(), $request->user(), $this->setor($request));

        return response()->json([
            'data' => $this->contas->listar($this->setor($request)),
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
        $this->garantirMesmoSetor($request, $conta);

        $dados = $request->validate([
            'valido_de' => ['nullable', 'date'],
            'expira_em' => ['nullable', 'date'],
            'horas' => ['nullable', 'integer', 'min:1', 'max:'.ContaTemporariaService::HORAS_MAX],
        ]);

        $this->contas->renovar($conta, $dados);

        return response()->json([
            'data' => $this->contas->listar($this->setor($request)),
            'meta' => ['message' => 'Acesso renovado.'],
        ]);
    }

    /** Desativa antes do prazo (a pessoa saiu da equipe, por exemplo). */
    public function desativar(Request $request, ContaTemporaria $conta): JsonResponse
    {
        $this->garantirGestor($request);
        $this->garantirMesmoSetor($request, $conta);

        $this->contas->desativar($conta);

        return response()->json([
            'data' => $this->contas->listar($this->setor($request)),
            'meta' => ['message' => 'Acesso encerrado.'],
        ]);
    }
}
