<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Avaliador\RegisterAvaliadorRequest;
use App\Http\Resources\UserResource;
use App\Services\AvaliadorService;
use Illuminate\Http\JsonResponse;

class AvaliadorController extends Controller
{
    public function __construct(private readonly AvaliadorService $avaliadores) {}

    /**
     * Cadastro público de avaliador. Exclusão mútua com orientador/coorientador
     * é validada no RegisterAvaliadorRequest.
     */
    public function store(RegisterAvaliadorRequest $request): JsonResponse
    {
        $user = $this->avaliadores->register($request->validated());

        if ($request->hasSession()) {
            auth()->guard('web')->login($user);
            $request->session()->regenerate();
        }

        return UserResource::make($user)
            ->additional(['meta' => ['message' => 'Cadastro de avaliador realizado com sucesso.']])
            ->response()
            ->setStatusCode(201);
    }
}
