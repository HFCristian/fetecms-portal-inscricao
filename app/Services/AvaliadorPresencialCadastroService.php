<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AvaliadorProfile;
use App\Models\User;
use App\Support\SenhaGerada;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial → **Cadastro de avaliadores** (Sprint 191).
 *
 * Há quem avalie só no dia da feira: o professor convidado que não passou pela
 * avaliação online, o pesquisador que chegou pela organização na véspera. O
 * cadastro público pede confirmação de e-mail, idiomas, cidade e camiseta —
 * coisas que ninguém vai perseguir para uma conta de evento. Aqui a
 * organização cadastra direto: os mesmos dados do cadastro de avaliador, menos
 * camiseta, idiomas, cidade e estado.
 *
 * A conta nasce **com o presencial confirmado** (`presencial` = sim, a intenção
 * que o avaliador normal responde na aba Presencial), mas **ainda não liberada**:
 * como todo avaliador do presencial, ela só avalia depois de liberada em
 * Validação de avaliadores (Sprint 223). E nasce **fora da avaliação online**
 * (`apenas_presencial`): distribuição, reposição da fila, sorteio e designação
 * online não a alcançam ({@see User::scopeAvaliadorDaOnline()}).
 *
 * A senha é **gerada** e devolvida uma vez — o portal guarda só o hash. Perdeu?
 * Gera-se outra aqui, ou a pessoa usa "Esqueci a senha".
 */
class AvaliadorPresencialCadastroService
{
    public function __construct(
        private readonly SubareaService $subareas,
        private readonly LiberacaoPresencialService $liberacao,
    ) {}

    /**
     * Os avaliadores cadastrados só para o presencial, do mais novo para o
     * mais antigo.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(string $busca = ''): array
    {
        $busca = Str::lower(trim($busca));
        $liberados = $this->liberacao->idsLiberados();

        return User::query()
            ->where('role', Role::Avaliador->value)
            ->whereHas('avaliadorProfile', fn ($p) => $p->where('apenas_presencial', true))
            ->when($busca !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$busca.'%'])
                ->orWhereRaw('LOWER(email) LIKE ?', ['%'.$busca.'%'])))
            ->with(['avaliadorProfile.area:id,nome', 'avaliadorProfile.subarea:id,nome', 'avaliadorProfile.cadastradoPor:id,name'])
            ->withCount('avaliacoesPresenciais')
            ->latest('id')
            ->get()
            ->map(fn (User $u) => $this->linha($u, $liberados))
            ->all();
    }

    /**
     * Cria a conta e devolve a senha gerada — a única vez em que ela existe em
     * texto.
     *
     * @param  array<string, mixed>  $dados  já validados
     * @return array{avaliador: array<string, mixed>, senha: string}
     */
    public function cadastrar(array $dados, User $admin): array
    {
        $senha = SenhaGerada::nova();

        $user = DB::transaction(function () use ($dados, $admin, $senha) {
            $subareaId = $dados['subarea_id'] ?? null;

            // Subárea nova digitada: entra no catálogo global, como no cadastro público.
            if (empty($subareaId) && ! empty($dados['subarea_nome'] ?? null)) {
                $subareaId = $this->subareas->firstOrCreateNaArea((int) $dados['area_id'], (string) $dados['subarea_nome'])->id;
            }

            $user = User::create([
                'name' => trim($dados['name']),
                'email' => $dados['email'],
                'password' => $senha,
                'role' => Role::Avaliador,
                'is_active' => true,
            ]);

            $user->avaliadorProfile()->create([
                'cpf' => $dados['cpf'],
                'titulacao' => $dados['titulacao'],
                'area_id' => $dados['area_id'],
                'subarea_id' => $subareaId ?: null,
                'presencial' => true,
                'presencial_em' => now(),
                'apenas_presencial' => true,
                'cadastrado_por' => $admin->id,
            ]);

            return $user;
        });

        return ['avaliador' => $this->linha($this->recarregar($user)), 'senha' => $senha];
    }

    /**
     * Troca a senha por uma nova gerada e encerra as sessões abertas: quem
     * tinha a anterior (anotada no papel errado) não entra mais.
     *
     * @return array{avaliador: array<string, mixed>, senha: string}
     */
    public function novaSenha(User $avaliador): array
    {
        if (! $avaliador->ehAvaliadorSoPresencial()) {
            throw ValidationException::withMessages([
                'avaliador' => 'Só as contas cadastradas aqui, para o evento presencial, têm a senha gerada pela organização.',
            ]);
        }

        $senha = SenhaGerada::nova();
        $avaliador->forceFill(['password' => $senha])->save();
        $avaliador->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $avaliador->id)->delete();
        }

        return ['avaliador' => $this->linha($this->recarregar($avaliador)), 'senha' => $senha];
    }

    private function recarregar(User $user): User
    {
        return $user->refresh()
            ->load(['avaliadorProfile.area:id,nome', 'avaliadorProfile.subarea:id,nome', 'avaliadorProfile.cadastradoPor:id,name'])
            ->loadCount('avaliacoesPresenciais');
    }

    /**
     * @param  list<int>|null  $liberados  os ids liberados, quando a lista inteira já os buscou
     * @return array<string, mixed>
     */
    private function linha(User $u, ?array $liberados = null): array
    {
        /** @var AvaliadorProfile|null $perfil */
        $perfil = $u->avaliadorProfile;

        return [
            'id' => $u->id,
            'nome' => $u->name,
            'email' => $u->email,
            'titulacao' => $perfil?->titulacao,
            'area' => $perfil?->area?->nome,
            'subarea' => $perfil?->subarea?->nome,
            'ativo' => (bool) $u->is_active,
            'comissao_especial' => (bool) $perfil?->comissao_especial,
            'avaliacoes_presenciais' => (int) ($u->avaliacoes_presenciais_count ?? 0),
            // Cadastrar não basta: a organização libera em Validação de avaliadores (Sprint 223).
            'liberado' => $liberados === null ? $this->liberacao->liberado($u) : in_array($u->id, $liberados, true),
            'cadastrado_por' => $perfil?->cadastradoPor?->name,
            'cadastrado_em' => $u->created_at?->toIso8601String(),
        ];
    }
}
