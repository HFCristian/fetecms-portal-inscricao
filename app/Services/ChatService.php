<?php

namespace App\Services;

use App\Enums\StatusConversa;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Chat de suporte — lado do usuário (orientador/avaliador). Cada usuário tem
 * uma única conversa (thread) com o suporte; novas mensagens a reabrem.
 */
class ChatService
{
    /** Conversa do usuário, criada na primeira vez que ele abre o chat. */
    public function obterOuCriar(User $user): Conversa
    {
        return Conversa::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => StatusConversa::NaoVisualizada],
        );
    }

    /**
     * Registra que o usuário está vendo a conversa agora (recibo de leitura das
     * mensagens do suporte). Chamado ao abrir o chat e a cada polling.
     */
    public function marcarVistoPeloUsuario(Conversa $conversa): Conversa
    {
        $conversa->forceFill(['usuario_visto_em' => now()])->save();

        return $conversa;
    }

    /**
     * Registra uma mensagem do usuário. Reabre a conversa como "não visualizada"
     * para o suporte ver que há conteúdo novo, mesmo que já tivesse sido respondida.
     */
    public function enviarMensagem(User $user, string $corpo): Conversa
    {
        return DB::transaction(function () use ($user, $corpo) {
            $conversa = $this->obterOuCriar($user);

            $conversa->mensagens()->create([
                'autor' => Mensagem::AUTOR_USUARIO,
                'corpo' => $corpo,
            ]);

            $conversa->update([
                'status' => StatusConversa::NaoVisualizada,
                'ultima_mensagem_em' => now(),
            ]);

            return $conversa->fresh('mensagens');
        });
    }
}
