<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AvaliacaoPresencial;
use App\Models\Projeto;
use App\Services\AvaliacaoPresencialFluxoService;
use App\Services\LotePresencialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avaliação presencial — lado do avaliador: os estandes que ele avalia no dia
 * da feira e a rubrica que responde em cada um.
 */
class AvaliadorPresencialAvaliacaoController extends Controller
{
    public function __construct(
        private readonly AvaliacaoPresencialFluxoService $fluxo,
        private readonly LotePresencialService $lote,
    ) {}

    /** O painel: o que está com ele e o que ainda pode pegar. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->fluxo->painel($request->user(), $request->boolean('teste')),
        ]);
    }

    /** Abre (ou retoma) a avaliação de um projeto. */
    public function iniciar(Request $request, Projeto $projeto): JsonResponse
    {
        return response()->json([
            'data' => $this->fluxo->iniciar($request->user(), $projeto, $request->boolean('teste')),
            'meta' => ['message' => 'Avaliação aberta.'],
        ]);
    }

    /** Pede permissão para avaliar um projeto fora das designações (regra do comitê). */
    public function pedirPermissao(Request $request, Projeto $projeto): JsonResponse
    {
        $dados = $request->validate([
            'justificativa' => ['nullable', 'string', 'max:500'],
        ], ['justificativa.max' => 'A justificativa pode ter até 500 caracteres.']);

        return response()->json([
            'data' => $this->fluxo->pedirPermissao($request->user(), $projeto, $dados['justificativa'] ?? null, $request->boolean('teste')),
            'meta' => ['message' => 'Pedido enviado: a organização vai responder em Comitê especial.'],
        ], 201);
    }

    /** Uma avaliação para responder (ou reler, depois de enviada). */
    public function show(Request $request, AvaliacaoPresencial $avaliacao): JsonResponse
    {
        abort_unless($avaliacao->avaliador_id === $request->user()->id, 403);

        return response()->json(['data' => $this->fluxo->detalhe($avaliacao, $request->user(), $request->boolean('teste'))]);
    }

    public function rascunho(Request $request, AvaliacaoPresencial $avaliacao): JsonResponse
    {
        $dados = $this->validar($request);

        return response()->json([
            'data' => $this->fluxo->salvarRascunho($request->user(), $avaliacao, $dados, $request->boolean('teste')),
            'meta' => ['message' => 'Rascunho salvo.'],
        ]);
    }

    public function concluir(Request $request, AvaliacaoPresencial $avaliacao): JsonResponse
    {
        $dados = $this->validar($request);

        return response()->json([
            'data' => $this->fluxo->concluir($request->user(), $avaliacao, $dados, $request->boolean('teste')),
            'meta' => ['message' => 'Avaliação enviada.'],
        ]);
    }

    /** A planilha com os projetos designados a ele, para preencher fora do portal. */
    public function modeloLote(Request $request): Response
    {
        return response($this->lote->modelo($request->user(), $request->boolean('teste')), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="avaliacoes-presenciais.xlsx"',
        ]);
    }

    /** A planilha preenchida, linha a linha: o que vira avaliação e o que não vira, com o motivo. */
    public function previaLote(Request $request): JsonResponse
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:2048', 'extensions:xlsx,csv', 'mimes:xlsx,csv,txt,zip'],
        ], [
            'arquivo.extensions' => 'Envie a planilha em .xlsx (ou .csv).',
            'arquivo.mimes' => 'Envie a planilha em .xlsx (ou .csv).',
        ], ['arquivo' => 'planilha']);

        return response()->json([
            'data' => $this->lote->previa($request->user(), $request->file('arquivo'), $request->boolean('teste')),
        ]);
    }

    /** Envia as linhas da prévia; o que o horário não deixa enviar fica como rascunho. */
    public function enviarLote(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'linhas' => ['required', 'array', 'min:1', 'max:'.LotePresencialService::MAX_LINHAS],
            'linhas.*.linha' => ['nullable', 'integer'],
            'linhas.*.projeto_id' => ['required', 'integer'],
            'linhas.*.respostas' => ['nullable', 'array'],
            'linhas.*.comentario' => ['nullable', 'string', 'max:2000'],
        ]);

        $resultado = $this->lote->enviar($request->user(), $dados['linhas'], $request->boolean('teste'));
        $r = $resultado['resumo'];

        return response()->json([
            'data' => $resultado,
            'meta' => ['message' => implode(' · ', array_filter([
                $r['enviadas'] ? "{$r['enviadas']} avaliação(ões) enviada(s)" : null,
                $r['rascunhos'] ? "{$r['rascunhos']} salva(s) como rascunho" : null,
                $r['erros'] ? "{$r['erros']} com erro" : null,
            ])).'.'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        return $request->validate([
            'respostas' => ['nullable', 'array'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
