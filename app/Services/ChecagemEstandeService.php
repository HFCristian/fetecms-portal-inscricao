<?php

namespace App\Services;

use App\Enums\SituacaoDocumento;
use App\Enums\TipoDocumento;
use App\Models\ChecagemEstande;
use App\Models\ChecagemEstandeItem;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ItemChecagemEstande;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\TurnoApresentacao;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial → **Checagem de estandes**.
 *
 * No dia da feira alguém percorre os estandes e confere se o trabalho está de
 * pé: banner montado, equipe presente, material combinado. Hoje isso é
 * prancheta, e o que foi conferido some com ela — a tela existe para o dado
 * sobreviver ao dia.
 *
 * **Quem é checado** são os finalistas da lista vigente, como no credenciamento
 * e no almoxarifado. **Quando**: dentro da janela do evento; fora dela a seção
 * abre em leitura. A conta demo tem o modo de teste que ignora as datas, usa a
 * lista de demonstração e mantém os registros do ensaio à parte
 * (`checagens_estande.demo`).
 *
 * O **termo de responsabilidade** (Sprint 129) entra na ficha como um item a
 * mais, já marcado conforme o que o orientador anexou: ele é conferido no mesmo
 * momento, e obrigar a reconferir num outro lugar seria pedir a mesma volta
 * duas vezes. Ele não é editável aqui — quem o envia é o orientador —, mas é
 * **visualizável**: a ficha traz o link do PDF.
 */
class ChecagemEstandeService
{
    /** Chave do item sintético do termo — não vem do catálogo. */
    public const ITEM_TERMO = 'termo_responsabilidade';

    /** A checagem está aberta para escrita? */
    public function podeChecar(?User $user = null, bool $teste = false): bool
    {
        $edicao = Edicao::atual();

        if ($this->emTeste($user, $teste)) {
            return ListaFinal::vigente($edicao, true) !== null;
        }

        return (bool) $edicao?->eventoEmAndamento() && ListaFinal::vigente($edicao) !== null;
    }

    /** Só a conta demo tem modo de teste. */
    public function emTeste(?User $user, bool $teste): bool
    {
        return $teste && (bool) $user?->is_demo;
    }

    /**
     * Estado da seção: janela, lista em uso e o catálogo de itens.
     *
     * @return array<string, mixed>
     */
    public function config(?User $user = null, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $emTeste = $this->emTeste($user, $teste);
        $lista = ListaFinal::vigente($edicao, $emTeste);

        return [
            'aberto' => $this->podeChecar($user, $teste),
            'iniciado' => (bool) $edicao?->eventoIniciado(),
            'encerrado' => (bool) $edicao?->eventoEncerrado(),
            'inicio_label' => $edicao?->evento_de?->format('d/m/Y H:i'),
            'fim_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            'pode_testar' => (bool) $user?->is_demo,
            'modo_teste' => $emTeste,
            'situacoes' => SituacaoDocumento::opcoes(),
            'itens' => $this->catalogo(),
            'lista' => $lista === null ? null : [
                'id' => $lista->id, 'nome' => $lista->nome, 'versao' => $lista->versao, 'demo' => $lista->demo,
            ],
        ];
    }

    /** Motivo de a seção estar fechada, em palavras. */
    public function motivoFechado(?User $user = null, bool $teste = false): string
    {
        $edicao = Edicao::atual();

        if (ListaFinal::vigente($edicao, $this->emTeste($user, $teste)) === null) {
            return 'Ainda não há lista final publicada — sem finalistas, não há estande a conferir.';
        }

        if ($edicao?->evento_de === null) {
            return 'A organização ainda não definiu as datas do evento.';
        }

        return $edicao->eventoEncerrado()
            ? 'O evento terminou: a checagem fica disponível apenas para consulta.'
            : 'A checagem abre no início do evento.';
    }

