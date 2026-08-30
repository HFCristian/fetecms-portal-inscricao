<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoPessoaCredenciamento;
use App\Http\Controllers\Controller;
use App\Models\DocumentoCredenciamento;
use App\Models\Edicao;
use App\Services\CredenciamentoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Parametrização → Credenciamento: a janela do evento e a lista de documentos
 * exigida de cada papel no balcão.
 *
 * A janela vive na edição (cada ano tem as suas datas); os documentos são
 * catálogo do portal, como áreas e escolas.
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

    public function criarDocumento(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tipo_pessoa' => ['required', Rule::in(TipoPessoaCredenciamento::valores())],
            'nome' => ['required', 'string', 'max:120', Rule::unique('documentos_credenciamento', 'nome')
                ->where(fn ($q) => $q->where('tipo_pessoa', $request->input('tipo_pessoa')))],
        ], ['nome.unique' => 'Este documento já está na lista deste papel.']);

        DocumentoCredenciamento::create($dados + [
            'ordem' => (int) DocumentoCredenciamento::where('tipo_pessoa', $dados['tipo_pessoa'])->max('ordem') + 1,
            'ativo' => true,
        ]);

        return response()->json(['data' => $this->payload($request)], 201);
    }

    public function atualizarDocumento(Request $request, DocumentoCredenciamento $documento): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['sometimes', 'string', 'max:120', Rule::unique('documentos_credenciamento', 'nome')
                ->where(fn ($q) => $q->where('tipo_pessoa', $documento->tipo_pessoa->value))
                ->ignore($documento->id)],
            'ativo' => ['sometimes', 'boolean'],
            'ordem' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ], ['nome.unique' => 'Este documento já está na lista deste papel.']);

        $documento->update($dados);

        return response()->json(['data' => $this->payload($request)]);
    }

    /**
     * Exclui um documento da lista. O que já foi conferido em algum
     * credenciamento não é apagado — seria reescrever a auditoria do evento;
     * nesse caso, desative-o.
     */
    public function excluirDocumento(Request $request, DocumentoCredenciamento $documento): JsonResponse
    {
        if ($documento->credenciamentos()->exists()) {
            throw ValidationException::withMessages([
                'documento' => 'Este documento já foi conferido em algum credenciamento. Desative-o em vez de excluir.',
            ]);
        }

        $documento->delete();

        return response()->json(['data' => $this->payload($request)]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        return [
            'config' => $this->credenciamento->config($request->user()),
            'tipos' => TipoPessoaCredenciamento::opcoes(),
            'documentos' => DocumentoCredenciamento::query()
                ->orderBy('tipo_pessoa')
                ->orderBy('ordem')
                ->orderBy('id')
                ->get(['id', 'tipo_pessoa', 'nome', 'ordem', 'ativo']),
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
