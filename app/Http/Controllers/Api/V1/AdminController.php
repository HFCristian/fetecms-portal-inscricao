<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
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
use App\Services\EscopoAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /** Projetos agrupados por área do conhecimento, com submetidos/rascunho (inclui rascunhos). */
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
    public function listarAdmins(EscopoAdminService $escopos): JsonResponse
    {
        return response()->json([
            'data' => UserResource::collection($this->admins->listar())->resolve(),
            // Escopos disponíveis e o de cada admin NA EDIÇÃO EM CURSO: a tela
            // monta o seletor daqui, sem precisar da aba de Parametrização.
            'meta' => [
                'escopos' => $escopos->listar()['escopos'],
                'escopo_por_admin' => $escopos->escoposDosAdmins(),
            ],
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

    /**
     * As contas de demonstração: orientadores e avaliadores usados para ensaiar
     * as telas presas a data. Sem busca, lista só quem já está marcado.
     */
    public function contasDemo(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'papel' => ['nullable', Rule::in([Role::Orientador->value, Role::Avaliador->value])],
        ]);

        return response()->json([
            'data' => $this->admins->contasDemo($filtros),
            'meta' => ['papeis' => [
                ['value' => Role::Orientador->value, 'label' => Role::Orientador->label()],
                ['value' => Role::Avaliador->value, 'label' => Role::Avaliador->label()],
            ]],
        ]);
    }

    /** Liga/desliga o modo demo de um orientador ou avaliador. */
    public function demoParticipante(Request $request, User $usuario): JsonResponse
    {
        $demo = $request->validate(['is_demo' => ['required', 'boolean']])['is_demo'];
        $atualizado = $this->admins->definirDemoParticipante($usuario, $demo);

        return response()->json([
            'data' => [
                'id' => $atualizado->id,
                'name' => $atualizado->name,
                'email' => $atualizado->email,
                'role' => $atualizado->role->value,
                'papel' => $atualizado->role->label(),
                'is_active' => (bool) $atualizado->is_active,
                'is_demo' => (bool) $atualizado->is_demo,
            ],
            'meta' => ['message' => $demo ? 'Modo demo liberado.' : 'Modo demo desativado.'],
        ]);
    }

    /** Liga/desliga o modo demo de um administrador (funcionalidades fora de data). */
    public function demoAdmin(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->isAdmin(), 404, 'Administrador não encontrado.');

        $demo = $request->validate(['is_demo' => ['required', 'boolean']])['is_demo'];
        $atualizado = $this->admins->definirDemo($admin, $demo);

        return UserResource::make($atualizado)
            ->additional(['meta' => [
                'message' => $demo ? 'Modo demo liberado.' : 'Modo demo desativado.',
            ]])
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
