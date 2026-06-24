<?php

namespace App\Services;

use App\Enums\StatusConversa;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Notifications\MensagemRespondida;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chat de suporte — lado do admin. Lista as conversas agrupadas por situação,
 * controla o status e envia a resposta (com e-mail ao usuário).
 */
class ChatAdminService
{
    /**
     * Conversas agrupadas para o painel:
     * - nao_respondidas: nao_visualizada + visualizada + em_tratamento;
     * - respondidas; arquivadas.
     * Mais recentes (pela última mensagem) primeiro.
     *
     * @return array{nao_respondidas: Collection, respondidas: Collection, arquivadas: Collection, contagem: array<string, int>}
     */
    public function listar(): array
    {
        $conversas = Conversa::query()
            ->with(['user:id,name,email,role', 'ultimaMensagem'])
            ->orderByDesc('ultima_mensagem_em')
            ->orderByDesc('updated_at')
            ->get();

        $naoRespondidas = $conversas->filter(fn (Conversa $c) => $c->status->naoRespondida())->values();
        $respondidas = $conversas->where('status', StatusConversa::Respondida)->values();
        $arquivadas = $conversas->where('status', StatusConversa::Arquivada)->values();

        return [
            'nao_respondidas' => $naoRespondidas,
            'respondidas' => $respondidas,
            'arquivadas' => $arquivadas,
            'contagem' => [
                'nao_respondidas' => $naoRespondidas->count(),
                'respondidas' => $respondidas->count(),
                'arquivadas' => $arquivadas->count(),
            ],
        ];
    }

    /** Marca como "visualizada" ao abrir (só se ainda estava "não visualizada"). */
    public function marcarVisualizada(Conversa $conversa): Conversa
    {
        if ($conversa->status === StatusConversa::NaoVisualizada) {
            $conversa->update(['status' => StatusConversa::Visualizada]);
        }

        return $conversa;
    }

    /** Altera o status manualmente (em_tratamento / arquivada / visualizada). */
    public function alterarStatus(Conversa $conversa, StatusConversa $status): Conversa
    {
        $conversa->update(['status' => $status]);

        return $conversa;
    }

    /**
     * Registra a resposta do suporte, marca a conversa como "respondida" e
     * notifica o usuário por e-mail com o texto da resposta.
     */
    public function responder(Conversa $conversa, User $admin, string $corpo): Conversa
    {
        $conversa = DB::transaction(function () use ($conversa, $admin, $corpo) {
            $conversa->mensagens()->create([
                'autor' => Mensagem::AUTOR_SUPORTE,
                'autor_user_id' => $admin->id,
                'corpo' => $corpo,
            ]);

            $conversa->update(['status' => StatusConversa::Respondida]);

            return $conversa->fresh(['mensagens', 'user']);
        });

        $conversa->user->notify(new MensagemRespondida($corpo));

        return $conversa;
    }
}
