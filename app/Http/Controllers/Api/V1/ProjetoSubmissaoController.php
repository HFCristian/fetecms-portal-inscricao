<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjetoStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlunoResource;
use App\Http\Resources\CoorientadorResource;
use App\Http\Resources\DocumentoResource;
use App\Http\Resources\ProjetoResource;
use App\Models\Projeto;
use App\Services\AdminRascunhoService;
use App\Services\InscricoesService;
use App\Services\NotificacaoProjetoService;
use App\Services\ProjetoChecklistService;
use App\Services\RegistroAtividadeService;
use App\Services\SubmissaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjetoSubmissaoController extends Controller
{
    private const RELATIONS = ['instituicao', 'area', 'subarea', 'estado', 'cidade', 'edicao', 'alunos', 'coorientador', 'documentos', 'user'];

    public function __construct(
        private readonly ProjetoChecklistService $checklist,
        private readonly SubmissaoService $submissoes,
        private readonly RegistroAtividadeService $registros,
        private readonly InscricoesService $inscricoes,
        private readonly NotificacaoProjetoService $notificacoes,
        private readonly AdminRascunhoService $rascunhos,
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
            // Passado o prazo, nem um projeto com checklist completo pode ser
            // enviado — inclusive quem cancelou o envio para editar.
            'pode_submeter' => $projeto->status->editavel()
                && empty($pendencias)
                && ! $this->inscricoes->bloqueadoPara($request->user()),
            'inscricoes' => $this->inscricoes->config(),
            // Desfazer a submissão (cancelar/excluir) enquanto a janela permitir.
            'pode_desfazer' => ! $projeto->status->editavel()
                && $this->submissoes->podeDesfazer($projeto, $request->user()),
            'impedimentos_desfazer' => $this->submissoes->impedimentosPara($projeto, $request->user()),
            // Admin terminando o rascunho de outra pessoa: a tela precisa pedir
            // a justificativa antes de deixar submeter.
            'exige_justificativa' => $this->rascunhos->ehEdicaoDeAdmin($projeto, $request->user()),
            // Quem é o dono da inscrição — o admin precisa ver de quem é o
            // rascunho que está prestes a submeter.
            'orientador' => $projeto->user?->name,
        ]]);
    }

    /**
     * Submete o projeto. 422 com pendências se o checklist falhar.
     *
     * Quando quem submete é um ADMIN terminando o rascunho de outra pessoa
     * ("Projetos em rascunho"), a justificativa é obrigatória: é o escape do
     * edital, e ela fecha a trilha de Registros → Rascunhos.
     */
    public function submeter(Request $request, Projeto $projeto): JsonResponse
    {
        $this->authorize('submit', $projeto);

        // Lido antes de submeter — depois o status muda e a condição não vale mais.
        $justificativa = null;
        if ($this->rascunhos->ehEdicaoDeAdmin($projeto, $request->user())) {
            $justificativa = $request->validate([
                'justificativa' => ['required', 'string', 'min:5', 'max:500'],
            ])['justificativa'];
        }

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

        // Só a requisição que efetivou a submissão grava o registro e manda o
        // comprovante por e-mail (a outra é no-op idempotente).
        if ($submetido) {
            $fresco = $projeto->fresh();
            $this->registros->submissao($fresco, $request->user());

            if ($justificativa !== null) {
                $this->rascunhos->registrarSubmissao($fresco, $request->user(), $justificativa);
            }

            $this->notificacoes->submetido($fresco->load('user', 'area'));
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
