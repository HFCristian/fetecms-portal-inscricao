<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\TipoCredencial;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\FotografiaEstande;
use App\Models\ListaFinal;
use App\Models\ListaPresencial;
use App\Models\ListaPresencialPremio;
use App\Models\Projeto;
use App\Models\User;
use App\Support\ApresentacaoPptx;
use App\Support\Cota;
use App\Support\PlanilhaXlsx;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ranking presencial → **listas preliminares e finais** (Sprint 251).
 *
 * As mesmas ideias da lista da avaliação online ({@see ListaFinalService}),
 * sobre a nota do estande:
 *
 * - a lista nasce **rascunho**, da classificação (em cada área de cada
 *   categoria, **até o Nº lugar** — padrão do 1º ao 4º, Sprint 255 —, com
 *   tetos opcionais por categoria e no total) ou da **união** de listas já
 *   geradas;
 * - **gerar** fecha: a preliminar fica registrada; a final vira a **ativa** da
 *   edição e a anterior passa a inativa, reativável com justificativa;
 * - o arquivo sai no formato da lista online — "CÓDIGO - Título", escola,
 *   estudantes, orientador e coorientador —, com a **colocação** de cada
 *   projeto no seu grupo categoria+área.
 *
 * O universo é o do ranking presencial: os finalistas da lista final vigente
 * (a demo, no modo de teste). O código é o do finalista nessa lista — a equipe
 * já o recebeu, e a premiação não renumera ninguém.
 *
 * Não existe reserva para o interior aqui: ela é exigência do fomento da
 * classificação online, não da premiação.
 *
 * A lista tem uma segunda seção (Sprint 253): as **premiações** — as
 * credenciais e os prêmios de Credenciais e Prêmios, **copiados** quando a
 * lista é montada e editáveis nela sem mexer no cadastro original.
 */
class ListaPresencialService
{
    public function __construct(
        private readonly RankingPresencialService $ranking,
        private readonly ListaFinalService $listasFinais,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /** Os códigos da lista final por id dela: montar um é caro, e a tela pede duas vezes. */
    private array $codigos = [];

    /**
     * Por padrão premia-se do 1º ao 4º lugar de cada área em cada categoria
     * (Sprint 255). O assistente vem preenchido com isso, e o admin muda área a
     * área na hora de gerar.
     */
    public const LUGARES_PADRAO = 4;

    /**
     * O que a tela de geração oferece: quantos projetos avaliados há em cada
     * categoria e em cada área dentro dela.
     *
     * @return array<string, mixed>
     */
    public function opcoes(bool $demo = false): array
    {
        $vigente = ListaFinal::vigente(null, $demo);
        $avaliados = $this->avaliados($demo);
        $areas = Area::query()->orderBy('nome')->get(['id', 'nome', 'sigla']);
        $porCategoria = $avaliados->groupBy(fn (array $p) => $p['categoria_valor'] ?? '');

        return [
            'lista_final' => $vigente === null ? null : ['id' => $vigente->id, 'nome' => $vigente->nome],
            'total_disponivel' => $avaliados->count(),
            'lugares_padrao' => self::LUGARES_PADRAO,
            'categorias' => array_map(function (Categoria $c) use ($porCategoria, $areas) {
                $daCategoria = $porCategoria->get($c->value, collect());
                $porArea = $daCategoria->groupBy(fn (array $p) => $p['area_id'] ?? 0);

                return [
                    'value' => $c->value,
                    'label' => $c->label(),
                    'sigla' => $c->sigla(),
                    'disponiveis' => $daCategoria->count(),
                    'areas' => $areas->map(fn (Area $a) => [
                        'id' => $a->id,
                        'nome' => $a->nome,
                        'sigla' => $a->siglaDaLista(),
                        'disponiveis' => $porArea->get($a->id, collect())->count(),
                    ])->values()->all(),
                ];
            }, Categoria::ordemDaLista()),
        ];
    }

    /**
     * Monta o rascunho a partir da classificação: em cada área de cada
     * categoria, os premiados **até o Nº lugar** (padrão 4º); por cima, os
     * tetos opcionais por categoria e no total.
     *
     * @param  array<string, mixed>  $cotas  `['total' => cota, 'categorias' => [cat => ['cota' => cota, 'areas' => [id => ['lugares' => ?int]]]]]` — área sem `lugares` segue o padrão; `lugares` nulo não limita
     */
    public function rascunhar(
        array $cotas,
        User $admin,
        ?string $nome = null,
        string $tipo = ListaPresencial::TIPO_PRELIMINAR,
        ?string $descricao = null,
        bool $demo = false,
    ): ListaPresencial {
        $edicao = $this->edicaoOuFalha();

        if (ListaFinal::vigente($edicao, $demo) === null) {
            throw ValidationException::withMessages([
                'lista' => 'A lista final ainda não foi gerada: sem finalistas, não há ranking presencial.',
            ]);
        }

        $escolhidos = $this->selecionar($this->avaliados($demo), $cotas);
        $tipo = $this->tipo($tipo);

        return DB::transaction(function () use ($edicao, $admin, $nome, $descricao, $tipo, $cotas, $demo, $escolhidos) {
            $lista = ListaPresencial::create([
                'edicao_id' => $edicao->id,
                'nome' => $this->nomeOuPadrao($nome, $edicao, $tipo),
                'descricao' => $this->textoOuNulo($descricao),
                'tipo' => $tipo,
                'vigente' => false,
                'rascunho' => true,
                'demo' => $demo,
                'versao' => 1,
                'cotas' => $cotas,
                'gerada_por' => $admin->id,
            ]);

            $lista->projetos()->attach(
                collect($escolhidos)->mapWithKeys(fn (array $p) => [$p['projeto_id'] => ['manual' => false]])->all(),
            );

            $this->copiarPremiacoes($lista);

            return $lista->fresh();
        });
    }

    /**
     * Rascunho montado pela **união** de listas presenciais já geradas — as
     * preliminares e as finais, ativa ou não —, sem repetir projeto.
     *
     * @param  list<int>  $listaIds
     */
    public function rascunharDeListas(
        array $listaIds,
        string $tipo,
        User $admin,
        ?string $nome = null,
        ?string $descricao = null,
        bool $demo = false,
    ): ListaPresencial {
        $edicao = $this->edicaoOuFalha();
        $tipo = $this->tipo($tipo);

        $origens = ListaPresencial::query()
            ->whereIn('id', $listaIds)
            ->where('edicao_id', $edicao->id)
            ->where('rascunho', false)
            ->where('demo', $demo)
            ->orderByDesc('id')
            ->get();

        if ($origens->isEmpty() || $origens->count() !== count(array_unique($listaIds))) {
            throw ValidationException::withMessages([
                'listas' => 'Escolha listas presenciais preliminares ou finais já geradas nesta edição.',
            ]);
        }

        $projetos = DB::table('lista_presencial_projetos')
            ->whereIn('lista_presencial_id', $origens->pluck('id'))
            ->pluck('projeto_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return DB::transaction(function () use ($edicao, $admin, $nome, $descricao, $tipo, $demo, $origens, $projetos) {
            $lista = ListaPresencial::create([
                'edicao_id' => $edicao->id,
                'nome' => $this->nomeOuPadrao($nome, $edicao, $tipo),
                'descricao' => $this->textoOuNulo($descricao),
                'tipo' => $tipo,
                'vigente' => false,
                'rascunho' => true,
                'demo' => $demo,
                'versao' => 1,
                'origens' => $origens->map(fn (ListaPresencial $l) => ['id' => $l->id, 'nome' => $l->nome, 'tipo' => $l->tipo])->values()->all(),
                'gerada_por' => $admin->id,
            ]);

            $lista->projetos()->attach(
                collect($projetos)->mapWithKeys(fn (int $id) => [$id => ['manual' => false]])->all(),
            );

            $this->unirPremiacoes($lista, $origens);

            return $lista->fresh();
        });
    }

