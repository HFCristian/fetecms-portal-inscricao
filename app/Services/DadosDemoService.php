<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AlmoxarifadoGuarda;
use App\Models\Avaliacao;
use App\Models\Credenciamento;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoAjuste;
use App\Models\RegistroAtividade;
use App\Models\Scopes\AvaliacaoAtivaScope;
use App\Models\Scopes\EdicaoScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parametrização → **Dados de demonstração**: um lugar só para ver — e apagar —
 * tudo que existe no portal para ensaio.
 *
 * O portal ganhou várias contas e rotinas de treinamento (`demo:ajustes`,
 * `demo:credenciamento`, o interruptor de conta demo), e cada uma some de um
 * jeito diferente: a conta sai dos públicos de comunicação, o projeto sai do
 * painel e do ranking (`Projeto::semDemo()`), a lista final demo corre numa
 * trilha paralela (`listas_finais.demo`), a guarda de ensaio fica marcada
 * (`almoxarifado_guardas.demo`). Espalhado assim, ninguém consegue responder
 * "o que ainda é de mentira no sistema?" — que é exatamente a pergunta de quem
 * vai abrir a feira de verdade.
 *
 * O que conta como demonstração, e por quê:
 *
 * - **conta** com `is_demo` — a marca é a origem de tudo o mais;
 * - **projeto** cujo dono é demo, em **qualquer edição** (o escopo de edição é
 *   atravessado de propósito: dado de ensaio esquecido na edição passada é
 *   justamente o que se quer achar);
 * - **avaliação** de projeto demo **ou** feita por avaliador demo — inclusive as
 *   devolvidas, que o `AvaliacaoAtivaScope` esconde das telas normais;
 * - **lista final** marcada `demo` e **guarda** marcada `demo`;
 * - **credenciamento** e **ajuste** de projeto demo;
 * - **registro** de auditoria cujo projeto ou autor é demo.
 *
 * A exclusão é a parte perigosa, então ela tem uma trava só: **nada que não
 * esteja marcado como demonstração é apagado aqui**. Cada método confere a
 * marca antes de tocar na linha, e quem quiser apagar dado real usa a tela
 * própria daquele dado.
 */
class DadosDemoService
{
    /** Teto de linhas por grupo — a tela mostra as mais recentes e diz o total. */
    private const LIMITE = 200;

    /**
     * Tudo que é de demonstração hoje, agrupado por tipo.
     *
     * @return array<string, mixed>
     */
    public function panorama(): array
    {
        $contas = $this->contas();
        $projetos = $this->projetos();
        $idsProjetos = $projetos->pluck('id')->all();
        $idsContas = $contas->pluck('id')->all();

        return [
            'contas' => $this->grupo($contas, fn (User $u) => $this->linhaConta($u)),
            'projetos' => $this->grupo($projetos, fn (Projeto $p) => $this->linhaProjeto($p)),
            'avaliacoes' => $this->grupo(
                $this->avaliacoes($idsProjetos, $idsContas),
                fn (Avaliacao $a) => $this->linhaAvaliacao($a, $idsProjetos),
            ),
            'listas' => $this->grupo($this->listas(), fn (ListaFinal $l) => $this->linhaLista($l)),
            'credenciamentos' => $this->grupo(
                $this->credenciamentos($idsProjetos),
                fn (Credenciamento $c) => $this->linhaCredenciamento($c),
            ),
            'guardas' => $this->grupo($this->guardas(), fn (AlmoxarifadoGuarda $g) => $this->linhaGuarda($g)),
            'ajustes' => $this->grupo($this->ajustes($idsProjetos), fn (ProjetoAjuste $a) => $this->linhaAjuste($a)),
            'registros' => $this->grupo(
                $this->registros($idsProjetos, $idsContas),
                fn (RegistroAtividade $r) => $this->linhaRegistro($r),
            ),
        ];
    }

