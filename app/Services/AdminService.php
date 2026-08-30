<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class AdminService
{
    /** Cria um novo administrador (somente outro admin pode acionar — ver rota role:admin). */
    public function register(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => Role::Admin,
            'is_active' => true,
        ]);
    }

    /** Lista todos os administradores (ativos e inativos), em ordem alfabética. */
    public function listar(): Collection
    {
        return User::where('role', Role::Admin->value)
            ->orderBy('name')
            ->get();
    }

    /** Atualiza nome e e-mail de um administrador. */
    public function atualizar(User $admin, array $data): User
    {
        $admin->update([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        return $admin->refresh();
    }

    /**
     * Ativa ou desativa um administrador. Travas de segurança na desativação:
     * ninguém desativa a própria conta, e não se pode desativar o último admin ativo.
     */
    public function definirStatus(User $admin, bool $ativo, User $autor): User
    {
        if (! $ativo) {
            if ($admin->id === $autor->id) {
                throw ValidationException::withMessages([
                    'is_active' => 'Você não pode desativar a sua própria conta.',
                ]);
            }

            if ($admin->is_active && $this->totalAtivos() <= 1) {
                throw ValidationException::withMessages([
                    'is_active' => 'Não é possível desativar o último administrador ativo.',
                ]);
            }
        }

        $admin->update(['is_active' => $ativo]);

        return $admin->refresh();
    }

    /**
     * Liga/desliga o **modo demo** de um administrador.
     *
     * Com ele, as telas que dependem de data passam a oferecer o "modo de
     * teste" — hoje o credenciamento (que ignora a janela do evento) e a aba de
     * ajustes do orientador. É uma permissão de treinamento: quem tem o modo
     * demo ligado vê o interruptor, e o interruptor é que ignora as datas, então
     * ninguém credencia fora de hora sem querer.
     *
     * A conta demo continua fora dos públicos de comunicação
     * (`PublicoUsuariosService`) e da distribuição de avaliações.
     */
    public function definirDemo(User $admin, bool $demo): User
    {
        $admin->update(['is_demo' => $demo]);

        return $admin->refresh();
    }

    private function totalAtivos(): int
    {
        return User::where('role', Role::Admin->value)->where('is_active', true)->count();
    }
}