    /**
     * **Gera** a lista: a preliminar fica registrada; a final vira a ativa da
     * edição, e a que estava ativa passa a inativa.
     */
    public function gerar(ListaPresencial $lista, User $admin): ListaPresencial
    {
        if (! $lista->rascunho) {
            throw ValidationException::withMessages(['lista' => 'Esta lista já foi gerada.']);
        }

        if ($this->vazia($lista)) {
            throw ValidationException::withMessages(['lista' => 'Uma lista sem nenhum projeto não pode ser gerada.']);
        }

        return DB::transaction(function () use ($lista, $admin) {
            if ($lista->ehFinal()) {
                $this->desativarFinais($lista);
            }

            $lista->forceFill([
                'vigente' => $lista->ehFinal(),
                'rascunho' => false,
                'gerada_em' => now(),
            ])->save();

            $this->registros->listaFinal(
                TipoRegistro::ListaPresencialGerada,
                $admin,
                $lista->nome,
                null,
                null,
                ($lista->ehFinal() ? 'final (ativa)' : 'preliminar').' · '.$lista->projetos()->count().' projeto(s) por nota · '
                    .$this->contarPremiacoes($lista).' premiação(ões)',
            );

            return $lista->fresh();
        });
    }

    /** Volta a ativar uma final já gerada, com justificativa. */
    public function reativar(ListaPresencial $lista, User $admin, string $justificativa): ListaPresencial
    {
        if (! $lista->ehFinal() || $lista->rascunho) {
            throw ValidationException::withMessages(['lista' => 'Só uma lista final já gerada pode ser a ativa.']);
        }

        if ($lista->vigente) {
            throw ValidationException::withMessages(['lista' => 'Esta lista já é a final ativa.']);
        }

        return DB::transaction(function () use ($lista, $admin, $justificativa) {
            $anterior = ListaPresencial::vigente($lista->edicao, $lista->demo);
            $this->desativarFinais($lista);
            $lista->forceFill(['vigente' => true])->save();

            $this->registros->listaFinal(
                TipoRegistro::ListaPresencialReativada,
                $admin,
                $lista->nome,
                null,
                trim($justificativa),
                $anterior ? "no lugar de {$anterior->nome}" : 'nenhuma estava ativa',
            );

            return $lista->fresh();
        });
    }

    /**
     * Inclui um finalista na seção por nota (Sprint 252). No rascunho é livre;
     * depois de gerada pede justificativa, sobe a versão e entra em Registros
     * → Lista final — é uma decisão fora do recorte por nota.
     */
    public function adicionarProjeto(ListaPresencial $lista, Projeto $projeto, User $admin, ?string $justificativa = null): ListaPresencial
    {
        if ($lista->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages(['projeto_id' => 'Este projeto já está na lista.']);
        }

        $this->garantirFinalista($lista, $projeto);

        return $this->alterar($lista, $admin, $justificativa, TipoRegistro::ListaPresencialProjetoAdicionado, $projeto,
            fn () => $lista->projetos()->attach($projeto->id, ['manual' => true]));
    }

    /** Retira um projeto da seção por nota, com as mesmas regras da inclusão. */
    public function removerProjeto(ListaPresencial $lista, Projeto $projeto, User $admin, ?string $justificativa = null): ListaPresencial
    {
        if (! $lista->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages(['projeto_id' => 'Este projeto não está na lista.']);
        }

        return $this->alterar($lista, $admin, $justificativa, TipoRegistro::ListaPresencialProjetoRemovido, $projeto,
            fn () => $lista->projetos()->detach($projeto->id));
    }

    /**
     * Aplica uma mudança de composição: livre no rascunho; na lista gerada,
     * com justificativa, versão nova e registro.
     */
    private function alterar(
        ListaPresencial $lista,
        User $admin,
        ?string $justificativa,
        TipoRegistro $tipo,
        ?Projeto $projeto,
        callable $mudanca,
        ?string $detalhe = null,
    ): ListaPresencial {
        if ($lista->rascunho) {
            $mudanca();

            return $lista->fresh();
        }

        if (mb_strlen(trim((string) $justificativa)) < 5) {
            throw ValidationException::withMessages([
                'justificativa' => 'Explique a alteração (ao menos 5 caracteres): a lista já foi gerada.',
            ]);
        }

        return DB::transaction(function () use ($lista, $admin, $justificativa, $tipo, $projeto, $mudanca, $detalhe) {
            $mudanca();
            $lista->increment('versao');

            $this->registros->listaFinal($tipo, $admin, $lista->nome.($detalhe ? " · {$detalhe}" : ''), $projeto, trim((string) $justificativa));

            return $lista->fresh();
        });
    }

