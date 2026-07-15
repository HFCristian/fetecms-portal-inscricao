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
        $conversa = $this->chat->obter($request->user());

        // Abrir o chat NÃO cria conversa nem notifica o suporte — só o envio da
        // primeira mensagem cria. Sem conversa, devolve um "casco" vazio.
        if ($conversa === null) {
            return response()->json(['data' => [
                'id' => null,
                'status' => null,
                'status_label' => null,
                'nao_respondida' => false,
                'ultima_mensagem_em' => null,
                'usuario_visto_em' => null,
                'suporte_visto_em' => null,
                'mensagens' => [],
            ]]);
        }

        $this->chat->marcarVistoPeloUsuario($conversa);

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

    /**
     * Há mensagens do suporte não lidas? Consulta leve para a bolinha do botão
     * fechado. NÃO marca a conversa como vista (não altera o recibo de leitura).
     */
    public function naoLidas(Request $request): JsonResponse
    {
        $total = $this->chat->naoLidasDoUsuario($request->user());

        return response()->json(['data' => ['nao_lidas' => $total > 0, 'total' => $total]]);
    }

    /** Marca que o usuário fechou o balão de apresentação do chat (não mostrar mais). */
    public function dispensarDica(Request $request): JsonResponse
    {
        $request->user()->update(['chat_dica_dispensada' => true]);

        return response()->json(['data' => ['chat_dica_dispensada' => true]]);
    }
}
