<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Desfazer a submissão (Sprint pós-v1). O orientador pode cancelar (o projeto
 * volta a rascunho) ou excluir a inscrição já submetida — mas só enquanto
 * ninguém tiver começado a avaliá-la e o período de avaliação não tiver
 * começado. Depois disso, apenas o admin.
 */
class SubmissaoService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /** Avaliações que já saíram do "designada" — ou seja, alguém começou a avaliar. */
    private const INICIADAS = [
        StatusAvaliacao::EmAndamento->value,
        StatusAvaliacao::Concluida->value,
    ];

    /**
     * Motivos que impedem o orientador de desfazer a submissão. Lista vazia
     * significa que ele pode. O admin não é barrado por nenhum deles.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function impedimentos(Projeto $projeto): array
    {
        $motivos = [];

        if ($this->avaliacaoIniciada($projeto)) {
            $motivos[] = [
                'code' => 'AVALIACAO_INICIADA',
                'message' => 'Este projeto já tem avaliação iniciada. Fale com a organização pelo suporte.',
            ];
        }

        if ($this->periodoDeAvaliacaoComecou($projeto)) {
            $motivos[] = [
                'code' => 'AVALIACAO_LIBERADA',
                'message' => 'O período de avaliação já começou. Fale com a organização pelo suporte.',
            ];
        }

        return $motivos;
    }

    /**
     * Impedimentos aplicados a ESTE autor: rascunho não tem submissão a desfazer
     * e o admin passa sempre (escape previsto no edital para casos excepcionais).
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function impedimentosPara(Projeto $projeto, User $autor): array
    {
        if ($projeto->status->editavel() || $autor->isAdmin()) {
            return [];
        }

        return $this->impedimentos($projeto);
    }

    /** O projeto pode ser cancelado/excluído por este usuário? */
    public function podeDesfazer(Projeto $projeto, User $autor): bool
    {
        return empty($this->impedimentosPara($projeto, $autor));
    }

    /** Alguma avaliação deste projeto já foi iniciada (ou concluída)? */
    public function avaliacaoIniciada(Projeto $projeto): bool
    {
        // Aproveita a relação já carregada (listagens) para não consultar por projeto.
        if ($projeto->relationLoaded('avaliacoes')) {
            return $projeto->avaliacoes->contains(
                fn ($avaliacao) => in_array($avaliacao->status->value, self::INICIADAS, true),
            );
        }

        return $projeto->avaliacoes()->whereIn('status', self::INICIADAS)->exists();
    }

    /**
     * O período de avaliação da edição do projeto já começou? Sem edição
     * vinculada, cai na edição atual — e, se nem essa existir, não começou.
     */
    public function periodoDeAvaliacaoComecou(Projeto $projeto): bool
    {
        $edicao = $projeto->edicao ?? Edicao::atual();

        return (bool) $edicao?->avaliacaoLiberada();
    }

    /**
     * Cancela a submissão: o projeto volta a rascunho, editável e submissível de
     * novo. As designações pendentes são apagadas — o projeto saiu da fila de
     * avaliação e será redistribuído se voltar a ser submetido.
     */
    public function cancelar(Projeto $projeto, User $autor): Projeto
    {
        $this->garantirQuePodeDesfazer($projeto, $autor);

        DB::transaction(function () use ($projeto) {
            $projeto->avaliacoes()->whereNotIn('status', self::INICIADAS)->delete();
            $projeto->update([
                'status' => ProjetoStatus::Rascunho,
                'submitted_at' => null,
            ]);
        });

        $this->registros->cancelamento($projeto, $autor);

        return $projeto->fresh();
    }

    /**
     * Exclui o projeto (soft delete). Serve tanto para o descarte de rascunho
     * quanto para a exclusão de uma inscrição já submetida — esta última entra na
     * trilha de auditoria, com título e dono copiados, para sobreviver ao delete.
     */
    public function excluir(Projeto $projeto, User $autor): void
    {
        $this->garantirQuePodeDesfazer($projeto, $autor);

        // Registra ANTES de apagar: precisamos dos dados do projeto e do dono.
        // Rascunho puro não vira registro — a trilha é sobre submissões.
        if (! $projeto->status->editavel()) {
            $this->registros->exclusao($projeto, $autor);
        }

        DB::transaction(function () use ($projeto) {
            $projeto->avaliacoes()->whereNotIn('status', self::INICIADAS)->delete();
            $projeto->delete();
        });
    }

    /** Rede de proteção: 422 se a janela já tiver fechado para este autor. */
    private function garantirQuePodeDesfazer(Projeto $projeto, User $autor): void
    {
        $motivos = $this->impedimentosPara($projeto, $autor);

        if (! empty($motivos)) {
            throw ValidationException::withMessages([
                'projeto' => array_column($motivos, 'message'),
            ]);
        }
    }
}
