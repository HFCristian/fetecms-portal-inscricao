<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicoMala;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CriarFeedbackRequest;
use App\Models\Feedback;
use App\Services\FeedbackResultadoService;
use App\Services\FeedbackService;
use App\Support\ModelosAlternativas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comunicação → Feedback (lado do admin): criar o pedido, acompanhar o envio
 * dos convites e ler os resultados.
 */
class AdminFeedbackController extends Controller
{
    public function __construct(
        private readonly FeedbackService $feedbacks,
        private readonly FeedbackResultadoService $resultados,
    ) {}

    /** A lista de pedidos, com quantos responderam em cada um. */
    public function index(): JsonResponse
    {
        $pedidos = Feedback::query()
            ->withCount([
                'perguntas',
                'destinatarios as convidados_count',
                'participacoes as respostas_count' => fn ($q) => $q->whereNotNull('respondido_em'),
            ])
            ->with('autor:id,name')
            ->latest('id')
            ->get()
            ->map(fn (Feedback $f) => [
                'id' => $f->id,
                'titulo' => $f->titulo,
                'descricao' => $f->descricao,
                'status' => $f->status->value,
                'status_label' => $f->status->label(),
                'perguntas' => $f->perguntas_count,
                'convidados' => $f->convidados_count,
                'respostas' => $f->respostas_count,
                'publicos' => array_map(fn (PublicoMala $p) => $p->label(), $f->publicosEnum()),
                'autor' => $f->autor?->name,
                'criado_em' => $f->created_at?->format('d/m/Y H:i'),
            ]);

        return response()->json(['data' => $pedidos]);
    }

    /** O que o formulário de criação precisa: públicos e modelos de alternativas. */
    public function opcoes(): JsonResponse
    {
        return response()->json(['data' => [
            'publicos' => PublicoMala::opcoes(),
            'modelos' => ModelosAlternativas::opcoes(),
            'max_perguntas' => CriarFeedbackRequest::MAX_PERGUNTAS,
            'max_opcoes' => CriarFeedbackRequest::MAX_OPCOES,
        ]]);
    }

    public function store(CriarFeedbackRequest $request): JsonResponse
    {
        $feedback = $this->feedbacks->criar($request->validated(), $request->user());

        return response()->json([
            'data' => ['id' => $feedback->id],
            'meta' => ['message' => 'Feedback publicado. Os convites estão saindo.'],
        ], 201);
    }

    /** O painel de um pedido: números, resultados e progresso do envio. */
    public function show(Feedback $feedback): JsonResponse
    {
        $feedback->load('perguntas');

        return response()->json(['data' => [
            'id' => $feedback->id,
            'titulo' => $feedback->titulo,
            'descricao' => $feedback->descricao,
            'status' => $feedback->status->value,
            'status_label' => $feedback->status->label(),
            'publicos' => array_map(fn (PublicoMala $p) => $p->label(), $feedback->publicosEnum()),
            'criado_em' => $feedback->created_at?->format('d/m/Y H:i'),
            'encerrado_em' => $feedback->encerrado_em?->format('d/m/Y H:i'),
            'resumo' => $this->resultados->resumo($feedback),
            'perguntas' => $this->resultados->porPergunta($feedback),
        ]]);
    }

    /** Relatório de envio, endereço a endereço (como o da mala direta). */
    public function destinatarios(Request $request, Feedback $feedback): JsonResponse
    {
        $situacao = $request->validate([
            'situacao' => ['nullable', 'string'],
        ])['situacao'] ?? null;

        return response()->json($this->resultados->destinatarios($feedback, $situacao));
    }

    /** Reenfileira só os convites que falharam. */
    public function reenviarFalhas(Feedback $feedback): JsonResponse
    {
        $quantos = $this->feedbacks->reenviarFalhas($feedback);

        return response()->json([
            'data' => $this->resultados->resumo($feedback),
            'meta' => ['message' => $quantos === 0
                ? 'Não há falhas para reenviar.'
                : "{$quantos} convite(s) reenviado(s)."],
        ]);
    }

    /** Encerra o pedido: sai do ar, os resultados ficam. */
    public function encerrar(Feedback $feedback): JsonResponse
    {
        $this->feedbacks->encerrar($feedback);

        return response()->json([
            'data' => ['status' => $feedback->fresh()->status->value],
            'meta' => ['message' => 'Feedback encerrado.'],
        ]);
    }

    /** Exporta as respostas escritas e as contagens em CSV. */
    public function exportar(Feedback $feedback): StreamedResponse
    {
        $perguntas = $this->resultados->porPergunta($feedback);
        $nome = 'feedback-'.$feedback->id.'.csv';

        return response()->streamDownload(function () use ($perguntas) {
            $saida = fopen('php://output', 'w');
            // BOM + ";" para o Excel em pt-BR abrir sem passo extra.
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Pergunta', 'Tipo', 'Resposta', 'Total'], ';');

            foreach ($perguntas as $p) {
                if ($p['tipo'] === 'alternativa') {
                    foreach ($p['opcoes'] as $o) {
                        fputcsv($saida, [$p['enunciado'], 'Alternativa', $o['opcao'], $o['total']], ';');
                    }
                } else {
                    foreach ($p['textos'] as $texto) {
                        fputcsv($saida, [$p['enunciado'], 'Dissertativa', $texto, 1], ';');
                    }
                }
            }

            fclose($saida);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
