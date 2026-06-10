<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orientador\RegisterOrientadorRequest;
use App\Http\Resources\UserResource;
use App\Services\OrientadorService;
use Illuminate\Http\JsonResponse;

class OrientadorController extends Controller
{
    public function __construct(private readonly OrientadorService $orientadores) {}

    /**
     * Cadastro público de orientador (wizard 3 etapas). Cria usuário + perfil,
     * já autentica a sessão e devolve o usuário.
     */
    public function store(RegisterOrientadorRequest $request): JsonResponse
    {
        $user = $this->orientadores->register($request->validated());

        // Loga automaticamente após o cadastro (mesma experiência do protótipo).
        // Só no fluxo web (stateful, com sessão); mobile cadastraria e pediria token.
        if ($request->hasSession()) {
            auth()->guard('web')->login($user);
            $request->session()->regenerate();
        }

        return UserResource::make($user)
            ->additional(['meta' => ['message' => 'Cadastro realizado com sucesso.']])
            ->response()
            ->setStatusCode(201);
    }
}
