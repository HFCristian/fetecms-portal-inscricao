<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ItemConferencia;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Services\DocumentosPresenciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aba "Documentos" do orientador: o que a organização quer receber **antes do
 * evento** dos projetos finalistas — os itens da lista única marcados "receber
 * antes" (Sprint 183), entre eles os dois termos.
 *
 * Não reaproveita as rotas de anexo da inscrição de propósito: aquelas exigem
 * projeto em rascunho e inscrições abertas, e este documento é exatamente o
 * contrário — projeto submetido, lista final publicada, prazo de inscrição há
 * muito encerrado.
 */
class DocumentoPresencialController extends Controller
{
    public function __construct(private readonly DocumentosPresenciaisService $documentos) {}

    /** Janela + os projetos finalistas do orientador, item a item. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $teste = $request->boolean('teste');

        return response()->json(['data' => [
            'janela' => $this->documentos->janela($user, $teste),
            'projetos' => $this->documentos->projetos($user, $teste),
        ]]);
    }

    /** Envia um arquivo para um item, com as pessoas para quem ele vale. */
    public function store(Request $request, Projeto $projeto, ItemConferencia $item): JsonResponse
    {
        $this->authorize('view', $projeto);

        $request->validate([
            'file' => [
                'required', 'file', 'mimes:'.implode(',', DocumentosPresenciaisService::MIMES),
                'max:'.DocumentosPresenciaisService::MAX_KB,
            ],
            'pessoas' => ['sometimes', 'array'],
            'pessoas.*' => ['string', 'max:40', 'distinct'],
        ], [
            'file.mimes' => 'Envie o arquivo em PDF, JPG ou PNG.',
        ], ['file' => 'arquivo', 'pessoas' => 'pessoas']);

        $secao = $this->documentos->anexar(
            $projeto,
            $item,
            $request->file('file'),
            $request->input('pessoas', []),
            $request->user(),
            $request->boolean('teste'),
        );

        return response()->json([
            'data' => $secao,
            'meta' => ['message' => "{$item->nome}: arquivo enviado."],
        ], 201);
    }

    /** Troca para quem um arquivo vale. */
    public function update(Request $request, ProjetoDocumento $documento): JsonResponse
    {
        $this->authorize('view', $documento->projeto ?? abort(404));

        $request->validate([
            'pessoas' => ['required', 'array', 'min:1'],
            'pessoas.*' => ['string', 'max:40', 'distinct'],
        ], [
            'pessoas.required' => 'Marque ao menos uma pessoa para quem o arquivo vale.',
            'pessoas.min' => 'Marque ao menos uma pessoa para quem o arquivo vale.',
        ], ['pessoas' => 'pessoas']);

        $secao = $this->documentos->definirPessoas(
            $documento, $request->input('pessoas', []), $request->user(), $request->boolean('teste'),
        );

        return response()->json(['data' => $secao, 'meta' => ['message' => 'Pessoas do arquivo atualizadas.']]);
    }

    /** Remove um arquivo enviado antes. */
    public function destroy(Request $request, ProjetoDocumento $documento): JsonResponse
    {
        $this->authorize('view', $documento->projeto ?? abort(404));

        $secao = $this->documentos->remover($documento, $request->user(), $request->boolean('teste'));

        return response()->json(['data' => $secao, 'meta' => ['message' => 'Arquivo removido.']]);
    }
}
