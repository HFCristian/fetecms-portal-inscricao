<?php

namespace App\Console\Commands;

use App\Enums\StatusConversa;
use App\Mail\ConversasPendentesAlerta;
use App\Models\Conversa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Envia ao suporte um aviso com a quantidade de mensagens não respondidas
 * (status "não visualizada" ou "visualizada"). Agendado para 07:00 todos os
 * dias (ver routes/console.php). Não envia nada quando não há pendências.
 */
class AlertarConversasPendentes extends Command
{
    protected $signature = 'chat:alertar-pendentes';

    protected $description = 'Avisa o suporte por e-mail sobre mensagens de chat não respondidas';

    public function handle(): int
    {
        $quantidade = Conversa::query()
            ->whereIn('status', array_map(
                fn (StatusConversa $s) => $s->value,
                StatusConversa::pendentesAlerta(),
            ))
            ->count();

        if ($quantidade === 0) {
            $this->info('Nenhuma mensagem pendente. Alerta não enviado.');

            return self::SUCCESS;
        }

        $destino = config('fetecms.suporte_alerta_email');
        Mail::to($destino)->send(new ConversasPendentesAlerta($quantidade));

        $this->info("Alerta enviado para {$destino}: {$quantidade} mensagem(ns) pendente(s).");

        return self::SUCCESS;
    }
}
