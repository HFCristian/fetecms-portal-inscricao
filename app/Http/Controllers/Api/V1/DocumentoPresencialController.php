<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Projeto;
use App\Services\DocumentosPresenciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba "Documentos" do orientador: o termo de responsabilidade dos projetos
 * **finalistas**, para o evento.
 *
 * Não reaproveita as rotas de anexo da inscrição de propósito: aquelas exigem
 * projeto em rascunho e inscrições abertas, e este documento é exatamente o
 * contrário — projeto submetido, lista final publicada, prazo de inscrição há
 * muito encerrado.
 */
class DocumentoPresencialController extends Controller
{
    public function __construct(private readonly DocumentosPresenciaisService $documentos) {}

    /** Janela + os projetos finalistas do orientador, com o termo de cada um. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $teste = $request->boolean('teste');

        return response()->json(['data' => [
            'janela' => $this->documentos->janela($user, $teste),
            'projetos' => $this->documentos->projetos($user, $teste),
        ]]);
    }

    /** Anexa (ou substitui) o termo de responsabilidade do projeto. */
    public function store(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);

        $request->validate([
            'file' => [
                'required', 'file', 'mimes:pdf',
                'max:'.DocumentosPresenciaisService::MAX_KB,
            ],
        ], [], ['file' => 'termo de responsabilidade']);

        $termo = $this->documentos->anexarTermo(
            $projeto,
            $request->file('file'),
            $request->user(),
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $termo,
            'meta' => ['message' => 'Termo de responsabilidade enviado.'],
        ], 201);
    }

    /** Remove o termo (o orientador reenvia o arquivo corrigido). */
    public function destroy(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);

        $this->documentos->removerTermo($projeto, $request->user(), $request->boolean('teste'));

        return response()->json(['data' => ['message' => 'Termo removido.']]);
    }
}
