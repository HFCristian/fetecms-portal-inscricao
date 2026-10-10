<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Services\DocumentosPresenciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba "Documentos" do orientador: o termo de responsabilidade e o termo de uso
 * de imagem dos projetos **finalistas**, para o evento.
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

    /**
     * Envia um arquivo do termo de uso de imagem (Sprint 174), com os
     * estudantes para quem ele vale. Os anteriores ficam: o projeto pode ter
     * um arquivo por estudante.
     */
    public function storeImagem(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);

        $request->validate([
            'file' => [
                'required', 'file', 'mimes:'.implode(',', DocumentosPresenciaisService::MIMES_IMAGEM),
                'max:'.DocumentosPresenciaisService::MAX_KB,
            ],
            'alunos' => ['required', 'array', 'min:1'],
            'alunos.*' => ['integer', 'distinct'],
        ], [
            'file.mimes' => 'Envie o termo em PDF, JPG ou PNG.',
            'alunos.required' => 'Marque ao menos um estudante para quem o termo vale.',
            'alunos.min' => 'Marque ao menos um estudante para quem o termo vale.',
        ], ['file' => 'termo de uso de imagem', 'alunos' => 'estudantes']);

        $termo = $this->documentos->anexarTermoImagem(
            $projeto,
            $request->file('file'),
            $request->input('alunos', []),
            $request->user(),
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $termo,
            'meta' => ['message' => 'Termo de uso de imagem enviado.'],
        ], 201);
    }

    /** Troca para quais estudantes um arquivo do termo de imagem vale. */
    public function updateImagem(Request $request, ProjetoDocumento $documento): JsonResponse
    {
        $this->authorize('view', $documento->projeto ?? abort(404));

        $request->validate([
            'alunos' => ['required', 'array', 'min:1'],
            'alunos.*' => ['integer', 'distinct'],
        ], [
            'alunos.required' => 'Marque ao menos um estudante para quem o termo vale.',
            'alunos.min' => 'Marque ao menos um estudante para quem o termo vale.',
        ], ['alunos' => 'estudantes']);

        $termo = $this->documentos->definirAlunosTermoImagem(
            $documento, $request->input('alunos', []), $request->user(), $request->boolean('teste'),
        );

        return response()->json([
            'data' => $termo,
            'meta' => ['message' => 'Estudantes do termo atualizados.'],
        ]);
    }

    /** Remove um arquivo do termo de imagem. */
    public function destroyImagem(Request $request, ProjetoDocumento $documento): JsonResponse
    {
        $this->authorize('view', $documento->projeto ?? abort(404));

        $termo = $this->documentos->removerTermoImagem($documento, $request->user(), $request->boolean('teste'));

        return response()->json([
            'data' => $termo,
            'meta' => ['message' => 'Arquivo removido.'],
        ]);
    }

    /** Remove o termo (o orientador reenvia o arquivo corrigido). */
    public function destroy(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);

        $this->documentos->removerTermo($projeto, $request->user(), $request->boolean('teste'));

        return response()->json(['data' => ['message' => 'Termo removido.']]);
    }
}
