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
     * Conversa do usuário, se já existir (NÃO cria). Abrir o chat não deve criar
     * conversa nem notificar o suporte — isso só acontece ao enviar a 1ª mensagem.
     */
    public function obter(User $user): ?Conversa
    {
        return Conversa::where('user_id', $user->id)->first();
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
     * Quantas mensagens do suporte o usuário ainda não viu (criadas após o último
     * usuario_visto_em). Consulta somente leitura — NÃO marca a conversa como vista,
     * para o botão fechado poder exibir a bolinha de "não lidas".
     */
    public function naoLidasDoUsuario(User $user): int
    {
        $conversa = Conversa::where('user_id', $user->id)->first();

        if (! $conversa) {
            return 0;
        }

        return $conversa->mensagens()
            ->where('autor', Mensagem::AUTOR_SUPORTE)
            ->when(
                $conversa->usuario_visto_em,
                fn ($q) => $q->where('created_at', '>', $conversa->usuario_visto_em),
            )
            ->count();
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
