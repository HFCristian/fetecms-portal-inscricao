<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AlterarSenhaRequest;
use App\Http\Requests\Auth\EsqueciSenhaRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RedefinirSenhaRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): UserResource
    {
        $data = $request->validated();

        $user = $this->auth->attempt(
            $data['email'],
            $data['password'],
            (bool) ($data['remember'] ?? false),
        );

        // Sessão nova após login previne fixation (Sanctum SPA).
        // Só há sessão em requisições do front (stateful); mobile usa token.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return UserResource::make($user->load(['orientadorProfile', 'avaliadorProfile.area', 'avaliadorProfile.subarea']));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['data' => ['message' => 'Sessão encerrada.']]);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make(
            $request->user()->load(['orientadorProfile', 'avaliadorProfile.area', 'avaliadorProfile.subarea'])
        );
    }

    /** Troca de senha do próprio usuário (qualquer papel). */
    public function alterarSenha(AlterarSenhaRequest $request): JsonResponse
    {
        $this->auth->alterarSenha(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return response()->json(['data' => ['message' => 'Senha alterada com sucesso.']]);
    }

    /**
     * Solicita o e-mail com o link de redefinição de senha. Resposta sempre
     * neutra (não revela se o e-mail existe) para prevenir enumeração de usuários.
     */
    public function esqueciSenha(EsqueciSenhaRequest $request): JsonResponse
    {
        $this->auth->enviarLinkRecuperacao($request->validated('email'));

        return response()->json(['data' => [
            'message' => 'Se este e-mail estiver cadastrado, enviamos um link para redefinir a senha.',
        ]]);
    }

    /** Redefine a senha a partir do token enviado por e-mail. */
    public function redefinirSenha(RedefinirSenhaRequest $request): JsonResponse
    {
        $this->auth->redefinirSenha($request->validated());

        return response()->json(['data' => [
            'message' => 'Senha redefinida com sucesso. Você já pode entrar com a nova senha.',
        ]]);
    }
}
