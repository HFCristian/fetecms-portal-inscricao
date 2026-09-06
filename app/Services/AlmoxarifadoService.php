<?php

namespace App\Services;

use App\Enums\TipoPessoaCredenciamento;
use App\Models\AlmoxarifadoGuarda;
use App\Models\AlmoxarifadoItem;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Almoxarifado: a guarda de volumes dos finalistas durante a feira.
 *
 * A equipe chega com maquete, ferramenta e mochila e precisa de onde deixar
 * isso enquanto circula pelo evento. O balcão registra **de quem** é o material
 * e, depois, **quem** o levou de volta — quase nunca a mesma pessoa, e quase
 * nunca tudo de uma vez. Por isso a unidade é o **item**: a retirada parcial
 * existe porque o dono de um volume aparece antes dos colegas.
 *
 * **Quem aparece**: os finalistas da lista vigente da edição, como no
 * credenciamento — quem não subiu ao evento não tem o que guardar. No **modo de
 * teste** (só para conta demo) a lista é a **demo** e os registros nascem
 * marcados `demo`, então ensaiar o balcão não devolve o material de ninguém.
 *
 * **Quando**: só dentro da janela do evento (`edicoes.evento_de`/`evento_ate`),
 * que fica fechada enquanto não for definida — guardar volume é ato presencial.
 * Fora dela a aba abre em leitura, para consultar o que ficou.
 */
