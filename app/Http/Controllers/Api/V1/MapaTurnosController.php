<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Services\TurnosApresentacaoService;
use App\Support\RegrasTurnos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Mapa do Evento → **Turnos de Apresentação**.
 *
 * A lista que divide os finalistas entre o turno A (matutino) e o B
 * (vespertino), gerada a partir da lista final vigente e das regras do admin.
 */
class MapaTurnosController extends Controller
{
    public function __construct(private readonly TurnosApresentacaoService $turnos) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->turnos->painel()]);
    }

    /** Busca de finalistas por título ou por participante, para as listas das regras. */
    public function opcoes(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->turnos->opcoesDeBusca((string) $request->query('q', '')),
        ]);
    }

    /** Guarda capacidades e regras sem gerar — o rascunho da configuração. */
    public function salvarConfig(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->turnos->salvarConfig($this->validarConfig($request)),
            'meta' => ['message' => 'Configuração dos turnos salva.'],
        ]);
    }

    /** Gera (ou regera) a divisão. Só a última lista vale. */
    public function gerar(Request $request): JsonResponse
    {
        $resultado = $this->turnos->gerar($this->validarConfig($request), $request->user());

        $realocados = count($resultado['realocados']);

        return response()->json([
            'data' => $resultado['lista'],
            'meta' => [
                'realocados' => $resultado['realocados'],
                'totais' => $resultado['totais'],
                'message' => $realocados === 0
                    ? 'Lista de turnos gerada.'
                    : sprintf(
                        'Lista de turnos gerada. %d projeto(s) foram para o outro turno por falta de estande.',
                        $realocados,
                    ),
            ],
        ]);
    }

    /** O admin movendo um projeto de turno à mão — sem justificativa, com registro. */
    public function mover(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'projeto_id' => ['required', 'integer'],
            'turno' => ['required', Rule::in(Turno::valores())],
        ]);

        return response()->json([
            'data' => $this->turnos->mover(
                (int) $dados['projeto_id'],
                Turno::from($dados['turno']),
                $request->user(),
            ),
            'meta' => ['message' => 'Projeto movido de turno.'],
        ]);
    }

    /** TXT, CSV ou PDF — o mesmo recorte, três formatos. */
    public function exportar(string $formato): Response
    {
        return match ($formato) {
            'txt' => response($this->turnos->exportarTxt(), 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="turnos-apresentacao.txt"',
            ]),
            'csv' => response($this->turnos->exportarCsv(), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="turnos-apresentacao.csv"',
            ]),
            default => response($this->turnos->exportarPdf(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="turnos-apresentacao.pdf"',
            ]),
        };
    }

    /**
     * As capacidades e as regras vindas da tela.
     *
     * A validação para na forma (tipos, turnos conhecidos, listas com nome): o
     * conteúdo é normalizado pelo {@see RegrasTurnos}, que é quem
     * sabe o formato guardado — assim o banco nunca recebe meia estrutura.
     *
     * @return array<string, mixed>
     */
    private function validarConfig(Request $request): array
    {
        return $request->validate([
            'capacidade' => ['required', 'array'],
            'capacidade.A' => ['required', 'integer', 'min:0', 'max:5000'],
            'capacidade.B' => ['required', 'integer', 'min:0', 'max:5000'],
            'regras' => ['present', 'array'],
            'regras.*.ativa' => ['boolean'],
            'regras.*.turno' => ['nullable', Rule::in(Turno::valores())],
            'regras.*.listas' => ['array'],
            'regras.*.listas.*.nome' => ['required', 'string', 'max:120'],
            'regras.*.listas.*.turno' => ['nullable', Rule::in(Turno::valores())],
            'regras.*.listas.*.turno_indisponivel' => ['nullable', Rule::in(Turno::valores())],
            'regras.*.listas.*.origem' => ['nullable', 'string', 'max:20'],
            'regras.*.listas.*.observacao' => ['nullable', 'string', 'max:500'],
            'regras.*.listas.*.projetos' => ['array'],
            'regras.*.listas.*.projetos.*' => ['integer'],
        ]);
    }
}
