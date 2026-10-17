<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Services\EtiquetasAvulsasService;
use App\Support\EtiquetasPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Credenciamento → Etiquetas avulsas (Sprint 218): buscar de quem é a etiqueta
 * e gerar o PDF. Vale para todo mundo da aba, inclusive a conta temporária do
 * balcão — é ela que está na frente de quem perdeu o crachá —, e cada geração
 * fica registrada.
 *
 * `teste=1` (conta demo) troca os participantes pelos da lista de
 * demonstração, como no resto do balcão.
 */
class EtiquetasAvulsasController extends Controller
{
    public function __construct(private readonly EtiquetasAvulsasService $etiquetas) {}

    /** As pessoas de um grupo que batem com a busca. */
    public function buscar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'grupo' => ['required', Rule::in(EtiquetasAvulsasService::GRUPOS)],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->etiquetas->buscar(
                $dados['grupo'],
                (string) ($dados['q'] ?? ''),
                $request->user(),
                $request->boolean('teste'),
            ),
            'meta' => [
                'turnos' => Turno::opcoes(),
                'por_folha' => EtiquetasPdf::POR_FOLHA,
                'maximo' => EtiquetasAvulsasService::MAXIMO,
            ],
        ]);
    }

    /** O PDF das etiquetas, a partir da posição escolhida da folha. */
    public function pdf(Request $request): Response
    {
        $personalizada = 'itens.*.grupo';
        $ehPersonalizada = 'required_if:'.$personalizada.','.EtiquetasAvulsasService::PERSONALIZADA;

        $dados = $request->validate([
            'inicio' => ['nullable', 'integer', 'min:1', 'max:'.EtiquetasPdf::POR_FOLHA],
            'itens' => ['required', 'array', 'min:1', 'max:'.EtiquetasAvulsasService::MAXIMO],
            'itens.*.grupo' => ['required', Rule::in([...EtiquetasAvulsasService::GRUPOS, EtiquetasAvulsasService::PERSONALIZADA])],
            'itens.*.id' => ['required_unless:'.$personalizada.','.EtiquetasAvulsasService::PERSONALIZADA, 'nullable', 'max:60'],
            // A personalizada leva só os campos da etiqueta normal.
            'itens.*.nome' => [$ehPersonalizada, 'nullable', 'string', 'max:120'],
            'itens.*.papel' => ['nullable', 'string', 'max:40'],
            'itens.*.turno' => ['nullable', Rule::in(Turno::valores())],
            'itens.*.estande' => ['nullable', 'integer', 'min:1', 'max:9999'],
            // Vai no QR e no Code 128, que só codifica ASCII imprimível.
            'itens.*.codigo' => ['nullable', 'string', 'max:40', 'regex:/^[\x20-\x7E]+$/'],
        ], [
            'itens.required' => 'Escolha ao menos uma etiqueta.',
            'itens.max' => 'No máximo :max etiquetas por vez.',
            'itens.*.nome.required_if' => 'Informe o nome da etiqueta personalizada.',
            'itens.*.codigo.regex' => 'O código aceita só letras sem acento, números, espaço e sinais comuns.',
        ], [
            'itens.*.estande' => 'estande',
            'itens.*.papel' => 'papel',
            'itens.*.codigo' => 'código',
        ]);

        $pdf = $this->etiquetas->pdf(
            $dados['itens'],
            (int) ($dados['inicio'] ?? 1),
            $request->user(),
            $request->boolean('teste'),
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="etiquetas-avulsas.pdf"',
        ]);
    }
}
