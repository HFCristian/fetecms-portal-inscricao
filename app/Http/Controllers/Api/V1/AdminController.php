<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegisterAdminRequest;
use App\Http\Requests\Admin\StatusAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminAvaliadoresService;
use App\Services\AdminDashboardService;
use App\Services\AdminLocalidadesService;
use App\Services\AdminProjetosService;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminService $admins,
        private readonly AdminDashboardService $dashboard,
        private readonly AdminProjetosService $projetos,
        private readonly AdminLocalidadesService $localidades,
        private readonly AdminAvaliadoresService $avaliadores,
    ) {}

    /** 9 métricas do painel. */
    public function dashboard(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->metricas()]);
    }

    /** Métricas de avaliadores: totais e distribuição por área. */
    public function avaliadores(): JsonResponse
    {
        return response()->json(['data' => $this->avaliadores->metricas()]);
    }

    /** Projetos agrupados por área do conhecimento (inclui rascunhos). */
    public function projetosPorArea(): JsonResponse
    {
        return response()->json(['data' => $this->projetos->porArea()]);
    }

    /** Projetos agregados por estado, cidade e escola (com status). */
    public function projetosPorLocalidade(): JsonResponse
    {
        return response()->json(['data' => $this->localidades->agregado()]);
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

    /** Lista todos os administradores (ativos e inativos). */
    public function listarAdmins(): JsonResponse
    {
        return response()->json([
            'data' => UserResource::collection($this->admins->listar())->resolve(),
        ]);
    }

    /** Edita nome e e-mail de um administrador. */
    public function updateAdmin(UpdateAdminRequest $request, User $admin): JsonResponse
    {
        abort_unless($admin->isAdmin(), 404, 'Administrador não encontrado.');

        $atualizado = $this->admins->atualizar($admin, $request->validated());

        return UserResource::make($atualizado)
            ->additional(['meta' => ['message' => 'Administrador atualizado.']])
            ->response();
    }

    /** Ativa ou desativa um administrador (com travas de segurança no service). */
    public function statusAdmin(StatusAdminRequest $request, User $admin): JsonResponse
    {
        abort_unless($admin->isAdmin(), 404, 'Administrador não encontrado.');

        $atualizado = $this->admins->definirStatus($admin, $request->ativo(), $request->user());
        $mensagem = $atualizado->is_active ? 'Administrador reativado.' : 'Administrador desativado.';

        return UserResource::make($atualizado)
            ->additional(['meta' => ['message' => $mensagem]])
            ->response();
    }
}
