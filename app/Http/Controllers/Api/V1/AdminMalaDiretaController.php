<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicoMala;
use App\Enums\StatusDestinatario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CriarMalaDiretaRequest;
use App\Http\Requests\Admin\MalaDiretaArquivoRequest;
use App\Http\Requests\Admin\PreviaMalaDiretaRequest;
use App\Http\Requests\Admin\TesteMalaDiretaRequest;
use App\Http\Resources\MalaDiretaDestinatarioResource;
use App\Http\Resources\MalaDiretaResource;
use App\Models\MalaDireta;
use App\Models\MalaDiretaArquivo;
use App\Services\MalaDiretaArquivoService;
use App\Services\MalaDiretaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mala direta (painel do admin): monta o público, mostra a prévia, dispara o
 * comunicado pela fila e devolve o progresso/relatório de cada envio.
 */
class AdminMalaDiretaController extends Controller
{
    public function __construct(private readonly MalaDiretaService $malas) {}

    /** Malas já disparadas, da mais recente para a mais antiga. */
    public function index(Request $request): JsonResponse
    {
        $pagina = $this->malas->listar((int) $request->integer('por_pagina', 20) ?: 20);

        return response()->json([
            'data' => MalaDiretaResource::collection($pagina->items())->resolve(),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'publicos' => PublicoMala::opcoes(),
                'situacoes' => StatusDestinatario::opcoes(),
            ],
        ]);
    }

    /** Opções de público e de situação — é o que a tela de composição desenha. */
    public function opcoes(): JsonResponse
    {
        return response()->json([
            'data' => [
                'publicos' => PublicoMala::opcoes(),
                'situacoes' => StatusDestinatario::opcoes(),
                'variaveis' => MalaDiretaService::VARIAVEIS,
                'max_personalizados' => MalaDiretaService::MAX_PERSONALIZADOS,
            ],
        ]);
    }

    /**
     * Quantos e quais e-mails receberiam a mensagem com o critério atual —
     * a contagem que o admin vê antes de confirmar o disparo.
     */
    public function previa(PreviaMalaDiretaRequest $request): JsonResponse
    {
        $lista = $this->malas->resolver($request->publicos(), $request->destinatarios());
        $invalidos = $lista->where('status', StatusDestinatario::Invalido->value)->count();

        $porPagina = (int) ($request->validated('por_pagina') ?? 25);
        $pagina = max(1, (int) ($request->validated('pagina') ?? 1));

        return response()->json([
            'data' => $lista->forPage($pagina, $porPagina)->values()->all(),
            'meta' => [
                'total' => $lista->count(),
                'validos' => $lista->count() - $invalidos,
                'invalidos' => $invalidos,
                'por_publico' => $this->malas->totaisPorPublico($request->publicos()),
                'pagina_atual' => $pagina,
                'por_pagina' => $porPagina,
                'ultima_pagina' => max(1, (int) ceil($lista->count() / $porPagina)),
            ],
        ]);
    }

    /** CSV da prévia (mesmo recorte da tela), antes de qualquer envio. */
    public function exportarPrevia(PreviaMalaDiretaRequest $request): Response
    {
        $csv = $this->malas->exportarCsv($this->malas->resolver($request->publicos(), $request->destinatarios()));

        return $this->csv($csv, 'destinatarios-'.now()->format('Y-m-d-His').'.csv');
    }

    /** Confirma o disparo: congela a lista e joga os e-mails na fila. */
    public function store(CriarMalaDiretaRequest $request): JsonResponse
    {
        $dados = $request->dados();
        $lista = $this->malas->resolver($dados['publicos'], $dados['destinatarios']);

        if ($lista->where('status', StatusDestinatario::Pendente->value)->isEmpty()) {
            throw ValidationException::withMessages([
                'publicos' => 'Nenhum destinatário válido para este critério — revise os públicos e a lista personalizada.',
            ]);
        }

        $mala = $this->malas->criar($dados, $request->user());

        return response()->json(['data' => (new MalaDiretaResource($mala->fresh()))->resolve()], 201);
    }

    /**
     * Manda a mensagem que está sendo escrita para os endereços de conferência,
     * sem tocar na base. Percorre a fila como o disparo de verdade — é o único
     * jeito de o teste denunciar um worker desatualizado.
     */
    public function teste(TesteMalaDiretaRequest $request): JsonResponse
    {
        $dados = $request->dados();
        $lista = $this->malas->resolver([], $dados['destinatarios']);

        if ($lista->where('status', StatusDestinatario::Pendente->value)->isEmpty()) {
            throw ValidationException::withMessages([
                'destinatarios' => 'Nenhum e-mail válido para o teste — confira os endereços.',
            ]);
        }

        $mala = $this->malas->enviarTeste($dados, $request->user());

        return response()->json(['data' => (new MalaDiretaResource($mala->fresh()))->resolve()], 201);
    }

    /**
     * Sobe uma imagem do corpo ou um anexo. O arquivo nasce solto e só é
     * vinculado à mala no disparo — o admin ainda está escrevendo.
     */
    public function subirArquivo(MalaDiretaArquivoRequest $request, MalaDiretaArquivoService $arquivos): JsonResponse
    {
        $arquivo = $arquivos->armazenar(
            $request->file('arquivo'),
            $request->validated('tipo'),
            $request->user(),
        );

        return response()->json(['data' => $this->arquivoJson($arquivo)], 201);
    }

    /** Serve o arquivo para a prévia no painel (nunca é link público). */
    public function baixarArquivo(MalaDiretaArquivo $arquivo): Response
    {
        return Storage::disk($arquivo->disk)->response($arquivo->path, $arquivo->nome_original, [
            'Content-Type' => $arquivo->mime ?? 'application/octet-stream',
        ]);
    }

    /** Remove um arquivo que ainda não foi disparado. */
    public function removerArquivo(MalaDiretaArquivo $arquivo, MalaDiretaArquivoService $arquivos): JsonResponse
    {
        $arquivos->remover($arquivo);

        return response()->json(['data' => ['removido' => true]]);
    }

    /** @return array<string, mixed> */
    private function arquivoJson(MalaDiretaArquivo $arquivo): array
    {
        return [
            'id' => $arquivo->id,
            'tipo' => $arquivo->tipo,
            'nome' => $arquivo->nome_original,
            'mime' => $arquivo->mime,
            'tamanho_bytes' => $arquivo->tamanho_bytes,
            'url' => '/api/v1/admin/mala-direta/arquivos/'.$arquivo->id,
        ];
    }

    /** Progresso/relatório de uma mala (a tela faz polling deste endpoint). */
    public function show(MalaDireta $mala): JsonResponse
    {
        return response()->json(['data' => (new MalaDiretaResource($mala))->resolve()]);
    }

    /** Destinatários da mala, filtráveis por situação (ex.: só as falhas). */
    public function destinatarios(Request $request, MalaDireta $mala): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(StatusDestinatario::cases(), 'value'))],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $pagina = $this->malas
            ->queryDestinatarios($mala, $request->string('status')->toString() ?: null)
            ->paginate((int) $request->integer('por_pagina', 25) ?: 25)
            ->withQueryString();

        return response()->json([
            'data' => MalaDiretaDestinatarioResource::collection($pagina->items())->resolve(),
            'meta' => [
                'pagina_atual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'situacoes' => StatusDestinatario::opcoes(),
            ],
        ]);
    }

    /** CSV do relatório da mala (com a situação de cada envio). */
    public function exportar(MalaDireta $mala): Response
    {
        $csv = $this->malas->exportarCsv($this->malas->queryDestinatarios($mala)->get());

        return $this->csv($csv, 'mala-'.$mala->id.'-destinatarios.csv');
    }

    /** Recoloca na fila só quem falhou (os inválidos continuam de fora). */
    public function reenviarFalhas(MalaDireta $mala): JsonResponse
    {
        $recolocados = $this->malas->reenviarFalhas($mala);

        return response()->json([
            'data' => (new MalaDiretaResource($mala->fresh()))->resolve(),
            'meta' => ['reenviados' => $recolocados],
        ]);
    }

    private function csv(string $csv, string $arquivo): Response
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$arquivo.'"',
        ]);
    }
}
