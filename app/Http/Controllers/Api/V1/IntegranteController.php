<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Categoria;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlunoResource;
use App\Http\Resources\CoorientadorResource;
use App\Models\Projeto;
use Illuminate\Http\JsonResponse;

class IntegranteController extends Controller
{
    /** Visão agregada dos integrantes do projeto (orientador + alunos + coorientador + limites). */
    public function index(Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);

        $projeto->load(['user.orientadorProfile', 'alunos', 'coorientador']);

        return response()->json(['data' => [
            'projeto' => [
                'id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'status' => $projeto->status->value,
                'editavel' => $projeto->status->editavel(),
            ],
            'orientador' => [
                'nome' => $projeto->user->name,
                'email' => $projeto->user->email,
                'telefone' => $projeto->user->orientadorProfile?->telefone,
            ],
            'alunos' => AlunoResource::collection($projeto->alunos)->resolve(),
            'coorientador' => $projeto->coorientador
                ? CoorientadorResource::make($projeto->coorientador)->resolve()
                : null,
            'limites' => [
                'categoria' => $projeto->categoria?->value,
                'categoria_label' => $projeto->categoria?->label(),
                'min_alunos' => Categoria::MIN_ALUNOS,
                'max_alunos' => $projeto->maxAlunos(),
                'alunos_atual' => $projeto->alunos->count(),
            ],
        ]]);
    }
}
