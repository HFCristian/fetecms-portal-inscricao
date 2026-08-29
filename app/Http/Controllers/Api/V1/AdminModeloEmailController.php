<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ModeloEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModeloEmailRequest;
use App\Services\ModeloEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comunicação → Modelos de e-mail: o texto dos e-mails automáticos do portal.
 * Sem linha salva, vale o padrão de fábrica; restaurar é apagar a linha.
 */
class AdminModeloEmailController extends Controller
{
    public function __construct(private readonly ModeloEmailService $modelos) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->modelos->listar()]);
    }

    public function show(ModeloEmail $modelo): JsonResponse
    {
        return response()->json(['data' => $this->modelos->detalhe($modelo)]);
    }

    public function update(ModeloEmailRequest $request, ModeloEmail $modelo): JsonResponse
    {
        return response()->json([
            'data' => $this->modelos->atualizar($modelo, $request->validated(), $request->user()),
            'meta' => ['message' => 'Modelo de e-mail salvo.'],
        ]);
    }

    /** Volta ao texto de fábrica. */
    public function restaurar(Request $request, ModeloEmail $modelo): JsonResponse
    {
        return response()->json([
            'data' => $this->modelos->restaurar($modelo),
            'meta' => ['message' => 'Modelo restaurado para o texto padrão.'],
        ]);
    }
}
