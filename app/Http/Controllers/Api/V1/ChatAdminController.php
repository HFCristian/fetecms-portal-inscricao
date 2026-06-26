<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AtualizarStatusConversaRequest;
use App\Http\Requests\Admin\ResponderConversaRequest;
use App\Http\Resources\ConversaResource;
use App\Models\Conversa;
use App\Services\ChatAdminService;
use Illuminate\Http\JsonResponse;

/**
 * Chat de suporte — painel do admin. Lista conversas agrupadas, abre o
 * histórico (marcando como visualizada), troca de status e responde.
 */
class ChatAdminController extends Controller
{
    public function __construct(private readonly ChatAdminService $chat) {}

    /** Conversas agrupadas (não respondidas / respondidas / arquivadas) + contagens. */
    public function index(): JsonResponse
    {
        $dados = $this->chat->listar();

        return response()->json([
            'data' => [
                'nao_respondidas' => ConversaResource::collection($dados['nao_respondidas']),
                'respondidas' => ConversaResource::collection($dados['respondidas']),
                'arquivadas' => ConversaResource::collection($dados['arquivadas']),
            ],
            'meta' => ['contagem' => $dados['contagem']],
        ]);
    }

    /** Quantidade de conversas não visualizadas (badge do menu do admin). */
    public function naoVistas(): JsonResponse
    {
        return response()->json(['data' => ['total' => $this->chat->naoVisualizadasCount()]]);
    }

    /** Abre o histórico completo; marca como "visualizada" se ainda não foi. */
    public function show(Conversa $conversa): ConversaResource
    {
        $this->chat->marcarVisualizada($conversa);

        return ConversaResource::make($conversa->load(['mensagens', 'user']));
    }

    /** Atualiza o status manualmente (em tratamento / arquivada / visualizada). */
    public function atualizarStatus(AtualizarStatusConversaRequest $request, Conversa $conversa): ConversaResource
    {
        $this->chat->alterarStatus($conversa, $request->statusEnum());

        return ConversaResource::make($conversa->load(['mensagens', 'user']));
    }

    /** Responde a conversa: registra a resposta, marca "respondida" e e-mails ao usuário. */
    public function responder(ResponderConversaRequest $request, Conversa $conversa): ConversaResource
    {
        $conversa = $this->chat->responder($conversa, $request->user(), $request->validated('corpo'));

        return ConversaResource::make($conversa->load(['mensagens', 'user']));
    }
}
