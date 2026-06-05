<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjetoStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlunoResource;
use App\Http\Resources\CoorientadorResource;
use App\Http\Resources\DocumentoResource;
use App\Http\Resources\ProjetoResource;
use App\Models\Projeto;
use App\Services\ProjetoChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProjetoSubmissaoController extends Controller
{
    private const RELATIONS = ['instituicao', 'area', 'subarea', 'estado', 'cidade', 'edicao', 'alunos', 'coorientador', 'documentos'];

    public function __construct(private readonly ProjetoChecklistService $checklist) {}

    /** Resumo da inscrição (cadastro7): projeto + integrantes + checklist de pendências. */
    public function resumo(Projeto $projeto): JsonResponse
    {
        $this->authorize('view', $projeto);

        $projeto->load(self::RELATIONS);
        $pendencias = $this->checklist->pendencias($projeto);

        return response()->json(['data' => [
            'projeto' => ProjetoResource::make($projeto)->resolve(),
            'integrantes' => [
                'alunos' => AlunoResource::collection($projeto->alunos)->resolve(),
                'coorientador' => $projeto->coorientador
                    ? CoorientadorResource::make($projeto->coorientador)->resolve()
                    : null,
            ],
            'documentos' => DocumentoResource::collection($projeto->documentos)->resolve(),
            'pendencias' => $pendencias,
            'pode_submeter' => $projeto->status->editavel() && empty($pendencias),
        ]]);
    }

    /** Submete o projeto (irreversível). 422 com pendências se o checklist falhar. */
    public function submeter(Projeto $projeto): JsonResponse
    {
        $this->authorize('submit', $projeto);

        // Idempotente: se já submetido, devolve 200 sem reprocessar.
        if (! $projeto->status->editavel()) {
            return response()->json([
                'data' => ProjetoResource::make($projeto)->resolve(),
                'meta' => ['message' => 'Projeto já submetido.'],
            ]);
        }

        $pendencias = $this->checklist->pendencias($projeto);
        if (! empty($pendencias)) {
            return response()->json([
                'message' => 'O projeto não está pronto para submissão.',
                'pendencias' => $pendencias,
                'code' => 'CHECKLIST_INCOMPLETO',
            ], 422);
        }

        DB::transaction(function () use ($projeto) {
            $projeto->update([
                'status' => ProjetoStatus::Submetido,
                'submitted_at' => now(),
            ]);
        });

        return response()->json([
            'data' => ProjetoResource::make($projeto->fresh())->resolve(),
            'meta' => ['message' => 'Inscrição submetida com sucesso.'],
        ]);
    }
}
