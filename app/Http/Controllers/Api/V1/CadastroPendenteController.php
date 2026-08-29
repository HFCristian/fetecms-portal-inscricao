<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cadastro\ConfirmarCadastroRequest;
use App\Http\Requests\Cadastro\TrocarEmailCadastroRequest;
use App\Http\Resources\CadastroPendenteResource;
use App\Http\Resources\UserResource;
use App\Models\CadastroPendente;
use App\Services\ConfirmacaoCadastroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Confirmação do e-mail no cadastro (orientador e avaliador): a tela mostra o
 * endereço que a pessoa digitou, recebe o código de 6 dígitos e, se ela viu que
 * errou, troca o e-mail sem perder o formulário preenchido.
 *
 * Todas as rotas são públicas — quem está aqui ainda não tem conta. O acesso é
 * pelo token do cadastro pendente, que só o navegador de quem preencheu tem.
 */
class CadastroPendenteController extends Controller
{
    public function __construct(private readonly ConfirmacaoCadastroService $confirmacao) {}

    /** Estado do cadastro pendente (a tela se recompõe depois de um refresh). */
    public function show(CadastroPendente $cadastro): JsonResponse
    {
        return CadastroPendenteResource::make($cadastro)->response();
    }

    /** Código correto: a conta nasce agora e a sessão já entra logada. */
    public function confirmar(ConfirmarCadastroRequest $request, CadastroPendente $cadastro): JsonResponse
    {
        $user = $this->confirmacao->confirmar($cadastro, $request->validated()['codigo']);

        // Mesma experiência de antes: cadastrou, está dentro. Só no fluxo web.
        if ($request->hasSession()) {
            auth()->guard('web')->login($user);
            $request->session()->regenerate();
        }

        return UserResource::make($user)
            ->additional(['meta' => ['message' => 'E-mail confirmado. Cadastro concluído com sucesso.']])
            ->response()
            ->setStatusCode(201);
    }

    /** Novo código para o mesmo endereço. */
    public function reenviar(Request $request, CadastroPendente $cadastro): JsonResponse
    {
        $this->confirmacao->reenviar($cadastro);

        return CadastroPendenteResource::make($cadastro)
            ->additional(['meta' => ['message' => 'Enviamos um novo código para '.$cadastro->email.'.']])
            ->response();
    }

    /** Corrige o e-mail digitado errado e manda o código para o novo endereço. */
    public function trocarEmail(TrocarEmailCadastroRequest $request, CadastroPendente $cadastro): JsonResponse
    {
        $this->confirmacao->trocarEmail($cadastro, $request->validated()['email']);

        return CadastroPendenteResource::make($cadastro)
            ->additional(['meta' => ['message' => 'Enviamos o código para '.$cadastro->email.'.']])
            ->response();
    }
}