    /** Só finalista da lista final vigente (da mesma trilha) recebe premiação. */
    private function garantirFinalista(ListaPresencial $lista, Projeto $projeto): void
    {
        $vigente = ListaFinal::vigente($lista->edicao, $lista->demo);

        if ($vigente === null || ! $vigente->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Só um finalista da lista final vigente pode entrar na lista da premiação.',
            ]);
        }
    }

    /**
     * Os premiados por nota da lista, com o nome do prêmio de cada um ("1º
     * lugar · Área · Categoria"), por id do projeto — a conta leve que o
     * Cerimonial faz a cada atualização do painel (Sprint 252).
     *
     * @return array<int, string>
     */
    public function rotulosPorNota(ListaPresencial $lista): array
    {
        $ids = $lista->projetos()->pluck('projetos.id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $medias = $this->ranking->medias($ids);
        $itens = Projeto::query()
            ->whereIn('id', $ids)
            ->with('area:id,nome')
            ->get(['id', 'titulo', 'area_id', 'categoria'])
            ->map(fn (Projeto $p) => [
                'projeto_id' => $p->id,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'categoria_valor' => $p->categoria?->value,
                'area' => $p->area?->nome,
                'area_id' => $p->area_id,
                'media' => $medias[$p->id]['media'] ?? null,
                'avaliacoes' => $medias[$p->id]['avaliacoes'] ?? 0,
            ])
            ->values()
            ->all();

        return collect($this->colocar($itens))
            ->mapWithKeys(fn (array $i) => [$i['projeto_id'] => $this->rotuloDaColocacao($i)])
            ->all();
    }

    // --- Premiações (Sprint 253) -----------------------------------------------

    /**
     * Copia as credenciais e os prêmios **ativos** de Credenciais e Prêmios
     * para a lista, com os projetos que os receberam — inclusive os que ainda
     * não têm ninguém, para o admin preencher aqui. Só entram finalistas da
     * trilha da lista: a de ensaio não herda premiado de verdade.
     */
    private function copiarPremiacoes(ListaPresencial $lista): void
    {
        $finalistas = $this->idsFinalistas($lista);

        Credencial::where('edicao_id', $lista->edicao_id)
            ->where('ativa', true)
            ->with('projetos:id')
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get()
            ->values()
            ->each(function (Credencial $c, int $i) use ($lista, $finalistas) {
                $premio = $lista->premios()->create([
                    'credencial_id' => $c->id,
                    'tipo' => $c->tipo,
                    'nome' => $c->nome,
                    'orgao' => $c->orgao,
                    'ordem' => $i,
                ]);

                $premio->projetos()->attach(
                    $c->projetos->pluck('id')
                        ->filter(fn ($id) => in_array((int) $id, $finalistas, true))
                        ->mapWithKeys(fn ($id) => [(int) $id => ['manual' => false]])
                        ->all(),
                );
            });
    }

    /**
     * Na união, a mesma credencial (ou o mesmo nome, quando ela não existe
     * mais) vira uma seção só, com os projetos de todas as origens.
     *
     * @param  Collection<int, ListaPresencial>  $origens
     */
    private function unirPremiacoes(ListaPresencial $lista, Collection $origens): void
    {
        $premios = ListaPresencialPremio::whereIn('lista_presencial_id', $origens->pluck('id'))
            ->with('projetos:id')
            ->orderBy('ordem')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ListaPresencialPremio $p) => $p->credencial_id !== null
                ? 'c'.$p->credencial_id
                : 'n'.$p->tipo->value.'|'.$this->chave($p->nome));

        $ordem = 0;
        foreach ($premios as $grupo) {
            $primeiro = $grupo->first();
            $novo = $lista->premios()->create([
                'credencial_id' => $primeiro->credencial_id,
                'tipo' => $primeiro->tipo,
                'nome' => $primeiro->nome,
                'orgao' => $primeiro->orgao,
                'ordem' => $ordem++,
            ]);

            $novo->projetos()->attach(
                $grupo->flatMap(fn (ListaPresencialPremio $p) => $p->projetos->pluck('id'))
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->mapWithKeys(fn (int $id) => [$id => ['manual' => false]])
                    ->all(),
            );
        }
    }

    /**
     * A seção de premiações da lista: cada credencial ou prêmio com os
     * projetos, em ordem de título.
     *
     * @return list<array<string, mixed>>
     */
    public function premiacoes(ListaPresencial $lista): array
    {
        $premios = $lista->premios()->with('projetos:id')->get();
        $ids = $premios->flatMap(fn (ListaPresencialPremio $p) => $p->projetos->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        $descritos = collect($ids === [] ? [] : $this->descrever($ids, $lista->demo))->keyBy('projeto_id');

        return $premios->map(fn (ListaPresencialPremio $p) => [
            'id' => $p->id,
            'credencial_id' => $p->credencial_id,
            'tipo' => $p->tipo->value,
            'tipo_label' => $p->tipo->label(),
            'nome' => $p->nome,
            'orgao' => $p->orgao,
            'titulo' => $p->titulo(),
            'itens' => $p->projetos
                ->map(fn (Projeto $projeto) => isset($descritos[$projeto->id])
                    ? $descritos[$projeto->id] + ['colocacao' => null, 'manual' => (bool) $projeto->pivot->manual]
                    : null)
                ->filter()
                ->sortBy(fn (array $i) => $this->chave($i['titulo']))
                ->values()
                ->all(),
        ])->values()->all();
    }

    /** Inclui um finalista numa credencial ou prêmio da lista. */
    public function adicionarNaPremiacao(ListaPresencial $lista, ListaPresencialPremio $premio, Projeto $projeto, User $admin, ?string $justificativa = null): ListaPresencial
    {
        $this->garantirDaLista($lista, $premio);

        if ($premio->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages(['projeto_id' => "Este projeto já está em {$premio->nome}."]);
        }

        $this->garantirFinalista($lista, $projeto);

        return $this->alterar($lista, $admin, $justificativa, TipoRegistro::ListaPresencialProjetoAdicionado, $projeto,
            fn () => $premio->projetos()->attach($projeto->id, ['manual' => true]), $premio->nome);
    }

    /** Retira um projeto de uma credencial ou prêmio da lista. */
    public function removerDaPremiacao(ListaPresencial $lista, ListaPresencialPremio $premio, Projeto $projeto, User $admin, ?string $justificativa = null): ListaPresencial
    {
        $this->garantirDaLista($lista, $premio);

        if (! $premio->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages(['projeto_id' => "Este projeto não está em {$premio->nome}."]);
        }

        return $this->alterar($lista, $admin, $justificativa, TipoRegistro::ListaPresencialProjetoRemovido, $projeto,
            fn () => $premio->projetos()->detach($projeto->id), $premio->nome);
    }

    /**
     * Troca a seção inteira pelo que está **agora** em Credenciais e Prêmios —
     * para a credencial atribuída depois de a lista ser montada. O que foi
     * mexido à mão nesta seção se perde, e a tela avisa.
     */
    public function recarregarPremiacoes(ListaPresencial $lista, User $admin, ?string $justificativa = null): ListaPresencial
    {
        return $this->alterar($lista, $admin, $justificativa, TipoRegistro::ListaPresencialPremiacoesRecarregadas, null,
            function () use ($lista) {
                $lista->premios()->delete();
                $this->copiarPremiacoes($lista);
            });
    }

    /**
     * As premiações da lista por projeto, no formato do Cerimonial — a conta
     * leve que roda a cada atualização do painel.
     *
     * @return array<int, list<array{id: int|string, nome: string, tipo: string, tipo_label: string}>>
     */
    public function premiacoesPorProjeto(ListaPresencial $lista): array
    {
        $porProjeto = [];

        foreach ($lista->premios()->with('projetos:id')->get() as $premio) {
            foreach ($premio->projetos as $projeto) {
                $porProjeto[$projeto->id][] = [
                    'id' => $premio->credencial_id ?? 'lp-'.$premio->id,
                    'nome' => $premio->nome,
                    'tipo' => $premio->tipo->value,
                    'tipo_label' => $premio->tipo->label(),
                ];
            }
        }

        return $porProjeto;
    }

    private function garantirDaLista(ListaPresencial $lista, ListaPresencialPremio $premio): void
    {
        abort_unless($premio->lista_presencial_id === $lista->id, 404);
    }

    /** @return list<int> */
    private function idsFinalistas(ListaPresencial $lista): array
    {
        $vigente = ListaFinal::vigente($lista->edicao, $lista->demo);

        return $vigente === null ? [] : $vigente->projetos()->pluck('projetos.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Apelido e descrição: rótulo, não composição — sem justificativa nem versão. */
    public function rotular(ListaPresencial $lista, ?string $nome, ?string $descricao): ListaPresencial
    {
        $lista->update([
            'nome' => $this->nomeOuPadrao($nome, $lista->edicao ?? $this->edicaoOuFalha(), $lista->tipo),
            'descricao' => $this->textoOuNulo($descricao),
        ]);

        return $lista->fresh();
    }

    /**
     * As listas da edição em curso, na trilha pedida (oficial ou de teste), da
     * mais nova para a mais antiga.
     *
     * @return list<array<string, mixed>>
     */
    public function listas(bool $demo = false): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        return ListaPresencial::where('edicao_id', $edicao->id)
            ->where('demo', $demo)
            ->with('autor:id,name')
            ->withCount('projetos')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ListaPresencial $l) => $this->resumo($l) + [
                'gerada_por' => $l->autor?->name,
                'criada_em' => $l->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * A tela de uma lista: o cabeçalho, a seção por nota e quem ainda pode
     * entrar nela.
     *
     * @return array<string, mixed>
     */
    public function detalhar(ListaPresencial $lista): array
    {
        $itens = $this->itens($lista);
        $dentro = array_column($itens, 'projeto_id');

        $finalistas = $this->finalistas($lista->demo);
        $premiacoes = $this->premiacoes($lista);

        return [
            'lista' => $this->resumo($lista) + ['projetos' => count($itens)],
            'itens' => $itens,
            'premiacoes' => $premiacoes,
            'slides' => $this->resumoSlides($lista, $itens, $premiacoes),
            // Quem pode entrar numa premiação: qualquer finalista (a tela tira
            // os que já estão nela).
            'finalistas' => $finalistas
                ->sortBy(fn (array $p) => $this->chave($p['titulo']))
                ->map(fn (array $p) => [
                    'id' => $p['projeto_id'],
                    'titulo' => $p['titulo'],
                    'codigo' => $p['codigo'],
                    'categoria' => $p['categoria'],
                    'area' => $p['area'],
                ])
                ->values()
                ->all(),
            // Os finalistas fora da seção por nota, os avaliados primeiro.
            'candidatos' => $finalistas
                ->reject(fn (array $p) => in_array($p['projeto_id'], $dentro, true))
                ->sortBy([
                    fn (array $a, array $b) => ($b['media'] ?? -1) <=> ($a['media'] ?? -1),
                    fn (array $a, array $b) => strcmp($this->chave($a['titulo']), $this->chave($b['titulo'])),
                ])
                ->map(fn (array $p) => [
                    'id' => $p['projeto_id'],
                    'titulo' => $p['titulo'],
                    'codigo' => $p['codigo'],
                    'categoria' => $p['categoria'],
                    'area' => $p['area'],
                    'media' => $p['media'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A seção por nota, já ordenada: categoria (FETECMS → FETEC Jr → FUNDECT),
     * área em ordem alfabética e colocação no grupo. O projeto sem avaliação
     * presencial (incluído à mão) fica no fim do grupo, sem colocação.
     *
     * @return list<array<string, mixed>>
     */
    public function itens(ListaPresencial $lista): array
    {
        $ids = $lista->projetos()->pluck('projetos.id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $manuais = $lista->projetos()->pluck('lista_presencial_projetos.manual', 'projetos.id');
        $itens = $this->descrever($ids, $lista->demo);

        foreach ($itens as &$item) {
            $item['manual'] = (bool) ($manuais[$item['projeto_id']] ?? false);
        }
        unset($item);

        return $this->colocar($itens);
    }

    /**
     * Cada premiado da lista com o nome do que ele recebeu, na ordem do
     * arquivo — é o que os quatro formatos e os slides percorrem.
     *
     * @return list<array{secao: string, premiacao: string, item: array<string, mixed>}>
     */
    public function premiados(ListaPresencial $lista): array
    {
        $porNota = array_map(fn (array $item) => [
            'secao' => 'nota',
            'tipo' => 'nota',
            'premiacao' => $this->rotuloDaColocacao($item),
            'item' => $item,
        ], $this->itens($lista));

        $premiacoes = [];
        foreach ($this->premiacoes($lista) as $premio) {
            foreach ($premio['itens'] as $item) {
                $premiacoes[] = [
                    'secao' => 'premiacao',
                    'tipo' => $premio['tipo'],
                    'premiacao' => $premio['titulo'],
                    'item' => $item,
                ];
            }
        }

        return [...$porNota, ...$premiacoes];
    }

    /**
     * O nome do prêmio por nota: "1º lugar · Ciências Agrárias · FETECMS".
     * Sem colocação (projeto sem avaliação presencial), só o grupo.
     *
     * @param  array<string, mixed>  $item
     */
    public function rotuloDaColocacao(array $item): string
    {
        return implode(' · ', array_filter([
            $item['colocacao'] !== null ? $item['colocacao'].'º lugar' : null,
            $item['area'] ?? 'Sem área',
            $item['categoria'],
        ]));
    }

    // --- Arquivos ---------------------------------------------------------------

    /**
     * A lista em arquivo: TXT no formato da lista online, CSV e Excel com uma
     * linha por premiação, PDF para o papel.
     *
     * @return array{conteudo: string, tipo: string, nome: string}
     */
    public function exportar(ListaPresencial $lista, string $formato): array
    {
        $nome = 'lista-presencial-'.Str::slug($lista->nome).'-v'.$lista->versao;

        return match ($formato) {
            'csv' => ['conteudo' => $this->csv($lista), 'tipo' => 'text/csv; charset=UTF-8', 'nome' => $nome.'.csv'],
            'xlsx' => [
                'conteudo' => PlanilhaXlsx::gerar(
                    'Lista presencial',
                    self::COLUNAS,
                    array_map(fn (array $p) => $this->linhaArquivo($p, numerico: true), $this->premiados($lista)),
                ),
                'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'nome' => $nome.'.xlsx',
            ],
            'pdf' => ['conteudo' => $this->pdf($lista), 'tipo' => 'application/pdf', 'nome' => $nome.'.pdf'],
            default => ['conteudo' => $this->txt($lista), 'tipo' => 'text/plain; charset=UTF-8', 'nome' => $nome.'.txt'],
        };
    }

    private const COLUNAS = [
        'Seção', 'Premiação', 'Colocação', 'Código', 'Projeto', 'Categoria', 'Área', 'Escola',
        'Estudantes', 'Orientador(a)', 'Coorientador(a)', 'Média presencial',
    ];

    /**
     * @param  array{secao: string, premiacao: string, item: array<string, mixed>}  $p
     * @return list<string|int|float|null>
     */
    private function linhaArquivo(array $p, bool $numerico = false): array
    {
        $i = $p['item'];

        return [
            $p['secao'] === 'nota' ? 'Por nota' : (TipoCredencial::tryFrom($p['tipo'])?->label() ?? 'Premiação'),
            $p['premiacao'],
            $p['secao'] === 'nota' && $i['colocacao'] !== null ? $i['colocacao'].'º' : '',
            $i['codigo'] ?? '',
            $i['titulo'],
            $i['categoria'] ?? '',
            $i['area'] ?? 'Sem área',
            $i['escola'],
            implode('; ', $i['alunos']),
            $i['orientador'] ?? '',
            $i['coorientador'] ?? '',
            $i['media'] === null ? ($numerico ? null : '') : ($numerico ? (float) $i['media'] : RankingPresencialService::nota($i['media'])),
        ];
    }

    private function csv(ListaPresencial $lista): string
    {
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}");
        fputcsv($saida, self::COLUNAS, ';');

        foreach ($this->premiados($lista) as $p) {
            fputcsv($saida, $this->linhaArquivo($p), ';');
        }

        rewind($saida);
        $csv = (string) stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }

    /**
     * O TXT: um cabeçalho por seção e por grupo, e cada projeto no bloco da
     * lista online, com a colocação na linha de cima.
     */
    private function txt(ListaPresencial $lista): string
    {
        $edicao = $lista->edicao?->nome;
        $saida = [
            mb_strtoupper($lista->ehFinal() ? 'Lista final presencial' : 'Lista preliminar presencial')
                .($edicao ? ' · '.$edicao : ''),
            $lista->nome,
        ];

        $saida[] = '';
        $saida[] = '== PREMIADOS POR NOTA';

        $grupos = collect($this->itens($lista))->groupBy(fn (array $i) => ($i['categoria'] ?? '').'|'.($i['area'] ?? ''));

        if ($grupos->isEmpty()) {
            $saida[] = '';
            $saida[] = '(nenhum projeto)';
        }

        foreach ($grupos as $itens) {
            $primeiro = $itens->first();
            $saida[] = '';
            $saida[] = '== '.mb_strtoupper(implode(' · ', array_filter([$primeiro['categoria'], $primeiro['area'] ?? 'Sem área'])));

            foreach ($itens as $item) {
                $saida[] = '';
                $saida[] = $item['colocacao'] !== null ? $item['colocacao'].'º lugar' : 'Sem colocação';
                $saida[] = rtrim($this->listasFinais->blocos([$this->paraBloco($item)]));
            }
        }

        // As premiações no cabeçalho da lista de premiação (Credenciais e
        // Prêmios) e cada projeto no mesmo bloco.
        $saida[] = '';
        $saida[] = '== PREMIAÇÕES';

        $premiacoes = $this->premiacoes($lista);

        if ($premiacoes === []) {
            $saida[] = '';
            $saida[] = '(nenhuma credencial ou prêmio)';
        }

        foreach ($premiacoes as $premio) {
            $saida[] = '';
            $saida[] = '== ['.mb_strtoupper($premio['tipo_label']).'] '.mb_strtoupper($premio['nome'])
                .($premio['orgao'] ? ' — '.$premio['orgao'] : '');

            if ($premio['itens'] === []) {
                $saida[] = '';
                $saida[] = '(nenhum projeto contemplado)';
            }

            foreach ($premio['itens'] as $item) {
                $saida[] = '';
                $saida[] = rtrim($this->listasFinais->blocos([$this->paraBloco($item)]));
            }
        }

        return implode("\n", $saida)."\n";
    }

    /**
     * O item no formato que o bloco da lista online espera.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function paraBloco(array $item): array
    {
        return [...$item, 'codigo' => $item['codigo'] ?? ''];
    }

    private function pdf(ListaPresencial $lista): string
    {
        $grupos = collect($this->premiados($lista))
            ->groupBy(fn (array $p) => $p['secao'] === 'nota'
                ? implode(' · ', array_filter([$p['item']['categoria'], $p['item']['area'] ?? 'Sem área']))
                : $p['premiacao'])
            ->map(fn (Collection $g, string $titulo) => ['titulo' => $titulo, 'secao' => $g->first()['secao'], 'premiados' => $g->values()->all()])
            ->values()
            ->all();

        return app(PdfService::class)->render('pdf.lista-presencial', [
            'lista' => $lista,
            'edicao' => $lista->edicao?->nome ?? 'FETECMS',
            'gerado_em' => now()->format('d/m/Y H:i'),
            'grupos' => $grupos,
        ]);
    }

    // --- Universo ---------------------------------------------------------------

    /**
     * Os finalistas da lista final vigente, descritos, com a média presencial
     * de quem já tem uma.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function finalistas(bool $demo): Collection
    {
        $vigente = ListaFinal::vigente(null, $demo);

        if ($vigente === null) {
            return collect();
        }

        $ids = $vigente->projetos()->pluck('projetos.id')->map(fn ($id) => (int) $id)->all();

        return collect($this->descrever($ids, $demo, $vigente));
    }

    /**
     * Os finalistas avaliados, do melhor para o pior — a ordem do ranking
     * presencial (média, depois quem tem mais avaliações, depois o título).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function avaliados(bool $demo): Collection
    {
        return $this->finalistas($demo)
            ->filter(fn (array $p) => $p['media'] !== null)
            ->sortBy([
                fn (array $a, array $b) => $b['media'] <=> $a['media'],
                fn (array $a, array $b) => $b['avaliacoes'] <=> $a['avaliacoes'],
                fn (array $a, array $b) => strcmp($this->chave($a['titulo']), $this->chave($b['titulo'])),
            ])
            ->values();
    }

    /**
     * Projetos por id no formato da lista: equipe, escola, código do finalista,
     * estande e a média presencial.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function descrever(array $ids, bool $demo, ?ListaFinal $vigente = null): array
    {
        $vigente ??= ListaFinal::vigente(null, $demo);
        $codigos = $vigente === null ? [] : ($this->codigos[$vigente->id] ??= $this->listasFinais->codigosDaLista($vigente));
        $medias = $this->ranking->medias($ids);
        $estandes = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)
            ->whereIn('projeto_id', $ids)
            ->get(['projeto_id', 'numero', 'turno'])
            ->keyBy('projeto_id');

        return Projeto::query()
            ->whereIn('id', $ids)
            ->with([
                'area:id,nome,sigla',
                'user:id,name',
                'alunos:id,projeto_id,nome',
                'coorientador:id,projeto_id,nome',
                'instituicao:id,nome,cidade_id',
                'instituicao.cidade:id,nome,estado_id,capital',
                'instituicao.cidade.estado:id,uf',
                'cidade:id,nome,capital',
                'estado:id,uf',
            ])
            ->get()
            ->map(function (Projeto $p) use ($codigos, $medias, $estandes) {
                $media = $medias[$p->id] ?? null;
                $estande = $estandes[$p->id] ?? null;

                return [
                    'projeto_id' => $p->id,
                    'codigo' => $codigos[$p->id] ?? null,
                    'titulo' => $p->titulo,
                    'categoria' => $p->categoria?->label(),
                    'categoria_valor' => $p->categoria?->value,
                    'area' => $p->area?->nome,
                    'area_id' => $p->area_id,
                    'escola' => $this->listasFinais->escola($p),
                    'alunos' => $p->alunos->sortBy(fn ($a) => $this->chave($a->nome))->pluck('nome')->values()->all(),
                    'orientador' => $p->user?->name,
                    'coorientador' => $p->coorientador?->nome,
                    'estande' => $estande?->numero,
                    'turno_label' => $estande?->turno?->label(),
                    'media' => $media['media'] ?? null,
                    'avaliacoes' => $media['avaliacoes'] ?? 0,
                    'parcial' => $media['parcial'] ?? false,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Ordena e dá a colocação de cada projeto no grupo categoria+área, pela
     * média; empate divide o lugar (1º, 2º, 2º, 4º), como no ranking.
     *
     * @param  list<array<string, mixed>>  $itens
     * @return list<array<string, mixed>>
     */
    private function colocar(array $itens): array
    {
        $ordemCategoria = array_flip(array_map(fn (Categoria $c) => $c->value, Categoria::ordemDaLista()));

        usort($itens, fn (array $a, array $b) => [
            $ordemCategoria[$a['categoria_valor']] ?? PHP_INT_MAX,
            $this->chave($a['area'] ?? 'zzz'),
            $a['media'] === null ? 1 : 0,
            -($a['media'] ?? 0),
            -$a['avaliacoes'],
            $this->chave($a['titulo']),
        ] <=> [
            $ordemCategoria[$b['categoria_valor']] ?? PHP_INT_MAX,
            $this->chave($b['area'] ?? 'zzz'),
            $b['media'] === null ? 1 : 0,
            -($b['media'] ?? 0),
            -$b['avaliacoes'],
            $this->chave($b['titulo']),
        ]);

        $grupo = null;
        $posicao = 0;
        $contador = 0;
        $anterior = null;

        foreach ($itens as &$item) {
            $chave = ($item['categoria_valor'] ?? '').'|'.($item['area_id'] ?? 0);

            if ($chave !== $grupo) {
                $grupo = $chave;
                $contador = 0;
                $anterior = null;
            }

            if ($item['media'] === null) {
                $item['colocacao'] = null;

                continue;
            }

            $contador++;
            if ($item['media'] !== $anterior) {
                $posicao = $contador;
                $anterior = $item['media'];
            }
            $item['colocacao'] = $posicao;
        }
        unset($item);

        return $itens;
    }

    /**
     * Escolhe os premiados por nota (Sprint 255). A área corta pela
     * **colocação** no grupo categoria+área, e não pela quantidade: "até o 4º
     * lugar" com dois empatados em 4º leva os dois — cortar um deles deixaria
     * fora da lista um projeto com a mesma colocação de quem entrou. Depois,
     * descendo a classificação geral, valem os tetos opcionais da categoria e
     * do total (número ou porcentagem, como antes).
     *
     * @param  Collection<int, array<string, mixed>>  $avaliados
     * @param  array<string, mixed>  $cotas
     * @return list<array<string, mixed>>
     */
    private function selecionar(Collection $avaliados, array $cotas): array
    {
        $colocacao = collect($this->colocar($avaliados->values()->all()))->pluck('colocacao', 'projeto_id');
        $total = Cota::de($cotas['total'] ?? null)?->resolver($avaliados->count());
        $limiteCategoria = [];

        foreach ((array) ($cotas['categorias'] ?? []) as $categoria => $config) {
            $cotaCategoria = Cota::de(((array) $config)['cota'] ?? null)?->resolver($total ?? $avaliados->count());
            if ($cotaCategoria !== null) {
                $limiteCategoria[$categoria] = $cotaCategoria;
            }
        }

        $usado = ['total' => 0, 'categoria' => []];
        $escolhidos = [];

        foreach ($avaliados as $p) {
            $categoria = (string) $p['categoria_valor'];
            $ateLugar = $this->ateLugar($cotas, $categoria, (int) $p['area_id']);

            if (($ateLugar !== null && $colocacao[$p['projeto_id']] > $ateLugar)
                || ($total !== null && $usado['total'] >= $total)
                || (isset($limiteCategoria[$categoria]) && ($usado['categoria'][$categoria] ?? 0) >= $limiteCategoria[$categoria])) {
                continue;
            }

            $usado['total']++;
            $usado['categoria'][$categoria] = ($usado['categoria'][$categoria] ?? 0) + 1;
            $escolhidos[] = $p;
        }

        return $escolhidos;
    }

    /**
     * Até que lugar a área premia: o que veio no pedido (nulo = sem limite, 0 =
     * área de fora) ou, sem nada informado, o padrão.
     *
     * @param  array<string, mixed>  $cotas
     */
    private function ateLugar(array $cotas, string $categoria, int $areaId): ?int
    {
        $area = (array) ($cotas['categorias'][$categoria]['areas'][$areaId] ?? []);

        if (! array_key_exists('lugares', $area)) {
            return self::LUGARES_PADRAO;
        }

        return $area['lugares'] === null || $area['lugares'] === '' ? null : max(0, (int) $area['lugares']);
    }

    // --- Slides (Sprint 254) -----------------------------------------------------

    /**
     * A apresentação da cerimônia em .pptx: para cada premiação, três slides —
     * o nome do prêmio (ou a colocação), a foto do estande (Cerimonial →
     * Fotografias de estandes) e o projeto como está na lista. Primeiro os
     * premiados por nota, por categoria e área, do último colocado para o
     * primeiro (o suspense do palco); depois as credenciais e os prêmios, na
     * ordem do cadastro.
     *
     * Grava num arquivo temporário e devolve o caminho: com uma foto de vários
     * MB por projeto, o arquivo não cabe na memória.
     *
     * @return array{caminho: string, nome: string}
     */
    public function slides(ListaPresencial $lista): array
    {
        if ($lista->rascunho) {
            throw ValidationException::withMessages(['lista' => 'Gere a lista antes de montar os slides.']);
        }

        $sequencia = $this->sequenciaDosSlides($lista);

        if ($sequencia === []) {
            throw ValidationException::withMessages(['lista' => 'A lista não tem nenhum premiado para os slides.']);
        }

        $fotos = $this->fotos($lista, array_map(fn (array $p) => $p['item']['projeto_id'], $sequencia));
        $evento = $lista->edicao?->nome ?? 'FETECMS';
        $apresentacao = new ApresentacaoPptx($lista->nome);

        foreach ($sequencia as $p) {
            $i = $p['item'];

            if ($p['secao'] === 'nota') {
                $grupo = implode(' · ', array_filter([$i['area'] ?? 'Sem área', $i['categoria']]));
                $apresentacao->titulo(
                    'Premiação por nota',
                    $i['colocacao'] !== null ? $i['colocacao'].'º lugar' : $grupo,
                    $i['colocacao'] !== null ? $grupo : null,
                    $evento,
                );
            } else {
                $apresentacao->titulo($p['tipo_label'], $p['nome'], $p['orgao'], $evento);
            }

            $foto = $fotos[$i['projeto_id']] ?? ['caminho' => null, 'motivo' => 'Sem fotografia registrada'];
            $apresentacao->foto($foto['caminho'], implode(' · ', array_filter([
                $i['estande'] !== null ? 'Estande '.str_pad((string) $i['estande'], 3, '0', STR_PAD_LEFT) : null,
                $i['codigo'],
            ])) ?: $i['titulo'], $foto['motivo'] ?? 'Sem fotografia registrada');

            $apresentacao->projeto($i['codigo'], $i['titulo'], $i['escola'], $this->equipe($i));
        }

        $caminho = tempnam(sys_get_temp_dir(), 'pptx');
        if ($caminho === false) {
            throw new \RuntimeException('Não foi possível criar o arquivo temporário dos slides.');
        }

        $apresentacao->salvar($caminho);

        return ['caminho' => $caminho, 'nome' => 'slides-'.Str::slug($lista->nome).'-v'.$lista->versao.'.pptx'];
    }

    /**
     * A ordem do palco: por nota (categoria → área → do último ao primeiro
     * colocado; quem não tem colocação abre o grupo), depois as premiações.
     *
     * @return list<array{secao: string, item: array<string, mixed>, tipo_label?: string, nome?: string, orgao?: ?string}>
     */
    private function sequenciaDosSlides(ListaPresencial $lista): array
    {
        $porNota = collect($this->itens($lista))
            ->groupBy(fn (array $i) => ($i['categoria_valor'] ?? '').'|'.($i['area_id'] ?? 0), preserveKeys: false)
            ->flatMap(fn (Collection $grupo) => $grupo->sortBy([
                fn (array $a, array $b) => ($b['colocacao'] ?? PHP_INT_MAX) <=> ($a['colocacao'] ?? PHP_INT_MAX),
                fn (array $a, array $b) => strcmp($this->chave($a['titulo']), $this->chave($b['titulo'])),
            ])->values())
            ->map(fn (array $i) => ['secao' => 'nota', 'item' => $i])
            ->values()
            ->all();

        $premiacoes = [];
        foreach ($this->premiacoes($lista) as $premio) {
            foreach ($premio['itens'] as $i) {
                $premiacoes[] = [
                    'secao' => 'premiacao',
                    'item' => $i,
                    'tipo_label' => $premio['tipo_label'],
                    'nome' => $premio['nome'],
                    'orgao' => $premio['orgao'],
                ];
            }
        }

        return [...$porNota, ...$premiacoes];
    }

    /**
     * A foto de cada projeto que serve para os slides, ou o motivo de não
     * servir.
     *
     * @param  list<int>  $projetoIds
     * @return array<int, array{caminho: ?string, motivo: ?string}>
     */
    private function fotos(ListaPresencial $lista, array $projetoIds): array
    {
        $disco = Storage::disk('local');

        return FotografiaEstande::where('edicao_id', $lista->edicao_id)
            ->whereIn('projeto_id', array_unique($projetoIds))
            ->whereNotNull('caminho')
            ->get(['projeto_id', 'caminho', 'mime'])
            ->mapWithKeys(function (FotografiaEstande $f) use ($disco) {
                if (! $disco->exists($f->caminho)) {
                    return [$f->projeto_id => ['caminho' => null, 'motivo' => 'Fotografia não encontrada no servidor']];
                }

                $caminho = $disco->path($f->caminho);

                return [$f->projeto_id => ApresentacaoPptx::imagem($caminho) === null
                    ? ['caminho' => null, 'motivo' => 'Fotografia num formato que o PowerPoint não abre (envie JPG ou PNG)']
                    : ['caminho' => $caminho, 'motivo' => null]];
            })
            ->all();
    }

    /**
     * O que a tela avisa antes de baixar os slides: quantos são e quem vai
     * sair sem foto (e por quê).
     *
     * @param  list<array<string, mixed>>  $itens
     * @param  list<array<string, mixed>>  $premiacoes
     * @return array<string, mixed>
     */
    private function resumoSlides(ListaPresencial $lista, array $itens, array $premiacoes): array
    {
        $projetos = collect($itens)
            ->concat(collect($premiacoes)->flatMap(fn (array $p) => $p['itens']))
            ->keyBy('projeto_id');
        $premiados = count($itens) + collect($premiacoes)->sum(fn (array $p) => count($p['itens']));
        $fotos = $projetos->isEmpty() ? [] : $this->fotos($lista, $projetos->keys()->all());

        return [
            'premiados' => $premiados,
            'slides' => $premiados * 3,
            'sem_foto' => $projetos
                ->filter(fn (array $i) => ($fotos[$i['projeto_id']]['caminho'] ?? null) === null)
                ->map(fn (array $i) => [
                    'projeto_id' => $i['projeto_id'],
                    'codigo' => $i['codigo'],
                    'titulo' => $i['titulo'],
                    'motivo' => $fotos[$i['projeto_id']]['motivo'] ?? 'Sem fotografia registrada',
                ])
                ->sortBy(fn (array $i) => $this->chave($i['titulo']))
                ->values()
                ->all(),
        ];
    }

    /**
     * A equipe como na lista: estudantes, orientador e coorientador.
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function equipe(array $item): array
    {
        return array_values(array_filter([
            ...$item['alunos'],
            $item['orientador'] ? $item['orientador'].' - Orientador(a)' : null,
            $item['coorientador'] ? $item['coorientador'].' - Coorientador(a)' : null,
        ]));
    }

    // --- Apoio ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function resumo(ListaPresencial $l): array
    {
        return [
            'id' => $l->id,
            'nome' => $l->nome,
            'descricao' => $l->descricao,
            'tipo' => $l->tipo,
            'vigente' => $l->vigente,
            'rascunho' => $l->rascunho,
            'demo' => $l->demo,
            'versao' => $l->versao,
            'projetos' => $l->projetos_count ?? $l->projetos()->count(),
            'premiacoes' => $this->contarPremiacoes($l),
            'origens' => $l->origens ?? [],
            'gerada_em' => $l->gerada_em?->toIso8601String(),
        ];
    }

    private function vazia(ListaPresencial $lista): bool
    {
        return $lista->projetos()->count() === 0 && $this->contarPremiacoes($lista) === 0;
    }

    /** Quantas premiações (credencial × projeto) a lista tem. */
    private function contarPremiacoes(ListaPresencial $lista): int
    {
        return DB::table('lista_presencial_premio_projetos')
            ->join('lista_presencial_premios', 'lista_presencial_premios.id', '=', 'lista_presencial_premio_projetos.premio_id')
            ->where('lista_presencial_premios.lista_presencial_id', $lista->id)
            ->count();
    }

    /** Desliga a final ativa da edição, na mesma trilha (oficial ou de teste). */
    private function desativarFinais(ListaPresencial $lista): void
    {
        ListaPresencial::where('edicao_id', $lista->edicao_id)
            ->where('demo', $lista->demo)
            ->where('tipo', ListaPresencial::TIPO_FINAL)
            ->update(['vigente' => false]);
    }

    private function tipo(?string $tipo): string
    {
        return $tipo === ListaPresencial::TIPO_FINAL ? ListaPresencial::TIPO_FINAL : ListaPresencial::TIPO_PRELIMINAR;
    }

    private function nomeOuPadrao(?string $nome, Edicao $edicao, string $tipo): string
    {
        $nome = trim((string) $nome);

        return $nome !== ''
            ? $nome
            : ($tipo === ListaPresencial::TIPO_FINAL ? 'Lista final presencial' : 'Lista preliminar presencial').' · '.$edicao->nome;
    }

    private function textoOuNulo(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }

    private function edicaoOuFalha(): Edicao
    {
        return Edicao::atual() ?? throw ValidationException::withMessages([
            'lista' => 'Nenhuma edição em curso para gerar a lista.',
        ]);
    }

    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
