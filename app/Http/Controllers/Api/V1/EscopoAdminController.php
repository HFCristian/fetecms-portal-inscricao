<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AbaAdmin;
use App\Http\Controllers\Controller;
use App\Models\EscopoAdmin;
use App\Models\User;
use App\Services\EscopoAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Escopos de admin: o CRUD dos perfis (Parametrização) e a atribuição de um
 * perfil a cada administrador na edição em curso (aba Administradores).
 */
class EscopoAdminController extends Controller
{
    public function __construct(private readonly EscopoAdminService $escopos) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->escopos->listar()]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate($this->regras());

        $this->escopos->criar($dados);

        return response()->json(['data' => $this->escopos->listar()], 201);
    }

    public function update(Request $request, EscopoAdmin $escopo): JsonResponse
    {
        $dados = $request->validate($this->regras($escopo));

        $this->escopos->atualizar($escopo, $dados);

        return response()->json(['data' => $this->escopos->listar()]);
    }

    public function destroy(EscopoAdmin $escopo): JsonResponse
    {
        $this->escopos->excluir($escopo);

        return response()->json(['data' => $this->escopos->listar()]);
    }

    /** Define o escopo de um admin na edição em curso (null = acesso total). */
    public function atribuir(Request $request, User $admin): JsonResponse
    {
        $dados = $request->validate([
            'escopo_id' => ['nullable', 'integer', 'exists:escopos_admin,id'],
        ]);

        $this->escopos->atribuir($admin, $dados['escopo_id'] ?? null);

        return response()->json(['data' => $this->escopos->escoposDosAdmins()]);
    }

    /** @return array<string, mixed> */
    private function regras(?EscopoAdmin $escopo = null): array
    {
        return [
            'nome' => [
                $escopo ? 'sometimes' : 'required', 'string', 'max:80',
                Rule::unique('escopos_admin', 'nome')->ignore($escopo?->id),
            ],
            'abas' => [$escopo ? 'sometimes' : 'required', 'array', 'min:1'],
            'abas.*' => ['string', Rule::in(AbaAdmin::valores())],
        ];
    }
}
