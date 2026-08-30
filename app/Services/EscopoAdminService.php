<?php

namespace App\Services;

use App\Enums\AbaAdmin;
use App\Enums\Role;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escopos de admin (Parametrização → Escopos de admin).
 *
 * Um escopo é um nome e um conjunto de abas do menu. Cada admin recebe um
 * escopo **por edição**, então a mesma pessoa pode cuidar da comunicação num
 * ano e do credenciamento no outro.
 *
 * Duas travas para ninguém se trancar do lado de fora:
 *
 * - **admin sem escopo na edição em curso tem acesso total** — é o
 *   comportamento anterior aos escopos e o que segura a criação de uma edição
 *   nova sem atribuições;
 * - **a edição precisa ter ao menos um admin com a aba "Administradores"**;
 *   sem isso ninguém poderia distribuir escopos de novo.
 */
class EscopoAdminService
{
    /**
     * Os escopos cadastrados, com quantos admins usam cada um (em qualquer
     * edição) e se pode ser excluído.
     *
     * @return array<string, mixed>
     */
    public function listar(): array
    {
        $escopos = EscopoAdmin::query()->withCount('admins')->orderBy('nome')->get();

        return [
            'escopos' => $escopos->map(fn (EscopoAdmin $e) => [
                'id' => $e->id,
                'nome' => $e->nome,
                'abas' => $e->abas ?? [],
                'admins' => $e->admins_count,
                'pode_excluir' => $e->admins_count === 0,
            ])->values()->all(),
            'abas' => AbaAdmin::opcoes(),
            'edicao' => $this->edicaoAtualResumo(),
        ];
    }

    /** @param array<string, mixed> $dados */
    public function criar(array $dados): EscopoAdmin
    {
        return EscopoAdmin::create([
            'nome' => trim($dados['nome']),
            'abas' => $this->abasValidas($dados['abas'] ?? []),
        ]);
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(EscopoAdmin $escopo, array $dados): EscopoAdmin
    {
        $abas = array_key_exists('abas', $dados)
            ? $this->abasValidas($dados['abas'])
            : $escopo->abas;

        // A trava anti-lockout roda DENTRO da transação: se tirar a aba
        // "Administradores" do último que a tinha, a alteração é desfeita.
        DB::transaction(function () use ($escopo, $dados, $abas) {
            $escopo->update([
                'nome' => isset($dados['nome']) ? trim($dados['nome']) : $escopo->nome,
                'abas' => $abas,
            ]);

            $this->garantirAlguemComAdministradores();
        });

        return $escopo->fresh();
    }

    public function excluir(EscopoAdmin $escopo): void
    {
        if ($escopo->admins()->exists()) {
            throw ValidationException::withMessages([
                'escopo' => 'Este escopo está atribuído a algum administrador. Troque o escopo dele antes.',
            ]);
        }

        $escopo->delete();
    }

    /**
     * Atribui (ou remove, com `null`) o escopo de um admin **na edição
     * informada** — sem edição, na que está em curso. Sem escopo, ele volta ao
     * acesso total.
     */
    public function atribuir(User $admin, ?int $escopoId, ?Edicao $edicao = null): void
    {
        if ($admin->role !== Role::Admin) {
            throw ValidationException::withMessages([
                'escopo_id' => 'Escopos valem apenas para administradores.',
            ]);
        }

        $edicao ??= Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'escopo_id' => 'Nenhuma edição em curso para atribuir o escopo.',
            ]);
        }

        DB::transaction(function () use ($admin, $escopoId, $edicao) {
            // Um escopo por admin em cada edição: troca é sempre "tira e põe".
            $admin->escopos()->wherePivot('edicao_id', $edicao->id)->detach();

            if ($escopoId !== null) {
                $admin->escopos()->attach($escopoId, ['edicao_id' => $edicao->id]);
            }

            $this->garantirAlguemComAdministradores($edicao);
        });
    }

    /**
     * Os admins e o escopo de cada um na edição em curso — alimenta a tela de
     * Administradores.
     *
     * @return array<int, array{escopo_id: int|null, escopo: string|null}>
     */
    public function escoposDosAdmins(?Edicao $edicao = null): array
    {
        $edicao ??= Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        return User::where('role', Role::Admin->value)
            ->with(['escopos' => fn ($q) => $q->wherePivot('edicao_id', $edicao->id)])
            ->get()
            ->mapWithKeys(function (User $admin) {
                $escopo = $admin->escopos->first();

                return [$admin->id => [
                    'escopo_id' => $escopo?->id,
                    'escopo' => $escopo?->nome,
                ]];
            })
            ->all();
    }

    /**
     * Normaliza a lista de abas: só valores conhecidos, sem repetição e sem
     * escopo vazio (um escopo sem aba nenhuma trancaria o admin em tudo).
     *
     * @return list<string>
     */
    private function abasValidas(mixed $abas): array
    {
        $validas = array_values(array_unique(array_intersect(
            AbaAdmin::valores(),
            is_array($abas) ? $abas : [],
        )));

        if ($validas === []) {
            throw ValidationException::withMessages([
                'abas' => 'Escolha ao menos uma aba para o escopo.',
            ]);
        }

        return $validas;
    }

    /**
     * Trava anti-lockout: a edição precisa manter ao menos um admin ATIVO capaz
     * de abrir "Administradores" — é de lá que se conserta qualquer escopo.
     * Admin sem escopo atribuído conta, porque ele tem acesso total.
     */
    private function garantirAlguemComAdministradores(?Edicao $edicao = null): void
    {
        $edicao ??= Edicao::atual();

        if ($edicao === null) {
            return;
        }

        $sobrou = User::where('role', Role::Admin->value)
            ->where('is_active', true)
            ->get()
            ->contains(function (User $admin) use ($edicao) {
                $escopo = $admin->escopos()->wherePivot('edicao_id', $edicao->id)->first();

                return $escopo === null || $escopo->permite(AbaAdmin::Administradores);
            });

        if (! $sobrou) {
            throw ValidationException::withMessages([
                'escopo_id' => 'Pelo menos um administrador ativo precisa manter a aba "Administradores" nesta edição.',
            ]);
        }
    }

    /** @return array{id: int, nome: string}|null */
    private function edicaoAtualResumo(): ?array
    {
        $edicao = Edicao::atual();

        return $edicao === null ? null : ['id' => $edicao->id, 'nome' => $edicao->nome];
    }
}
