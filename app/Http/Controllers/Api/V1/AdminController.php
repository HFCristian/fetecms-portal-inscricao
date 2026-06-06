<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegisterAdminRequest;
use App\Http\Resources\UserResource;
use App\Services\AdminDashboardService;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminService $admins,
        private readonly AdminDashboardService $dashboard,
    ) {}

    /** 9 métricas do painel. */
    public function dashboard(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->metricas()]);
    }

    /** Cria outro administrador (rota protegida por role:admin). */
    public function store(RegisterAdminRequest $request): JsonResponse
    {
        $admin = $this->admins->register($request->validated());

        return UserResource::make($admin)
            ->additional(['meta' => ['message' => 'Administrador criado com sucesso.']])
            ->response()
            ->setStatusCode(201);
    }
}
