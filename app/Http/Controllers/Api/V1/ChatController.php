<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\EnviarMensagemRequest;
use App\Http\Resources\ConversaResource;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat de suporte — endpoints do orientador/avaliador. Cada usuário acessa
 * apenas a própria conversa (resolvida sempre a partir do usuário autenticado).
 */
class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    /** Conversa do usuário autenticado, com todo o histórico de mensagens. */
    public function show(Request $request): JsonResponse
    {
        $conversa = $this->chat->obterOuCriar($request->user());
        $this->chat->marcarVistoPeloUsuario($conversa);

        // setStatusCode(200): firstOrCreate marca o model como "recém-criado" e o
        // Laravel responderia 201 num GET na primeira vez que o chat é aberto.
        return ConversaResource::make($conversa->load('mensagens'))
            ->response()
            ->setStatusCode(200);
    }

    /** Envia uma mensagem do usuário ao suporte. */
    public function store(EnviarMensagemRequest $request): ConversaResource
    {
        $conversa = $this->chat->enviarMensagem($request->user(), $request->validated('corpo'));

        return ConversaResource::make($conversa->load('mensagens'));
    }
}
