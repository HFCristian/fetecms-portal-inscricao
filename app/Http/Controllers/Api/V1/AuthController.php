<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
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

        return UserResource::make($user->load('orientadorProfile'));
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
        return UserResource::make($request->user()->load('orientadorProfile'));
    }
}
