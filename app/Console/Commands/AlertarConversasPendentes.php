<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\StatusConversa;
use App\Mail\ConversasPendentesAlerta;
use App\Models\Conversa;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa TODOS os administradores (ativos), por e-mail, com a quantidade de
 * mensagens não respondidas (status "não visualizada" ou "visualizada").
 * Agendado para 07:00 todos os dias (ver routes/console.php). Não envia nada
 * quando não há pendências. Se não houver nenhum admin cadastrado, cai no
 * endereço de fallback de config('fetecms.suporte_alerta_email').
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

        $destinatarios = User::query()
            ->where('role', Role::Admin->value)
            ->where('is_active', true)
            ->get();

        // Sem nenhum admin ativo: usa o endereço de fallback para não perder o aviso.
        if ($destinatarios->isEmpty()) {
            $destinatarios = collect([config('fetecms.suporte_alerta_email')]);
        }

        Mail::to($destinatarios)->send(new ConversasPendentesAlerta($quantidade));

        $this->info("Alerta enviado para {$destinatarios->count()} destinatário(s): {$quantidade} mensagem(ns) pendente(s).");

        return self::SUCCESS;
    }
}
