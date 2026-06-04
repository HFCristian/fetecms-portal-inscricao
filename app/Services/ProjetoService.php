<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProjetoService
{
    /** Lista os projetos do orientador, com filtro opcional por status. */
    public function listarDoOrientador(User $user, ?string $status = null): Collection
    {
        return $user->projetos()
            ->with(['instituicao', 'area'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')
            ->get();
    }

    /** Cria um projeto em rascunho para o orientador autenticado. */
    public function criarRascunho(User $user, array $data): Projeto
    {
        $data['status'] = ProjetoStatus::Rascunho;
        $data['edicao_id'] ??= Edicao::where('inscricoes_abertas', true)->value('id');

        // user_id vem da relação (do usuário autenticado), nunca do request.
        return $user->projetos()->create($data);
    }

    public function atualizar(Projeto $projeto, array $data): Projeto
    {
        $projeto->update($data);

        return $projeto->fresh(['instituicao', 'area', 'subarea', 'estado', 'cidade', 'edicao']);
    }
}
