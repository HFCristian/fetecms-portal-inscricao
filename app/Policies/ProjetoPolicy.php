<?php

namespace App\Policies;

use App\Models\Projeto;
use App\Models\User;

class ProjetoPolicy
{
    /** Admin enxerga tudo (dashboard/gestão — Sprint 5). */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isOrientador();
    }

    public function view(User $user, Projeto $projeto): bool
    {
        return $projeto->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isOrientador();
    }

    /** Só o dono edita, e apenas enquanto rascunho (sem volta após submeter). */
    public function update(User $user, Projeto $projeto): bool
    {
        return $projeto->user_id === $user->id && $projeto->status->editavel();
    }

    public function delete(User $user, Projeto $projeto): bool
    {
        return $projeto->user_id === $user->id && $projeto->status->editavel();
    }

    /**
     * Só o dono submete. O bloqueio de "já submetido" é tratado no controller
     * (idempotente: resubmeter o próprio projeto já submetido devolve 200).
     */
    public function submit(User $user, Projeto $projeto): bool
    {
        return $projeto->user_id === $user->id;
    }
}
