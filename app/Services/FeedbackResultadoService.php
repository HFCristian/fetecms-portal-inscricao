<?php

namespace App\Services;

use App\Enums\StatusDestinatario;
use App\Models\Feedback;
use App\Models\FeedbackDestinatario;
use App\Models\FeedbackPergunta;
use App\Models\FeedbackResposta;

/**
 * Os resultados de um pedido de feedback: a contagem de cada alternativa e a
 * lista das respostas escritas.
 *
 * Tudo aqui é **anônimo por construção** — as respostas não têm `user_id` para
 * consultar. O que se sabe de gente vem das participações, e só como número.
 */
class FeedbackResultadoService
{
    public function __construct(private readonly FeedbackService $feedbacks) {}

    /**
     * Números do pedido: quantos foram convidados, quantos viram o balão e
     * quantos responderam.
     *
     * @return array<string, mixed>
     */
    public function resumo(Feedback $feedback): array
    {
        $participacoes = $feedback->participacoes();
        $convidados = FeedbackDestinatario::where('feedback_id', $feedback->id)->count();
        $responderam = (clone $participacoes)->whereNotNull('respondido_em')->count();

        return [
            'convidados' => $convidados,
            'viram' => (clone $participacoes)->whereNotNull('visto_em')->count(),
            'dispensaram' => (clone $participacoes)->whereNotNull('dispensado_em')->whereNull('respondido_em')->count(),
            'responderam' => $responderam,
            // Taxa sobre quem foi convidado; sem convidados não há taxa a dar.
            'taxa_resposta' => $convidados > 0 ? round($responderam / $convidados * 100, 1) : null,
            'envio' => $this->feedbacks->progressoEnvio($feedback),
        ];
    }

    /**
     * Uma seção por pergunta. Alternativa vira contagem por opção (com o total
     * e o percentual); dissertativa vira a lista dos textos.
     *
     * @return list<array<string, mixed>>
     */
    public function porPergunta(Feedback $feedback): array
    {
        return $feedback->perguntas
            ->map(fn (FeedbackPergunta $p) => $p->ehAlternativa()
                ? $this->alternativas($p)
                : $this->dissertativas($p))
            ->all();
    }

    /** @return array<string, mixed> */
    private function alternativas(FeedbackPergunta $pergunta): array
    {
        $contagem = FeedbackResposta::where('pergunta_id', $pergunta->id)
            ->selectRaw('valor, COUNT(*) as total')
            ->groupBy('valor')
            ->pluck('total', 'valor');

        $total = (int) $contagem->sum();

        // Sai sempre com TODAS as opções, mesmo as zeradas: um gráfico sem a
        // opção que ninguém marcou esconde justamente a informação.
        $opcoes = array_map(fn (string $opcao) => [
            'opcao' => $opcao,
            'total' => (int) ($contagem[$opcao] ?? 0),
            'percentual' => $total > 0 ? round(((int) ($contagem[$opcao] ?? 0)) / $total * 100, 1) : 0.0,
        ], $pergunta->opcoes ?? []);

        return [
            'id' => $pergunta->id,
            'tipo' => $pergunta->tipo->value,
            'enunciado' => $pergunta->enunciado,
            'respostas' => $total,
            'opcoes' => $opcoes,
        ];
    }

    /** @return array<string, mixed> */
    private function dissertativas(FeedbackPergunta $pergunta): array
    {
        $textos = FeedbackResposta::where('pergunta_id', $pergunta->id)
            ->orderBy('id')
            ->pluck('valor')
            ->all();

        return [
            'id' => $pergunta->id,
            'tipo' => $pergunta->tipo->value,
            'enunciado' => $pergunta->enunciado,
            'respostas' => count($textos),
            'textos' => $textos,
        ];
    }

    /**
     * O relatório de envio, endereço a endereço — igual ao da mala direta.
     *
     * @return array<string, mixed>
     */
    public function destinatarios(Feedback $feedback, ?string $situacao = null, int $porPagina = 50): array
    {
        $query = FeedbackDestinatario::where('feedback_id', $feedback->id)
            ->when(
                $situacao !== null && StatusDestinatario::tryFrom($situacao) !== null,
                fn ($q) => $q->where('status', $situacao),
            )
            ->orderBy('nome');

        $pagina = $query->paginate($porPagina);

        return [
            'data' => collect($pagina->items())->map(fn (FeedbackDestinatario $d) => [
                'id' => $d->id,
                'nome' => $d->nome,
                'email' => $d->email,
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'erro' => $d->erro,
                'enviado_em' => $d->enviado_em?->format('d/m/Y H:i'),
            ])->all(),
            'meta' => [
                'total' => $pagina->total(),
                'pagina' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ];
    }
}
