<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CadastrarAvaliadorPresencialRequest;
use App\Models\User;
use App\Services\AvaliadorPresencialCadastroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação presencial → **Cadastro de avaliadores** (Sprint 191): a
 * organização cria a conta de quem avalia só no dia da feira.
 */
class AvaliadorPresencialCadastroController extends Controller
{
    public function __construct(private readonly AvaliadorPresencialCadastroService $cadastro) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate(['q' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $this->cadastro->listar((string) ($filtros['q'] ?? ''))]);
    }

    /** A senha vem na resposta uma única vez: o portal guarda só o hash. */
    public function store(CadastrarAvaliadorPresencialRequest $request): JsonResponse
    {
        $resultado = $this->cadastro->cadastrar($request->validated(), $request->user());

        return response()->json([
            'data' => $resultado['avaliador'],
            'meta' => [
                'senha' => $resultado['senha'],
                'message' => 'Avaliador cadastrado. Anote a senha: ela não aparece de novo.',
            ],
        ], 201);
    }

    public function novaSenha(User $avaliador): JsonResponse
    {
        $resultado = $this->cadastro->novaSenha($avaliador);

        return response()->json([
            'data' => $resultado['avaliador'],
            'meta' => [
                'senha' => $resultado['senha'],
                'message' => 'Senha nova gerada. A anterior deixou de valer.',
            ],
        ]);
    }
}
