<?php

namespace App\Jobs;

use App\Enums\ModeloEmail;
use App\Enums\StatusDestinatario;
use App\Models\FeedbackDestinatario;
use App\Services\FeedbackService;
use App\Services\ModeloEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia UM convite de feedback. Um job por destinatário, como na mala direta: o
 * e-mail que o servidor recusar vira uma linha de falha no relatório sem
 * derrubar o resto do disparo.
 *
 * Requer `php artisan queue:work` rodando no deploy.
 */
class EnviarConviteFeedback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Recusa de SMTP costuma ser passageira. */
    public int $tries = 3;

    public function __construct(private readonly int $destinatarioId) {}

    public function handle(ModeloEmailService $modelos, FeedbackService $feedbacks): void
    {
        $destinatario = FeedbackDestinatario::with('feedback.perguntas')->find($this->destinatarioId);

        // Já processado (ou apagado com o feedback): nada a fazer.
        if ($destinatario === null || $destinatario->status !== StatusDestinatario::Pendente) {
            return;
        }

        $feedback = $destinatario->feedback;

        if ($feedback === null) {
            return;
        }

        $mensagem = $modelos->mensagem(ModeloEmail::FeedbackSolicitado, [
            'nome' => Str::before((string) $destinatario->nome, ' ') ?: ($destinatario->nome ?? 'participante'),
            'nome_completo' => $destinatario->nome ?? 'participante',
            'email' => $destinatario->email,
            'titulo' => $feedback->titulo,
            'descricao' => (string) $feedback->descricao,
            'perguntas' => (string) $feedback->perguntas->count(),
        ]);

        try {
            Mail::to($destinatario->email)->send($mensagem);

            $destinatario->update([
                'status' => StatusDestinatario::Enviado,
                'erro' => null,
                'enviado_em' => now(),
            ]);
        } catch (Throwable $e) {
            $destinatario->update([
                'status' => StatusDestinatario::Falha,
                'erro' => Str::limit($e->getMessage(), 500),
            ]);
        }

        // O pedido só entra "no ar" quando não sobra convite pendente. Conferir
        // aqui é barato e evita depender de agendador.
        $feedbacks->concluirEnvioSeTerminou($feedback->fresh());
    }

    /** Estourou as tentativas: registra a falha para o relatório não mentir. */
    public function failed(Throwable $e): void
    {
        FeedbackDestinatario::where('id', $this->destinatarioId)
            ->where('status', StatusDestinatario::Pendente->value)
            ->update([
                'status' => StatusDestinatario::Falha->value,
                'erro' => Str::limit($e->getMessage(), 500),
            ]);
    }
}