    /**
     * Os finalistas com a situação da checagem de cada um.
     *
     * Filtros: `busca` (título, escola ou orientador), `area_id`, `categoria`,
     * `situacao` (`conferidos` | `pendentes`) e `turno`.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function finalistas(
        array $filtros,
        int $porPagina = 25,
        ?User $user = null,
        bool $teste = false,
    ): LengthAwarePaginator {
        $emTeste = $this->emTeste($user, $teste);
        $pagina = $this->query($filtros, $user, $teste)->paginate($porPagina)->withQueryString();

        $estandes = $this->estandesDe($pagina->getCollection()->pluck('id')->all());
        $checagens = $this->checagensDe($pagina->getCollection()->pluck('id')->all(), $emTeste);

        $pagina->getCollection()->transform(
            fn (Projeto $p) => $this->linha($p, $estandes[$p->id] ?? null, $checagens[$p->id] ?? null),
        );

        return $pagina;
    }

    /**
     * Quantos estandes há e quantos já foram conferidos, no recorte dos filtros
     * (menos o de situação — senão o card mostraria só a aba aberta).
     *
     * @param  array<string, mixed>  $filtros
     * @return array{finalistas:int, conferidos:int, pendentes:int}
     */
    public function resumo(array $filtros, ?User $user = null, bool $teste = false): array
    {
        $emTeste = $this->emTeste($user, $teste);
        $ids = $this->query(array_merge($filtros, ['situacao' => null]), $user, $teste)
            ->reorder()
            ->pluck('id')
            ->all();

        $conferidos = ChecagemEstande::whereIn('projeto_id', $ids)
            ->where('demo', $emTeste)
            ->whereNotNull('verificado_em')
            ->count();

        return [
            'finalistas' => count($ids),
            'conferidos' => $conferidos,
            'pendentes' => count($ids) - $conferidos,
        ];
    }

    /**
     * A ficha de um estande: o projeto, onde ele fica, os itens a conferir e o
     * termo de responsabilidade que o orientador enviou.
     *
     * @return array<string, mixed>
     */
    public function ficha(Projeto $projeto, ?User $user = null, bool $teste = false): array
    {
        $emTeste = $this->emTeste($user, $teste);
        $this->garantirFinalista($projeto, $user, $teste);

        $projeto->loadMissing(['area:id,nome', 'user:id,name', 'instituicao:id,nome', 'alunos:id,projeto_id,nome']);
        $checagem = $this->checagemDe($projeto, $emTeste);
        $marcado = $checagem?->itens->keyBy('item_id') ?? collect();

        return [
            'projeto' => [
                'id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                'escola' => $projeto->instituicao?->nome,
                'orientador' => $projeto->user?->name,
                'alunos' => $projeto->alunos->pluck('nome')->all(),
            ],
            'local' => $this->local($projeto->id),
            'itens' => array_map(fn (ItemChecagemEstande $i) => [
                'id' => $i->id,
                'nome' => $i->nome,
                'descricao' => $i->descricao,
                'situacao' => $marcado->get($i->id)?->situacao?->value,
            ], $this->catalogoAtivo()),
            // O termo não é editável aqui: quem o envia é o orientador. Ele
            // aparece já conferido (ou faltando) e pode ser aberto na hora.
            'termo' => $this->termo($projeto),
            'checagem' => $checagem === null ? null : [
                'verificado_em' => $checagem->verificado_em?->toIso8601String(),
                'verificado_por' => $checagem->autor?->name,
                'observacao' => $checagem->observacao,
            ],
        ];
    }

    /**
     * Grava a conferência de um estande.
     *
     * @param  array{itens?: array<int, array{item_id:int, situacao:string}>, observacao?: string|null}  $dados
     * @return array<string, mixed> a ficha recarregada
     */
    public function registrar(Projeto $projeto, array $dados, User $admin, bool $teste = false): array
    {
        $emTeste = $this->emTeste($admin, $teste);

        if (! $this->podeChecar($admin, $teste)) {
            throw ValidationException::withMessages(['periodo' => $this->motivoFechado($admin, $teste)]);
        }

        $this->garantirFinalista($projeto, $admin, $teste);

        $catalogo = collect($this->catalogoAtivo())->keyBy('id');

        DB::transaction(function () use ($projeto, $dados, $admin, $emTeste, $catalogo) {
            $checagem = ChecagemEstande::firstOrNew([
                'projeto_id' => $projeto->id,
                'demo' => $emTeste,
            ]);

            $checagem->fill([
                'lista_final_id' => ListaFinal::vigente(Edicao::atual(), $emTeste)?->id,
                'verificado_por' => $admin->id,
                'verificado_em' => now(),
                'observacao' => $dados['observacao'] ?? null,
                'demo' => $emTeste,
            ])->save();

            $checagem->itens()->delete();

            foreach ($dados['itens'] ?? [] as $item) {
                $doCatalogo = $catalogo->get((int) $item['item_id']);

                if ($doCatalogo === null) {
                    continue;
                }

                $checagem->itens()->create([
                    'item_id' => $doCatalogo->id,
                    // Desnormalizado: renomear o catálogo depois do evento não
                    // pode reescrever o que foi conferido.
                    'item_nome' => $doCatalogo->nome,
                    'situacao' => $item['situacao'],
                ]);
            }
        });

        return $this->ficha($projeto->refresh(), $admin, $teste);
    }

