<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TipoDocumento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projeto\DocumentoRequest;
use App\Http\Resources\DocumentoResource;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Services\DocumentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoController extends Controller
{
    public function __construct(private readonly DocumentoService $documentos) {}

    public function index(Projeto $projeto): AnonymousResourceCollection
    {
        $this->authorize('view', $projeto);

        return DocumentoResource::collection($projeto->documentos);
    }

    public function store(DocumentoRequest $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('update', $projeto); // dono + rascunho

        $documento = $this->documentos->armazenar(
            $projeto,
            $request->file('file'),
            TipoDocumento::from($request->validated('tipo')),
        );

        return DocumentoResource::make($documento)->response()->setStatusCode(201);
    }

    public function download(ProjetoDocumento $documento): StreamedResponse
    {
        $this->authorize('view', $documento->projeto);

        return Storage::disk($documento->disk)->download($documento->path, $documento->nome_original);
    }

    public function destroy(ProjetoDocumento $documento): JsonResponse
    {
        $this->authorize('update', $documento->projeto);

        $this->documentos->remover($documento);

        return response()->json(['data' => ['message' => 'Documento removido.']]);
    }
}