    /**
     * Liga/desliga a marca de demonstração de uma conta.
     *
     * Aqui vale para **qualquer papel**, inclusive admin — esta é a tela que
     * enxerga a demonstração inteira, e mandar a pessoa a outra aba só para
     * desligar um interruptor que ela está vendo seria perverso.
     *
     * Desmarcar tem consequência: os projetos daquela conta voltam a contar no
     * painel, no ranking e na lista final. É decisão do admin, e a tela avisa.
     *
     * @return array<string, mixed>
     */
    public function definirDemo(User $usuario, bool $demo): array
    {
        $usuario->update(['is_demo' => $demo]);
        $usuario->refresh();
        $usuario->loadCount([
            'projetos as projetos_count' => fn (Builder $q) => $q->withoutGlobalScope(EdicaoScope::class),
        ]);

        return $this->linhaConta($usuario);
    }

    /**
     * Apaga a conta de demonstração e tudo que nasceu dela.
     *
     * Os projetos vão embora de vez (`forceDelete`, não a lixeira): dado de
     * ensaio soft-deleted continuaria aparecendo em consulta com `withTrashed`,
     * que é o oposto do que esta tela promete. As avaliações, credenciamentos,
     * guardas, ajustes e itens de lista descem junto pelas chaves estrangeiras.
     */
    public function excluirConta(User $usuario): void
    {
        $this->exigirDemo((bool) $usuario->is_demo, 'Esta conta não está marcada como demonstração.');

        DB::transaction(function () use ($usuario) {
            $projetos = Projeto::withoutGlobalScope(EdicaoScope::class)
                ->withTrashed()
                ->where('user_id', $usuario->id)
                ->get();

            foreach ($projetos as $projeto) {
                $this->apagarProjeto($projeto);
            }

            // O registro guarda autor e projeto desnormalizados justamente para
            // sobreviver ao delete — aqui é o contrário: o ensaio some inteiro.
            RegistroAtividade::where('user_id', $usuario->id)->delete();

            $usuario->delete();
        });
    }

    /** Apaga um projeto de demonstração (o dono continua). */
    public function excluirProjeto(int $id): void
    {
        $projeto = $this->acharProjeto($id);

        $this->exigirDemo(
            (bool) $projeto->user?->is_demo,
            'Este projeto não é de uma conta de demonstração.',
        );

        DB::transaction(fn () => $this->apagarProjeto($projeto));
    }

    /** Apaga uma lista final de demonstração (os projetos dela continuam). */
    public function excluirLista(ListaFinal $lista): void
    {
        $this->exigirDemo((bool) $lista->demo, 'Esta lista final não é de demonstração.');

        $lista->delete();
    }

    /** Apaga uma guarda de almoxarifado de demonstração. */
    public function excluirGuarda(AlmoxarifadoGuarda $guarda): void
    {
        $this->exigirDemo((bool) $guarda->demo, 'Esta guarda não é de demonstração.');

        $guarda->forceDelete();
    }

    /**
     * Apaga **tudo** de uma vez: contas demo (com seus projetos), listas e
     * guardas marcadas. Devolve quanto saiu de cada grupo.
     *
     * @return array<string, int>
     */
    public function limparTudo(): array
    {
        return DB::transaction(function () {
            $contas = $this->contas();
            $projetos = $this->projetos();
            $listas = $this->listas();
            $guardas = $this->guardas();

            $totais = [
                'contas' => $contas->count(),
                'projetos' => $projetos->count(),
                'listas' => $listas->count(),
                'guardas' => $guardas->count(),
            ];

            foreach ($guardas as $guarda) {
                $guarda->forceDelete();
            }

            foreach ($listas as $lista) {
                $lista->delete();
            }

            foreach ($projetos as $projeto) {
                $this->apagarProjeto($projeto);
            }

            foreach ($contas as $conta) {
                RegistroAtividade::where('user_id', $conta->id)->delete();
                $conta->delete();
            }

            return $totais;
        });
    }

