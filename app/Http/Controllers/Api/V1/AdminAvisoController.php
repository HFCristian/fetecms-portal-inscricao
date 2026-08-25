<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicoMala;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AvisoRequest;
use App\Models\Aviso;
use App\Services\AvisoService;
use App\Services\InscricoesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Avisos publicados pelo admin (aba "Inscrições").
 */
class AdminAvisoController extends Controller
{
    public function __construct(
        private readonly AvisoService $avisos,
        private readonly InscricoesService $inscricoes,
    ) {}

    /** O que o formulário precisa: variáveis, modelo pronto e as datas de hoje. */
    public function opcoes(): JsonResponse
    {
        return response()->json(['data' => [
            'variaveis' => AvisoService::VARIAVEIS,
            'modelo' => [
                'titulo' => AvisoService::MODELO_TITULO,
                'mensagem' => AvisoService::MODELO_MENSAGEM,
            ],
            'inscricoes' => $this->inscricoes->config(),
            // Públicos combináveis, os mesmos da mala direta.
            'publicos' => PublicoMala::opcoes(),
            'publico_padrao' => array_map(fn ($p) => $p->value, AvisoService::PUBLICO_PADRAO),
            // Quantos o público padrão alcança — o formulário abre com esse número.
            'destinatarios' => $this->avisos->alcancados([]),
        ]]);
    }

    /** Publica um aviso; o anterior do MESMO público sai do ar automaticamente. */
    public function store(AvisoRequest $request): JsonResponse
    {
        $aviso = $this->avisos->publicar(
            $request->user(),
            $request->validated('titulo'),
            $request->validated('mensagem'),
            $request->publicos(),
            $request->expiraEm(),
        );

        return response()->json([
            'data' => $this->avisos->resumo($aviso),
            'meta' => ['message' => 'Aviso publicado. Ele aparece para quem está no público escolhido.'],
        ], 201);
    }

    /** Tira o aviso do ar. */
    public function encerrar(Aviso $aviso): JsonResponse
    {
        $this->avisos->encerrar($aviso);

        return response()->json([
            'data' => $this->avisos->paraTela($aviso->fresh()),
            'meta' => ['message' => 'Aviso encerrado.'],
        ]);
    }

    /** Prévia do texto com as variáveis já resolvidas, antes de publicar. */
    public function previa(AvisoRequest $request): JsonResponse
    {
        return response()->json(['data' => [
            'titulo' => $this->avisos->personalizar($request->validated('titulo')),
            'mensagem' => $this->avisos->personalizar($request->validated('mensagem')),
            // Quantas pessoas o público escolhido alcança hoje.
            'destinatarios' => $this->avisos->alcancados($request->publicos()),
        ]]);
    }

    /** Avisos já publicados, com as contagens de quem viu e quem fechou. */
    public function index(Request $request): JsonResponse
    {
        $avisos = $this->avisos->historico((int) $request->integer('por_pagina', 10));

        return response()->json([
            'data' => $avisos->items(),
            'meta' => [
                'pagina' => $avisos->currentPage(),
                'ultima_pagina' => $avisos->lastPage(),
                'total' => $avisos->total(),
            ],
        ]);
    }

    /** Um aviso com os números do relatório. */
    public function show(Aviso $aviso): JsonResponse
    {
        return response()->json([
            'data' => $this->avisos->resumo($aviso),
            'meta' => ['situacoes' => AvisoService::SITUACOES],
        ]);
    }

    /** Quem viu, quem fechou e quem ainda não viu. */
    public function leitores(Request $request, Aviso $aviso): JsonResponse
    {
        [$situacao, $busca] = $this->recorte($request);

        $pagina = $this->avisos->leitores($aviso, $situacao, $busca, (int) $request->integer('por_pagina', 20));

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'pagina' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
            ],
        ]);
    }

    /** CSV do mesmo recorte da listagem. */
    public function exportar(Request $request, Aviso $aviso): Response
    {
        [$situacao, $busca] = $this->recorte($request);

        $csv = $this->avisos->exportarCsv($aviso, $situacao, $busca);
        $arquivo = 'aviso-'.$aviso->id.'-'.now()->format('Y-m-d-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$arquivo.'"',
        ]);
    }

    /**
     * Filtros da listagem/exportação: situação conhecida (ou nenhuma) e busca.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function recorte(Request $request): array
    {
        $situacao = $request->string('situacao')->toString();
        $busca = trim($request->string('q')->toString());

        return [
            array_key_exists($situacao, AvisoService::SITUACOES) ? $situacao : null,
            $busca !== '' ? $busca : null,
        ];
    }

    /** Aviso no ar agora, se houver. */
    public function ativo(Request $request): JsonResponse
    {
        // Podem ser vários — um por público. A tela lista todos, cada um com o
        // seu botão de encerrar.
        return response()->json([
            'data' => $this->avisos->vigentes()->map(fn (Aviso $aviso) => $this->avisos->resumo($aviso))->all(),
        ]);
    }
}