class AlmoxarifadoService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /** O balcão está aberto para esta pessoa? O demo em modo teste ignora as datas. */
    public function podeOperar(?User $user, bool $teste = false): bool
    {
        if ($this->emTeste($user, $teste)) {
            return true;
        }

        return (bool) Edicao::atual()?->eventoEmAndamento();
    }

    /**
     * O modo de teste vale mesmo para esta pessoa? O parâmetro sozinho não
     * basta: quem não é demo pode mandar `teste=1` na URL e nada muda.
     */
    public function emTeste(?User $user, bool $teste): bool
    {
        return $teste && (bool) $user?->is_demo;
    }

    /**
     * Estado da janela + o que a tela precisa para se explicar.
     *
     * @return array<string, mixed>
     */
    public function config(?User $user = null, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $emTeste = $this->emTeste($user, $teste);
        $vigente = ListaFinal::vigente($edicao, $emTeste);

        return [
            'aberto' => $this->podeOperar($user, $teste),
            'iniciado' => (bool) $edicao?->eventoIniciado(),
            'encerrado' => (bool) $edicao?->eventoEncerrado(),
            'inicio_label' => $edicao?->evento_de?->format('d/m/Y H:i'),
            'fim_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            'pode_testar' => (bool) $user?->is_demo,
            'modo_teste' => $emTeste,
            'lista' => $vigente === null ? null : [
                'id' => $vigente->id,
                'nome' => $vigente->nome,
                'versao' => $vigente->versao,
                'demo' => $vigente->demo,
            ],
        ];
    }

    /** Por que o balcão está fechado agora. */
    public function motivoFechado(): string
    {
        $edicao = Edicao::atual();

        if ($edicao?->eventoEncerrado()) {
            return 'O evento foi encerrado em '.$edicao->evento_ate->format('d/m/Y H:i').'.';
        }

        return $edicao?->evento_de !== null
            ? 'O almoxarifado abre em '.$edicao->evento_de->format('d/m/Y H:i').'.'
            : 'O período do evento ainda não foi definido pela organização.';
    }

    /**
     * Os finalistas que o balcão pode atender, com as pessoas de cada um — é o
     * primeiro passo do assistente.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function projetos(?User $user = null, bool $teste = false, string $busca = '', int $limite = 20): Collection
    {
        $vigente = ListaFinal::vigente(null, $this->emTeste($user, $teste));

        if ($vigente === null) {
            return collect();
        }

        $busca = trim($busca);

        return Projeto::query()
            ->whereIn('id', $vigente->projetos()->select('projetos.id'))
            ->with(['alunos:id,projeto_id,nome', 'coorientador:id,projeto_id,nome', 'user:id,name', 'instituicao:id,nome'])
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(function ($sub) use ($termo) {
                    $sub->whereRaw('LOWER(titulo) LIKE ?', [$termo])
                        ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$termo]));
                });
            })
            ->orderBy('titulo')
            ->limit($limite)
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'escola' => $p->instituicao?->nome,
                'orientador' => $p->user?->name,
                'pessoas' => $this->pessoasDoProjeto($p),
            ]);
    }

    /**
     * A tabela de registros: um por atendimento, com o que já saiu e o que
     * ainda está guardado.
     *
     * Filtros: `busca` (projeto ou quem deixou) e `situacao`
     * (`guardados` | `retirados`).
     *
     * @param  array<string, mixed>  $filtros
     */
    public function listar(
        array $filtros,
        int $porPagina = 25,
        ?User $user = null,
        bool $teste = false,
    ): LengthAwarePaginator {
        $pagina = $this->query($filtros, $user, $teste)->paginate($porPagina)->withQueryString();

        $pagina->getCollection()->transform(fn (AlmoxarifadoGuarda $g) => $this->linha($g));

        return $pagina;
    }

    /**
     * Quantos atendimentos e quantos ainda têm material no balcão.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{registros:int, guardados:int, retirados:int, itens_guardados:int}
     */
    public function resumo(array $filtros, ?User $user = null, bool $teste = false): array
    {
        $base = $this->query(array_merge($filtros, ['situacao' => null]), $user, $teste)->reorder();

        $total = (clone $base)->count();
        $guardados = (clone $base)->whereHas('itens', fn ($q) => $q->whereNull('retirado_em'))->count();

        return [
            'registros' => $total,
            'guardados' => $guardados,
            'retirados' => $total - $guardados,
            'itens_guardados' => AlmoxarifadoItem::whereNull('retirado_em')
                ->whereIn('guarda_id', (clone $base)->select('almoxarifado_guardas.id'))
                ->count(),
        ];
    }

    /**
     * Registra a guarda. O assistente confirma tudo na tela antes de chegar
     * aqui — é a última tela do fluxo que vira esta chamada.
     *
     * @param  array{projeto_id:int, responsavel_tipo:string, responsavel_id:?int, itens:list<string>}  $dados
     */
    public function registrar(array $dados, User $admin, bool $teste = false): AlmoxarifadoGuarda
    {
        $projeto = $this->finalista((int) $dados['projeto_id'], $admin, $teste);
        $responsavel = $this->responsavel($projeto, $dados['responsavel_tipo'] ?? '', $dados['responsavel_id'] ?? null);
        $itens = $this->limparItens($dados['itens'] ?? []);

        return DB::transaction(function () use ($projeto, $responsavel, $itens, $admin, $teste) {
            $guarda = AlmoxarifadoGuarda::create([
                'edicao_id' => Edicao::atual()?->id,
                'projeto_id' => $projeto->id,
                'responsavel_tipo' => $responsavel['tipo'],
                'responsavel_id' => $responsavel['id'],
                'responsavel_nome' => $responsavel['nome'],
                'registrado_por' => $admin->id,
                'registrado_em' => now(),
                'demo' => $this->emTeste($admin, $teste),
            ]);

            foreach ($itens as $descricao) {
                $guarda->itens()->create(['descricao' => $descricao]);
            }

            $this->registros->almoxarifadoGuarda($projeto, $admin, $responsavel['nome'], $itens);

            return $guarda->fresh(['itens', 'autor']);
        });
    }

    /**
     * Registra a retirada. Sem `itens`, sai **tudo o que ainda está guardado**
     * — é a retirada completa; com eles, só os escolhidos.
     *
     * Quem retira não costuma ser quem deixou (o dono da maquete aparece antes
     * dos colegas), então o responsável é escolhido de novo aqui, e o horário é
     * o desta retirada — não o do registro.
     *
     * @param  array{responsavel_tipo:string, responsavel_id:?int, itens?:list<int>}  $dados
     */
    public function retirar(AlmoxarifadoGuarda $guarda, array $dados, User $admin, bool $teste = false): AlmoxarifadoGuarda
    {
        $this->exigirAberto($admin, $teste);

        $projeto = $guarda->projeto;
        $responsavel = $this->responsavel($projeto, $dados['responsavel_tipo'] ?? '', $dados['responsavel_id'] ?? null);

        $pendentes = $guarda->pendentes()->get();
        $escolhidos = $dados['itens'] ?? null;

        $alvos = $escolhidos === null
            ? $pendentes
            : $pendentes->whereIn('id', array_map('intval', $escolhidos));

        if ($alvos->isEmpty()) {
            throw ValidationException::withMessages([
                'itens' => 'Escolha ao menos um item que ainda esteja guardado.',
            ]);
        }

        $agora = now();

        return DB::transaction(function () use ($guarda, $alvos, $responsavel, $admin, $agora, $projeto) {
            foreach ($alvos as $item) {
                $item->update([
                    'retirado_em' => $agora,
                    'retirado_por_tipo' => $responsavel['tipo'],
                    'retirado_por_id' => $responsavel['id'],
                    'retirado_por_nome' => $responsavel['nome'],
                    'retirada_registrada_por' => $admin->id,
                ]);
            }

            $guarda->load('itens');

            $this->registros->almoxarifadoRetirada(
                $projeto,
                $admin,
                $responsavel['nome'],
                $alvos->pluck('descricao')->all(),
                $guarda->pendentes()->count() === 0,
                $agora,
            );

            return $guarda->fresh(['itens', 'autor']);
        });
    }

    /**
     * Corrige um registro: quem deixou e a composição dos itens.
     *
     * É material de outra pessoa na mão da organização, então a **justificativa
     * é obrigatória** e cada correção fica registrada. Item **já retirado** não
     * é apagado nem renomeado — ele não está mais aqui para ser conferido, e
     * mexer nele reescreveria uma entrega que já aconteceu.
     *
     * @param  array{responsavel_tipo:string, responsavel_id:?int, itens:list<array{id?:?int, descricao:string}>, justificativa:string}  $dados
     */
    public function editar(AlmoxarifadoGuarda $guarda, array $dados, User $admin, bool $teste = false): AlmoxarifadoGuarda
    {
        $this->exigirAberto($admin, $teste);

        $projeto = $guarda->projeto;
        $responsavel = $this->responsavel($projeto, $dados['responsavel_tipo'] ?? '', $dados['responsavel_id'] ?? null);
        $justificativa = trim((string) ($dados['justificativa'] ?? ''));

        $guarda->load('itens');
        $existentes = $guarda->itens->keyBy('id');
        $informados = $dados['itens'] ?? [];

        // O que a tela mandou, separado entre o que já existia e o que é novo.
        $mantidos = [];
        $novos = [];

        foreach ($informados as $item) {
            $descricao = trim(preg_replace('/\s+/u', ' ', (string) ($item['descricao'] ?? '')) ?? '');

            if ($descricao === '') {
                continue;
            }

            $id = isset($item['id']) ? (int) $item['id'] : null;

            if ($id !== null && $existentes->has($id)) {
                $mantidos[$id] = $descricao;
            } else {
                $novos[] = $descricao;
            }
        }

        if ($mantidos === [] && $novos === []) {
            throw ValidationException::withMessages(['itens' => 'O registro precisa ter ao menos um item.']);
        }

        $removidos = $existentes->reject(fn (AlmoxarifadoItem $i) => array_key_exists($i->id, $mantidos));
        $retiradoRemovido = $removidos->first(fn (AlmoxarifadoItem $i) => $i->retirado());

        if ($retiradoRemovido !== null) {
            throw ValidationException::withMessages([
                'itens' => 'O item “'.$retiradoRemovido->descricao.'” já foi retirado e não pode ser excluído do registro.',
            ]);
        }

        $antes = [
            'responsavel' => $guarda->responsavel_nome,
            'itens' => $guarda->itens->pluck('descricao')->all(),
        ];

        return DB::transaction(function () use (
            $guarda, $responsavel, $mantidos, $novos, $removidos, $admin, $projeto, $antes, $justificativa
        ) {
            $guarda->update([
                'responsavel_tipo' => $responsavel['tipo'],
                'responsavel_id' => $responsavel['id'],
                'responsavel_nome' => $responsavel['nome'],
            ]);

            foreach ($mantidos as $id => $descricao) {
                $item = $guarda->itens->firstWhere('id', $id);

                // Renomear o que já saiu reescreveria uma entrega concluída.
                if ($item !== null && ! $item->retirado() && $item->descricao !== $descricao) {
                    $item->update(['descricao' => $descricao]);
                }
            }

            $removidos->each(fn (AlmoxarifadoItem $i) => $i->delete());

            foreach ($novos as $descricao) {
                $guarda->itens()->create(['descricao' => $descricao]);
            }

            $guarda->load('itens');

            $this->registros->almoxarifadoEdicao($projeto, $admin, $antes, [
                'responsavel' => $guarda->responsavel_nome,
                'itens' => $guarda->itens->pluck('descricao')->all(),
            ], $justificativa);

            return $guarda->fresh(['itens', 'autor']);
        });
    }

    /**
     * Exclui o registro, com justificativa. É **soft delete**: a linha sai da
     * tela, e a trilha continua podendo explicar o que houve.
     */
    public function excluir(AlmoxarifadoGuarda $guarda, string $justificativa, User $admin, bool $teste = false): void
    {
        $this->exigirAberto($admin, $teste);

        $guarda->load(['itens', 'projeto']);
        $projeto = $guarda->projeto;
        $itens = $guarda->itens->pluck('descricao')->all();
        $responsavel = $guarda->responsavel_nome;

        DB::transaction(function () use ($guarda, $projeto, $admin, $responsavel, $itens, $justificativa) {
            // O registro vai antes do delete: ele precisa do que estava lá.
            $this->registros->almoxarifadoExclusao($projeto, $admin, $responsavel, $itens, trim($justificativa));

            $guarda->delete();
        });
    }

    /** O balcão precisa estar aberto para qualquer escrita. */
    private function exigirAberto(?User $user, bool $teste): void
    {
        if (! $this->podeOperar($user, $teste)) {
            throw ValidationException::withMessages(['almoxarifado' => $this->motivoFechado()]);
        }
    }

    /** A guarda em detalhe — a mesma linha da tabela, recarregada. */
    public function detalhe(AlmoxarifadoGuarda $guarda): array
    {
        return $this->linha($guarda->loadMissing([
            'itens', 'autor', 'projeto.instituicao', 'projeto.alunos', 'projeto.coorientador', 'projeto.user',
        ]));
    }

    /**
     * O projeto precisa ser finalista da lista que vale para esta pessoa — a
     * demo no modo de teste, a oficial fora dele. É o que impede o ensaio de
     * alcançar um projeto de verdade e vice-versa.
     */
    public function finalista(int $projetoId, ?User $user, bool $teste): Projeto
    {
        $vigente = ListaFinal::vigente(null, $this->emTeste($user, $teste));

        $projeto = $vigente === null
            ? null
            : Projeto::whereIn('id', $vigente->projetos()->select('projetos.id'))->find($projetoId);

        if ($projeto === null) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto não está na lista final vigente.',
            ]);
        }

        return $projeto;
    }

    /**
     * Quem pode figurar como responsável: só gente do projeto. O almoxarifado
     * entrega material da equipe, então um nome solto no campo não serviria
     * para cobrar de ninguém depois.
     *
     * @return array{tipo:string, id:?int, nome:string}
     */
    public function responsavel(Projeto $projeto, string $tipo, ?int $id, string $campo = 'responsavel_id'): array
    {
        $pessoas = collect($this->pessoasDoProjeto($projeto));
        $pessoa = $pessoas->first(fn (array $p) => $p['tipo'] === $tipo && $p['id'] === $id);

        if ($pessoa === null) {
            throw ValidationException::withMessages([
                $campo => 'Escolha um aluno, o orientador ou o coorientador deste projeto.',
            ]);
        }

        return ['tipo' => $pessoa['tipo'], 'id' => $pessoa['id'], 'nome' => $pessoa['nome']];
    }

    /**
     * As pessoas de um projeto, na ordem em que a tela as oferece.
     *
     * @return list<array{tipo:string, tipo_label:string, id:?int, nome:string}>
     */
    public function pessoasDoProjeto(Projeto $projeto): array
    {
        $projeto->loadMissing(['alunos', 'coorientador', 'user']);
        $pessoas = [];

        foreach ($projeto->alunos as $aluno) {
            $pessoas[] = $this->pessoa(TipoPessoaCredenciamento::Aluno, $aluno->id, $aluno->nome);
        }

        if ($projeto->user !== null) {
            $pessoas[] = $this->pessoa(TipoPessoaCredenciamento::Orientador, $projeto->user->id, $projeto->user->name);
        }

        if ($projeto->coorientador !== null) {
            $pessoas[] = $this->pessoa(
                TipoPessoaCredenciamento::Coorientador,
                $projeto->coorientador->id,
                $projeto->coorientador->nome,
            );
        }

        return $pessoas;
    }

    /**
     * Itens digitados: um por linha, sem vazios nem repetição de espaços. É a
     * contagem que a tabela mostra e o que a retirada parcial escolhe.
     *
     * @param  array<int, string>  $itens
     * @return list<string>
     */
    public function limparItens(array $itens): array
    {
        $limpos = array_values(array_filter(
            array_map(fn ($i) => trim(preg_replace('/\s+/u', ' ', (string) $i) ?? ''), $itens),
            fn (string $i) => $i !== '',
        ));

        if ($limpos === []) {
            throw ValidationException::withMessages([
                'itens' => 'Descreva ao menos um item a guardar.',
            ]);
        }

        return $limpos;
    }

    /** @return array{tipo:string, tipo_label:string, id:?int, nome:string} */
    private function pessoa(TipoPessoaCredenciamento $tipo, ?int $id, string $nome): array
    {
        return ['tipo' => $tipo->value, 'tipo_label' => $tipo->label(), 'id' => $id, 'nome' => $nome];
    }

    /**
     * Consulta base: os atendimentos da edição, no lado certo do modo de teste.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<AlmoxarifadoGuarda>
     */
    private function query(array $filtros, ?User $user = null, bool $teste = false): Builder
    {
        $busca = trim((string) ($filtros['busca'] ?? ''));

        return AlmoxarifadoGuarda::query()
            ->where('demo', $this->emTeste($user, $teste))
            ->when(Edicao::atual() !== null, fn ($q) => $q->where('edicao_id', Edicao::atual()->id))
            // As pessoas vêm junto porque a linha oferece as ações de retirada,
            // e todas elas precisam escolher quem é o responsável.
            ->with([
                'itens', 'autor:id,name',
                'projeto:id,titulo,instituicao_id,user_id', 'projeto.instituicao:id,nome',
                'projeto.alunos:id,projeto_id,nome', 'projeto.coorientador:id,projeto_id,nome',
                'projeto.user:id,name',
            ])
            ->when(($filtros['situacao'] ?? null) === 'guardados', fn ($q) => $q
                ->whereHas('itens', fn ($i) => $i->whereNull('retirado_em')))
            ->when(($filtros['situacao'] ?? null) === 'retirados', fn ($q) => $q
                ->whereDoesntHave('itens', fn ($i) => $i->whereNull('retirado_em')))
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(function ($sub) use ($termo) {
                    $sub->whereRaw('LOWER(responsavel_nome) LIKE ?', [$termo])
                        ->orWhereHas('projeto', fn ($p) => $p->whereRaw('LOWER(titulo) LIKE ?', [$termo]));
                });
            })
            ->orderByDesc('registrado_em')
            ->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function linha(AlmoxarifadoGuarda $guarda): array
    {
        $itens = $guarda->itens;
        $retirados = $itens->filter(fn (AlmoxarifadoItem $i) => $i->retirado());
        // A tabela mostra a retirada mais recente: é a informação que o balcão
        // procura quando alguém volta perguntando o que já saiu.
        $ultima = $retirados->sortByDesc('retirado_em')->first();

        return [
            'id' => $guarda->id,
            'projeto_id' => $guarda->projeto_id,
            'projeto' => $guarda->projeto?->titulo,
            'escola' => $guarda->projeto?->instituicao?->nome,
            'responsavel_tipo' => $guarda->responsavel_tipo?->value,
            'responsavel_id' => $guarda->responsavel_id,
            'responsavel' => $guarda->responsavel_nome,
            'registrado_em' => $guarda->registrado_em?->toIso8601String(),
            'registrado_por' => $guarda->autor?->name,
            // Quem pode figurar como responsável de uma retirada ou correção.
            'pessoas' => $guarda->projeto === null ? [] : $this->pessoasDoProjeto($guarda->projeto),
            'itens_total' => $itens->count(),
            'itens_retirados' => $retirados->count(),
            'itens_pendentes' => $itens->count() - $retirados->count(),
            'retirado_por' => $ultima?->retirado_por_nome,
            'retirado_em' => $ultima?->retirado_em?->toIso8601String(),
            'situacao' => $itens->isEmpty() || $retirados->count() < $itens->count()
                ? ($retirados->isEmpty() ? 'guardado' : 'parcial')
                : 'retirado',
            'itens' => $itens->map(fn (AlmoxarifadoItem $i) => [
                'id' => $i->id,
                'descricao' => $i->descricao,
                'retirado' => $i->retirado(),
                'retirado_em' => $i->retirado_em?->toIso8601String(),
                'retirado_por' => $i->retirado_por_nome,
            ])->values()->all(),
        ];
    }
}
