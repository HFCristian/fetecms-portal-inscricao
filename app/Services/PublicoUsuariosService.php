<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\PublicoMala;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quem está em cada público-alvo. Nasceu na mala direta e hoje serve também aos
 * avisos na tela — os dois falam com os mesmos recortes da base.
 *
 * Todo público considera só contas ATIVAS e NÃO demo: conta de teste não recebe
 * comunicado nem aviso de edital.
 */
class PublicoUsuariosService
{
    /** Base comum: quem pode ser alcançado por qualquer público. */
    public function base(): Builder
    {
        return User::query()->where('is_active', true)->where('is_demo', false);
    }

    /**
     * Condição de um público, aplicável a qualquer builder de usuários — é o
     * que permite unir vários públicos numa consulta só.
     */
    public function condicao(PublicoMala $publico): Closure
    {
        $submetidos = [
            ProjetoStatus::Submetido->value,
            ProjetoStatus::Aprovado->value,
            ProjetoStatus::Rejeitado->value,
        ];

        return match ($publico) {
            PublicoMala::Todos => fn (Builder $q) => $q->whereIn('role', [Role::Orientador, Role::Avaliador]),
            PublicoMala::Orientadores => fn (Builder $q) => $q->where('role', Role::Orientador),
            PublicoMala::Avaliadores => fn (Builder $q) => $q->where('role', Role::Avaliador),
            PublicoMala::OrientadoresRascunho => fn (Builder $q) => $q->where('role', Role::Orientador)
                ->whereHas('projetos', fn ($p) => $p->where('status', ProjetoStatus::Rascunho)),
            PublicoMala::OrientadoresSubmetidos => fn (Builder $q) => $q->where('role', Role::Orientador)
                ->whereHas('projetos', fn ($p) => $p->whereIn('status', $submetidos)),
            PublicoMala::AvaliadoresPendentes => fn (Builder $q) => $q->where('role', Role::Avaliador)
                ->whereHas('avaliacoes', fn ($a) => $a->where('status', StatusAvaliacao::EmAndamento)),
            PublicoMala::AvaliadoresConcluidas => fn (Builder $q) => $q->where('role', Role::Avaliador)
                ->whereHas('avaliacoes', fn ($a) => $a->where('status', StatusAvaliacao::Concluida)),
            PublicoMala::AvaliadoresComissao => fn (Builder $q) => $q->where('role', Role::Avaliador)
                ->whereHas('avaliadorProfile', fn ($p) => $p->where('comissao_especial', true)),
        };
    }

    /**
     * Consulta de um público.
     *
     * @return Builder<User>
     */
    public function query(PublicoMala $publico): Builder
    {
        return $this->base()->where($this->condicao($publico));
    }

    /**
     * União de vários públicos numa consulta só (sem duplicar ninguém). Sem
     * público nenhum, não alcança ninguém.
     *
     * @param  array<int, PublicoMala>  $publicos
     * @return Builder<User>
     */
    public function queryUniao(array $publicos): Builder
    {
        if ($publicos === []) {
            return $this->base()->whereRaw('1 = 0');
        }

        $condicoes = array_map(fn (PublicoMala $p) => $this->condicao($p), $publicos);

        return $this->base()->where(function (Builder $q) use ($condicoes) {
            foreach ($condicoes as $condicao) {
                $q->orWhere($condicao);
            }
        });
    }

    /**
     * Este usuário é alcançado por algum destes públicos?
     *
     * @param  array<int, PublicoMala>  $publicos
     */
    public function alcanca(User $user, array $publicos): bool
    {
        return $publicos !== [] && $this->queryUniao($publicos)->whereKey($user->id)->exists();
    }

    /**
     * Converte os valores crus (string) nos casos do enum, descartando o que
     * não existe.
     *
     * @param  array<int, mixed>  $publicos
     * @return array<int, PublicoMala>
     */
    public function normalizar(array $publicos): array
    {
        return array_values(array_filter(array_map(
            fn ($valor) => $valor instanceof PublicoMala ? $valor : PublicoMala::tryFrom((string) $valor),
            $publicos,
        )));
    }
}
