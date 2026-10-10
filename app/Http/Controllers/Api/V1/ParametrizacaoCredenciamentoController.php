<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Edicao;
use App\Services\CredenciamentoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Parametrização → Credenciamento: a janela do evento e os itens entregues aos
 * finalistas no balcão.
 *
 * A janela vive na edição (cada ano tem as suas datas). Os documentos que o
 * balcão confere saíram daqui na Sprint 181: são a lista única de itens do
 * credenciamento e da checagem (`ItensConferenciaController`).
 */
class ParametrizacaoCredenciamentoController extends Controller
{
    public function __construct(private readonly CredenciamentoService $credenciamento) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    /** Define início e fim do evento (hora de parede de Campo Grande). */
    public function definirJanela(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'evento_de' => ['nullable', 'date'],
            'evento_ate' => ['nullable', 'date'],
        ]);

        $de = $this->interpretar($dados['evento_de'] ?? null);
        $ate = $this->interpretar($dados['evento_ate'] ?? null);

        if ($de && $ate && $ate->lessThanOrEqualTo($de)) {
            throw ValidationException::withMessages([
                'evento_ate' => 'O fim do evento precisa ser depois do início.',
            ]);
        }

        Edicao::atual()?->update(['evento_de' => $de, 'evento_ate' => $ate]);

        return response()->json(['data' => $this->payload($request)]);
    }

    /**
     * Os itens entregues ao finalista no balcão (camiseta, crachá, kit…). É a
     * lista que a tela lembra o admin de entregar ao concluir o credenciamento.
     */
    public function definirItens(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'itens' => ['present', 'array', 'max:30'],
            // Vazio é aceito e descartado abaixo: a tela manda a lista inteira,
            // com as linhas que o admin ainda não preencheu.
            'itens.*' => ['nullable', 'string', 'max:80'],
        ]);

        // Sem repetição e sem espaço sobrando: a lista é lida em voz alta no balcão.
        $itens = array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $dados['itens'],
        ), fn (string $item) => $item !== '')));

        Edicao::atual()?->update(['itens_credenciamento' => $itens]);

        return response()->json(['data' => $this->payload($request)]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        // Os documentos conferidos no balcão moram na lista única de itens
        // (Parametrização → Itens do credenciamento e da checagem, Sprint 181).
        return [
            'config' => $this->credenciamento->config($request->user()),
        ];
    }

    /** Hora de parede local (23:59 é 23:59 em Campo Grande, sem shift de UTC). */
    private function interpretar(?string $data): ?Carbon
    {
        return ($data !== null && $data !== '')
            ? Carbon::parse($data, config('app.timezone'))
            : null;
    }
}