    /**
     * O projeto some de vez, com a trilha que fala dele.
     *
     * `registros_atividade.projeto_id` é `nullOnDelete`: sem esta limpeza o
     * registro ficaria órfão na tela de Registros, dizendo que um projeto que
     * não existe mais foi submetido.
     */
    private function apagarProjeto(Projeto $projeto): void
    {
        RegistroAtividade::where('projeto_id', $projeto->id)->delete();

        $projeto->forceDelete();
    }

    /** @throws ValidationException */
    private function exigirDemo(bool $ehDemo, string $mensagem): void
    {
        if (! $ehDemo) {
            throw ValidationException::withMessages(['demo' => $mensagem]);
        }
    }

    /** @return Collection<int, User> */
    private function contas(): Collection
    {
        return User::query()
            ->where('is_demo', true)
            ->withCount([
                'projetos as projetos_count' => fn (Builder $q) => $q->withoutGlobalScope(EdicaoScope::class),
            ])
            ->orderBy('role')
            ->orderBy('name')
            ->get();
    }

    /**
     * Os projetos de contas demo, **de todas as edições**.
     *
     * @return Collection<int, Projeto>
     */
    private function projetos(): Collection
    {
        return Projeto::withoutGlobalScope(EdicaoScope::class)
            ->withTrashed()
            ->whereHas('user', fn (Builder $q) => $q->where('is_demo', true))
            ->with(['user:id,name,email', 'area:id,nome', 'edicao:id,nome'])
            ->withCount('avaliacoes')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Avaliações de projeto demo ou de avaliador demo — as devolvidas
     * incluídas, que o `AvaliacaoAtivaScope` tira das telas de trabalho e que
     * aqui interessam justamente por estarem escondidas.
     *
     * @param  list<int>  $projetos
     * @param  list<int>  $contas
     * @return Collection<int, Avaliacao>
     */
    private function avaliacoes(array $projetos, array $contas): Collection
    {
        if ($projetos === [] && $contas === []) {
            return collect();
        }

        return Avaliacao::withoutGlobalScope(AvaliacaoAtivaScope::class)
            ->where(fn (Builder $q) => $q
                ->whereIn('projeto_id', $projetos)
                ->orWhereIn('avaliador_id', $contas))
            ->with(['avaliador:id,name,email'])
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, ListaFinal> */
    private function listas(): Collection
    {
        return ListaFinal::query()
            ->where('demo', true)
            ->withCount('projetos')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  list<int>  $projetos
     * @return Collection<int, Credenciamento>
     */
    private function credenciamentos(array $projetos): Collection
    {
        if ($projetos === []) {
            return collect();
        }

        return Credenciamento::query()
            ->whereIn('projeto_id', $projetos)
            ->with(['autor:id,name'])
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, AlmoxarifadoGuarda> */
    private function guardas(): Collection
    {
        return AlmoxarifadoGuarda::query()
            ->where('demo', true)
            ->withCount('itens')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  list<int>  $projetos
     * @return Collection<int, ProjetoAjuste>
     */
    private function ajustes(array $projetos): Collection
    {
        if ($projetos === []) {
            return collect();
        }

        return ProjetoAjuste::query()
            ->whereIn('projeto_id', $projetos)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  list<int>  $projetos
     * @param  list<int>  $contas
     * @return Collection<int, RegistroAtividade>
     */
    private function registros(array $projetos, array $contas): Collection
    {
        if ($projetos === [] && $contas === []) {
            return collect();
        }

        return RegistroAtividade::query()
            ->where(fn (Builder $q) => $q
                ->whereIn('projeto_id', $projetos)
                ->orWhereIn('user_id', $contas))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Empacota um grupo: o total de verdade e, no máximo, as `LIMITE` linhas
     * mais recentes — o painel do ensaio não pode ficar pesado por causa de uma
     * carga de teste de mil projetos.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $itens
     * @param  callable(TModel): array<string, mixed>  $linha
     * @return array<string, mixed>
     */
    private function grupo(Collection $itens, callable $linha): array
    {
        return [
            'total' => $itens->count(),
            'itens' => $itens->take(self::LIMITE)->map($linha)->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function linhaConta(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role->value,
            'papel' => $u->role->label(),
            'is_active' => (bool) $u->is_active,
            'is_demo' => (bool) $u->is_demo,
            'projetos' => (int) ($u->projetos_count ?? 0),
            'criada_em' => $u->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function linhaProjeto(Projeto $p): array
    {
        return [
            'id' => $p->id,
            'titulo' => $p->titulo,
            'status' => $p->status?->value,
            'status_label' => $p->status?->label(),
            'categoria_label' => $p->categoria?->label(),
            'area' => $p->area?->nome,
            'edicao' => $p->edicao?->nome,
            'orientador' => $p->user?->name,
            'orientador_email' => $p->user?->email,
            'avaliacoes' => (int) ($p->avaliacoes_count ?? 0),
            'excluido' => $p->trashed(),
            'criado_em' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>  $projetosDemo
     * @return array<string, mixed>
     */
    private function linhaAvaliacao(Avaliacao $a, array $projetosDemo): array
    {
        return [
            'id' => $a->id,
            'projeto_id' => $a->projeto_id,
            'avaliador' => $a->avaliador?->name,
            'status' => $a->status?->value,
            'status_label' => $a->status?->label(),
            'nota' => $a->nota,
            'devolvida' => $a->devolvida_em !== null,
            // Quem puxou esta avaliação para o ensaio: o projeto ou o avaliador.
            'motivo' => in_array($a->projeto_id, $projetosDemo, true)
                ? 'Projeto de demonstração'
                : 'Avaliador de demonstração',
        ];
    }

    /** @return array<string, mixed> */
    private function linhaLista(ListaFinal $l): array
    {
        return [
            'id' => $l->id,
            'nome' => $l->nome,
            'versao' => (int) $l->versao,
            'vigente' => (bool) $l->vigente,
            'projetos' => (int) ($l->projetos_count ?? 0),
            'gerada_em' => $l->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function linhaCredenciamento(Credenciamento $c): array
    {
        return [
            'id' => $c->id,
            'projeto_id' => $c->projeto_id,
            'autor' => $c->autor?->name,
            'finalizado_em' => $c->finalizado_em?->toIso8601String(),
            'rascunho' => $c->finalizado_em === null,
        ];
    }

    /** @return array<string, mixed> */
    private function linhaGuarda(AlmoxarifadoGuarda $g): array
    {
        return [
            'id' => $g->id,
            'projeto_id' => $g->projeto_id,
            'responsavel' => $g->responsavel_nome,
            'itens' => (int) ($g->itens_count ?? 0),
            'registrado_em' => $g->registrado_em?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function linhaAjuste(ProjetoAjuste $a): array
    {
        return [
            'id' => $a->id,
            'projeto_id' => $a->projeto_id,
            'tipo' => $a->tipo,
            'aceito' => (bool) $a->aceito,
            'decidido_em' => $a->decidido_em?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function linhaRegistro(RegistroAtividade $r): array
    {
        return [
            'id' => $r->id,
            'tipo' => $r->tipo?->value,
            'tipo_label' => $r->tipo?->label(),
            'autor' => $r->autor_nome,
            'projeto' => $r->projeto_titulo,
            'criado_em' => $r->created_at?->toIso8601String(),
        ];
    }

    /** O projeto demo pedido, atravessando o escopo de edição e a lixeira. */
    private function acharProjeto(int $id): Projeto
    {
        return Projeto::withoutGlobalScope(EdicaoScope::class)
            ->withTrashed()
            ->with('user:id,is_demo')
            ->findOrFail($id);
    }

    /**
     * Papéis, para a tela agrupar as contas sem repetir os rótulos.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function papeis(): array
    {
        return array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::cases());
    }
}
