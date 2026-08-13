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
use App\Services\RegistroAtividadeService;
use App\Services\SubmissaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjetoSubmissaoController extends Controller
{
    private const RELATIONS = ['instituicao', 'area', 'subarea', 'estado', 'cidade', 'edicao', 'alunos', 'coorientador', 'documentos'];

    public function __construct(
        private readonly ProjetoChecklistService $checklist,
        private readonly SubmissaoService $submissoes,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /** Resumo da inscrição (cadastro7): projeto + integrantes + checklist de pendências. */
    public function resumo(Request $request, Projeto $projeto): JsonResponse
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
            // Desfazer a submissão (cancelar/excluir) enquanto a janela permitir.
            'pode_desfazer' => ! $projeto->status->editavel()
                && $this->submissoes->podeDesfazer($projeto, $request->user()),
            'impedimentos_desfazer' => $this->submissoes->impedimentosPara($projeto, $request->user()),
        ]]);
    }

    /** Submete o projeto. 422 com pendências se o checklist falhar. */
    public function submeter(Request $request, Projeto $projeto): JsonResponse
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

        // Trava a linha (SELECT ... FOR UPDATE no Postgres) e revalida o status
        // dentro da transação: se duas requisições passarem pela verificação
        // acima ao mesmo tempo, só a primeira efetiva a submissão; a outra é
        // no-op idempotente. Evita reprocessar efeitos colaterais no futuro.
        $submetido = DB::transaction(function () use ($projeto) {
            $travado = Projeto::whereKey($projeto->getKey())->lockForUpdate()->first();
            if (! $travado->status->editavel()) {
                return false;
            }

            $travado->update([
                'status' => ProjetoStatus::Submetido,
                'submitted_at' => now(),
            ]);

            return true;
        });

        // Só a requisição que efetivou a submissão grava o registro.
        if ($submetido) {
            $this->registros->submissao($projeto->fresh(), $request->user());
        }

        return response()->json([
            'data' => ProjetoResource::make($projeto->fresh())->resolve(),
            'meta' => ['message' => 'Inscrição submetida com sucesso.'],
        ]);
    }

    /**
     * Cancela a submissão: o projeto volta a rascunho e pode ser editado e
     * submetido de novo. Só enquanto ninguém tiver iniciado a avaliação e o
     * período de avaliação não tiver começado (422 com os motivos, se já passou).
     */
    public function cancelar(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('cancelSubmission', $projeto);

        // Idempotente: rascunho não tem submissão a desfazer.
        if ($projeto->status->editavel()) {
            return response()->json([
                'data' => ProjetoResource::make($projeto)->resolve(),
                'meta' => ['message' => 'Este projeto já está em rascunho.'],
            ]);
        }

        $motivos = $this->submissoes->impedimentosPara($projeto, $request->user());
        if (! empty($motivos)) {
            return response()->json([
                'message' => 'Não é mais possível cancelar esta submissão.',
                'motivos' => $motivos,
                'code' => 'SUBMISSAO_BLOQUEADA',
            ], 422);
        }

        $projeto = $this->submissoes->cancelar($projeto, $request->user());

        return response()->json([
            'data' => ProjetoResource::make($projeto->load(self::RELATIONS))->resolve(),
            'meta' => ['message' => 'Submissão cancelada. O projeto voltou para rascunho.'],
        ]);
    }
}
