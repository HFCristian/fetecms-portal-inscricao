<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Models\Edicao;
use App\Models\PalavraChave;
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
        $projeto = $user->projetos()->create($data);
        $this->sincronizarPalavrasChave($data['palavras_chave'] ?? null);

        return $projeto;
    }

    public function atualizar(Projeto $projeto, array $data): Projeto
    {
        $projeto->update($data);
        $this->sincronizarPalavrasChave($data['palavras_chave'] ?? null);

        return $projeto->fresh(['instituicao', 'area', 'subarea', 'estado', 'cidade', 'edicao']);
    }

    /** Garante que cada palavra-chave do projeto exista na lista global compartilhada. */
    private function sincronizarPalavrasChave(?array $palavras): void
    {
        foreach ($palavras ?? [] as $texto) {
            $texto = trim((string) $texto);
            if ($texto !== '') {
                PalavraChave::firstOrCreate(['texto' => $texto]);
            }
        }
    }
}
