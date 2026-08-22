<?php

namespace App\Jobs;

use App\Enums\StatusDestinatario;
use App\Mail\MalaDiretaMensagem;
use App\Models\MalaDiretaDestinatario;
use App\Services\MalaDiretaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia UMA mensagem da mala direta. Um job por destinatário: o e-mail que o
 * servidor recusar vira uma linha de falha no relatório sem derrubar o resto
 * do disparo.
 *
 * Requer `php artisan queue:work` rodando no deploy (QUEUE_CONNECTION=database).
 */
class EnviarMalaDireta implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tenta de novo antes de desistir: recusa de SMTP costuma ser passageira. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(public int $destinatarioId) {}

    public function handle(MalaDiretaService $malas): void
    {
        $destinatario = MalaDiretaDestinatario::with('mala')->find($this->destinatarioId);

        // Já processado (ou mala apagada): nada a fazer. Protege contra job repetido.
        if (! $destinatario || ! $destinatario->mala || $destinatario->status !== StatusDestinatario::Pendente) {
            return;
        }

        $mala = $destinatario->mala;

        Mail::to($destinatario->email)->send(new MalaDiretaMensagem(
            $mala,
            $malas->personalizar($mala->corpo, $destinatario),
        ));

        $destinatario->update([
            'status' => StatusDestinatario::Enviado,
            'enviado_em' => now(),
            'erro' => null,
        ]);

        $malas->concluirSePronta($mala);
    }

    /** Esgotadas as tentativas, o motivo vai para o relatório da mala. */
    public function failed(?Throwable $e): void
    {
        $destinatario = MalaDiretaDestinatario::with('mala')->find($this->destinatarioId);

        if (! $destinatario || $destinatario->status !== StatusDestinatario::Pendente) {
            return;
        }

        $destinatario->update([
            'status' => StatusDestinatario::Falha,
            'erro' => Str::limit($e?->getMessage() ?: 'Falha desconhecida no envio.', 500),
        ]);

        if ($destinatario->mala) {
            app(MalaDiretaService::class)->concluirSePronta($destinatario->mala);
        }
    }
}