    /**
     * O **espelho** da checagem: todos os finalistas, com tudo o que foi
     * conferido e o termo de responsabilidade de cada um.
     *
     * É a tela de conferência da organização — a mesma informação das fichas,
     * lado a lado, para responder "o que ainda falta?" sem abrir projeto por
     * projeto.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    public function espelho(array $filtros, ?User $user = null, bool $teste = false): array
    {
        $emTeste = $this->emTeste($user, $teste);
        $projetos = $this->query($filtros, $user, $teste)
            ->with('alunos:id,projeto_id,nome')
            ->get();

        $ids = $projetos->pluck('id')->all();
        $estandes = $this->estandesDe($ids);
        $checagens = $this->checagensDe($ids, $emTeste);
        $ativos = $this->catalogoAtivo();

        return $projetos->map(function (Projeto $p) use ($estandes, $checagens, $ativos) {
            $checagem = $checagens[$p->id] ?? null;
            $marcado = $checagem?->itens->keyBy('item_id') ?? collect();

            return [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'escola' => $p->instituicao?->nome,
                'orientador' => $p->user?->name,
                'local' => $this->local($p->id, $estandes[$p->id] ?? null),
                'conferido_em' => $checagem?->verificado_em?->toIso8601String(),
                'conferido_por' => $checagem?->autor?->name,
                'observacao' => $checagem?->observacao,
                'itens' => array_map(fn (ItemChecagemEstande $i) => [
                    'id' => $i->id,
                    'nome' => $i->nome,
                    'situacao' => $marcado->get($i->id)?->situacao?->value,
                ], $ativos),
                'termo' => $this->termo($p),
            ];
        })->all();
    }

    // --- Catálogo de itens -------------------------------------------------

    /**
     * O catálogo inteiro (inclusive os desativados), para a tela de
     * parametrização.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogo(): array
    {
        return ItemChecagemEstande::orderBy('ordem')
            ->orderBy('nome')
            ->get()
            ->map(fn (ItemChecagemEstande $i) => [
                'id' => $i->id,
                'nome' => $i->nome,
                'descricao' => $i->descricao,
                'ordem' => $i->ordem,
                'ativo' => $i->ativo,
                // Item já conferido não é excluído: desative-o.
                'em_uso' => $i->id !== null && ChecagemEstandeItem::where('item_id', $i->id)->exists(),
            ])
            ->all();
    }

    /** @param  array<string, mixed>  $dados */
    public function criarItem(array $dados): ItemChecagemEstande
    {
        return ItemChecagemEstande::create([
            'nome' => trim($dados['nome']),
            'descricao' => $dados['descricao'] ?? null,
            'ordem' => (int) ($dados['ordem'] ?? 0),
            'ativo' => $dados['ativo'] ?? true,
        ]);
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizarItem(ItemChecagemEstande $item, array $dados): ItemChecagemEstande
    {
        $item->fill(array_filter([
            'nome' => isset($dados['nome']) ? trim($dados['nome']) : null,
            'descricao' => $dados['descricao'] ?? null,
            'ordem' => $dados['ordem'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('ativo', $dados)) {
            $item->ativo = (bool) $dados['ativo'];
        }

        $item->save();

        return $item;
    }

    public function excluirItem(ItemChecagemEstande $item): void
    {
        if (ChecagemEstandeItem::where('item_id', $item->id)->exists()) {
            throw ValidationException::withMessages([
                'item' => 'Este item já foi conferido em algum estande — desative-o em vez de excluir.',
            ]);
        }

        $item->delete();
    }

    // --- Internos ----------------------------------------------------------

    /** @return Builder<Projeto> */
    private function query(array $filtros, ?User $user = null, bool $teste = false): Builder
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $this->emTeste($user, $teste));
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $emTeste = $this->emTeste($user, $teste);

        return Projeto::query()
            ->when($lista === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($lista !== null, fn ($q) => $q->whereIn('id', $lista->projetos()->select('projetos.id')))
            ->with(['area:id,nome', 'user:id,name', 'instituicao:id,nome'])
            ->when(! empty($filtros['area_id']), fn ($q) => $q->where('area_id', $filtros['area_id']))
            ->when(! empty($filtros['categoria']), fn ($q) => $q->where('categoria', $filtros['categoria']))
            ->when(($filtros['situacao'] ?? null) === 'conferidos', fn ($q) => $q
                ->whereIn('id', ChecagemEstande::where('demo', $emTeste)->whereNotNull('verificado_em')->select('projeto_id')))
            ->when(($filtros['situacao'] ?? null) === 'pendentes', fn ($q) => $q
                ->whereNotIn('id', ChecagemEstande::where('demo', $emTeste)->whereNotNull('verificado_em')->select('projeto_id')))
            ->when(! empty($filtros['turno']), fn ($q) => $q->whereIn(
                'id',
                TurnoApresentacao::where('turno', $filtros['turno'])->select('projeto_id'),
            ))
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(function ($sub) use ($termo) {
                    $sub->whereRaw('LOWER(titulo) LIKE ?', [$termo])
                        ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$termo]))
                        ->orWhereHas('instituicao', fn ($i) => $i->whereRaw('LOWER(nome) LIKE ?', [$termo]));
                });
            })
            ->orderBy('titulo');
    }

    /** @return array<string, mixed> */
    private function linha(Projeto $projeto, ?EstandeProjeto $estande, ?ChecagemEstande $checagem): array
    {
        $itens = $checagem?->itens ?? collect();

        return [
            'id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'categoria' => $projeto->categoria?->value,
            'categoria_label' => $projeto->categoria?->label(),
            'area_id' => $projeto->area_id,
            'area' => $projeto->area?->nome,
            'escola' => $projeto->instituicao?->nome,
            'orientador' => $projeto->user?->name,
            'local' => $this->local($projeto->id, $estande),
            'conferido' => (bool) $checagem?->verificado_em,
            'conferido_em' => $checagem?->verificado_em?->toIso8601String(),
            'conferido_por' => $checagem?->autor?->name,
            // O que ficou faltando é o que a lista precisa destacar.
            'ausentes' => $itens->where('situacao', SituacaoDocumento::Ausente)->count(),
            'tem_termo' => $this->termo($projeto) !== null,
        ];
    }

    /**
     * Onde o projeto apresenta: turno e número do estande (Mapa do Evento).
     *
     * @return array<string, mixed>
     */
    private function local(int $projetoId, ?EstandeProjeto $estande = null): array
    {
        $estande ??= EstandeProjeto::where('projeto_id', $projetoId)->first();
        $turno = TurnoApresentacao::where('projeto_id', $projetoId)->first();

        return [
            'estande' => $estande?->numero,
            'turno' => $turno?->turno?->value ?? $estande?->turno?->value,
            'turno_label' => $turno?->turno?->label() ?? $estande?->turno?->label(),
        ];
    }

    /**
     * O termo de responsabilidade do projeto, para conferência e leitura.
     *
     * @return array<string, mixed>|null
     */
    private function termo(Projeto $projeto): ?array
    {
        $termo = ProjetoDocumento::where('projeto_id', $projeto->id)
            ->where('tipo', TipoDocumento::TermoResponsabilidade->value)
            ->latest('id')
            ->first();

        if ($termo === null) {
            return null;
        }

        $laudo = $termo->assinatura ?? [];

        return [
            'id' => $termo->id,
            'nome_original' => $termo->nome_original,
            'enviado_em' => $termo->created_at?->toIso8601String(),
            'assinatura_valida' => $termo->assinatura_valida,
            'assinatura_motivo' => $laudo['motivo'] ?? 'Assinatura não conferida.',
        ];
    }

    /** @return list<ItemChecagemEstande> */
    private function catalogoAtivo(): array
    {
        return ItemChecagemEstande::where('ativo', true)
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, EstandeProjeto>
     */
    private function estandesDe(array $ids): array
    {
        return EstandeProjeto::whereIn('projeto_id', $ids)->get()->keyBy('projeto_id')->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, ChecagemEstande>
     */
    private function checagensDe(array $ids, bool $demo): array
    {
        return ChecagemEstande::whereIn('projeto_id', $ids)
            ->where('demo', $demo)
            ->with(['itens', 'autor:id,name'])
            ->get()
            ->keyBy('projeto_id')
            ->all();
    }

    private function checagemDe(Projeto $projeto, bool $demo): ?ChecagemEstande
    {
        return ChecagemEstande::where('projeto_id', $projeto->id)
            ->where('demo', $demo)
            ->with(['itens', 'autor:id,name'])
            ->first();
    }

    private function garantirFinalista(Projeto $projeto, ?User $user, bool $teste): void
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $this->emTeste($user, $teste));

        if ($lista === null || ! $lista->projetos()->whereKey($projeto->id)->exists()) {
            // 404 e não 403: em modo de teste um projeto oficial simplesmente
            // não existe, e vice-versa — é o que impede o ensaio alcançar um
            // finalista de verdade.
            abort(404);
        }
    }
}
