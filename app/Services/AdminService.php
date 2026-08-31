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
     * As **contas de demonstração** (Administradores → Contas demo):
     * orientadores e avaliadores que servem para ensaiar as telas presas a data.
     *
     * Sem termo de busca a lista mostra **só quem já está marcado** — é o que o
     * admin quer ver ao abrir a seção ("quais contas de treinamento existem?").
     * Com termo, procura em toda a base de participantes, que é onde ele acha a
     * conta nova para marcar. Administradores ficam de fora: o interruptor
     * deles é a própria linha da lista acima.
     *
     * @param  array<string, mixed>  $filtros  `busca` e `papel`
     * @return list<array<string, mixed>>
     */
    public function contasDemo(array $filtros = [], int $limite = 25): array
    {
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $papel = $filtros['papel'] ?? null;

        return User::query()
            ->whereIn('role', [Role::Orientador->value, Role::Avaliador->value])
            ->when($papel !== null && $papel !== '', fn ($q) => $q->where('role', $papel))
            ->when($busca === '', fn ($q) => $q->where('is_demo', true))
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(fn ($sub) => $sub
                    ->whereRaw('LOWER(name) LIKE ?', [$termo])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$termo]));
            })
            // Quem já é demo primeiro: na busca, é o que muda de estado.
            ->orderByDesc('is_demo')
            ->orderBy('name')
            ->limit($limite)
            ->get(['id', 'name', 'email', 'role', 'is_active', 'is_demo'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'papel' => $u->role->label(),
                'is_active' => (bool) $u->is_active,
                'is_demo' => (bool) $u->is_demo,
            ])
            ->values()
            ->all();
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

    /**
     * O mesmo interruptor, para um **participante** (orientador ou avaliador).
     *
     * É a mesma coluna e o mesmo efeito — só o que ela destrava muda de papel:
     * no orientador, a aba **Ajustes** fora do período; no avaliador, a
     * avaliação antes da data. E, nos dois, `is_demo` mantém a conta fora dos
     * números da feira e dos públicos de comunicação.
     */
    public function definirDemoParticipante(User $user, bool $demo): User
    {
        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'is_demo' => 'O modo demo de um administrador é definido na lista de administradores.',
            ]);
        }

        $user->update(['is_demo' => $demo]);

        return $user->refresh();
    }

    private function totalAtivos(): int
    {
        return User::where('role', Role::Admin->value)->where('is_active', true)->count();
    }
}
