<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContaTemporariaRequest;
use App\Models\ContaTemporaria;
use App\Services\ContaTemporariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Contas temporárias: as contas de prazo curto que atendem os balcões do
 * evento — o do **credenciamento**, o do **almoxarifado** e os **voluntários**
 * da avaliação presencial, que trabalham em turnos.
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
            // Renovar sem citar turnos prorroga a mesma escala; citando, ela é
            // substituída pela nova.
            'turnos' => ['nullable', 'array', 'max:20'],
            'turnos.*.inicio' => ['required', 'date'],
            'turnos.*.fim' => ['required', 'date'],
        ]);

        $this->contas->renovar($conta, $dados);

        return response()->json([
            'data' => $this->contas->listar($this->setor($request)),
            'meta' => ['message' => 'Acesso renovado.'],
        ]);
    }

    /**
     * Aprova ou rejeita a presença de quem se anunciou no turno.
     *
     * Rejeitar exige motivo escrito e tira a conta do ar — quem decide é o
     * admin do setor, que é quem está no balcão com a pessoa.
     */
    public function presenca(Request $request, ContaTemporaria $conta): JsonResponse
    {
        $this->garantirGestor($request);
        $this->garantirMesmoSetor($request, $conta);

        $dados = $request->validate([
            'aprovar' => ['required', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $this->contas->decidirPresenca(
            $conta,
            (bool) $dados['aprovar'],
            $dados['motivo'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $this->contas->listar($this->setor($request)),
            'meta' => ['message' => $dados['aprovar']
                ? 'Presença aprovada — a conta já abre a aba.'
                : 'Presença rejeitada e acesso encerrado.'],
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

    /** O modelo em Excel para o cadastro em lote deste setor. */
    public function modelo(Request $request): Response
    {
        $this->garantirGestor($request);

        $setor = $this->setor($request);

        return response($this->contas->modeloLote($setor), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="modelo-contas-'.str_replace('_', '-', $setor).'.xlsx"',
        ]);
    }

    /**
     * Lê a planilha e devolve linha a linha o que vai ser criado e o que não
     * passa, com o motivo. Não grava nada.
     */
    public function previaLote(Request $request): JsonResponse
    {
        $this->garantirGestor($request);

        $dados = $request->validate([
            'arquivo' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt', 'mimes:xlsx,csv,txt,zip'],
            ...$this->regrasPadroes(),
        ], [], ['arquivo' => 'planilha']);

        return response()->json([
            'data' => $this->contas->previaLote($request->file('arquivo'), $this->setor($request), $this->padroes($dados)),
        ]);
    }

    /**
     * Cria as contas da prévia confirmada. As senhas geradas voltam **uma
     * vez**, dentro da planilha de acesso (base64), e não ficam em lugar nenhum.
     */
    public function lote(Request $request): JsonResponse
    {
        $this->garantirGestor($request);

        $dados = $request->validate([
            'linhas' => ['required', 'array', 'min:1', 'max:'.ContaTemporariaService::MAX_LOTE],
            'linhas.*.linha' => ['nullable', 'integer'],
            'linhas.*.name' => ['nullable', 'string', 'max:255'],
            'linhas.*.email' => ['nullable', 'string', 'max:255'],
            'linhas.*.cpf' => ['nullable', 'string', 'max:20'],
            'linhas.*.curso' => ['nullable', 'string', 'max:255'],
            'linhas.*.valido_de' => ['nullable', 'string', 'max:30'],
            'linhas.*.horas' => ['nullable', 'integer', 'min:1', 'max:'.ContaTemporariaService::HORAS_MAX],
            'linhas.*.turnos' => ['nullable', 'array', 'max:20'],
            'linhas.*.turnos.*.inicio' => ['required', 'string', 'max:30'],
            'linhas.*.turnos.*.fim' => ['required', 'string', 'max:30'],
            ...$this->regrasPadroes(),
        ]);

        $setor = $this->setor($request);
        $resultado = $this->contas->criarLote($dados['linhas'], $this->padroes($dados), $request->user(), $setor);
        $criadas = count($resultado['criadas']);

        return response()->json([
            'data' => $this->contas->listar($setor),
            'meta' => [
                'message' => $criadas === 0
                    ? 'Nenhuma conta criada — confira os motivos abaixo.'
                    : ($criadas === 1 ? '1 conta criada.' : "{$criadas} contas criadas.")
                        .' Baixe a planilha de acesso agora: as senhas não ficam guardadas.',
                'criadas' => $resultado['criadas'],
                'ignoradas' => $resultado['ignoradas'],
                'arquivo' => $criadas === 0 ? null : [
                    'nome' => 'acessos-contas-'.str_replace('_', '-', $setor).'-'.now()->format('Ymd-His').'.xlsx',
                    'base64' => base64_encode($this->contas->planilhaAcessos($resultado['criadas'])),
                ],
            ],
        ], $criadas === 0 ? 200 : 201);
    }

    /**
     * Remove a conta: apagada se nunca atendeu ninguém, arquivada se já atendeu
     * — o nome continua nas fichas que ela assinou.
     */
    public function destroy(Request $request, ContaTemporaria $conta): JsonResponse
    {
        $this->garantirGestor($request);
        $this->garantirMesmoSetor($request, $conta);
        abort_if($conta->removida_em !== null, 404, 'Conta não encontrada.');

        $como = $this->contas->remover($conta);

        return response()->json([
            'data' => $this->contas->listar($this->setor($request)),
            'meta' => ['message' => $como === 'arquivada'
                ? 'Conta removida. Ela já tinha atendimentos registrados, então o nome continua nos registros — mas o acesso acabou.'
                : 'Conta removida.'],
        ]);
    }

    /** @return array<string, list<string>> */
    private function regrasPadroes(): array
    {
        return [
            'padroes' => ['nullable', 'array'],
            'padroes.valido_de' => ['nullable', 'date'],
            'padroes.horas' => ['nullable', 'integer', 'min:1', 'max:'.ContaTemporariaService::HORAS_MAX],
            'padroes.turnos' => ['nullable', 'array', 'max:20'],
            'padroes.turnos.*.inicio' => ['required', 'date'],
            'padroes.turnos.*.fim' => ['required', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function padroes(array $dados): array
    {
        return (array) ($dados['padroes'] ?? []);
    }
}
