<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\StatusRecomendacao;
use App\Enums\TipoCredencial;
use App\Models\Area;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RecomendacaoPremiacao;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial → **Credenciais e Prêmios**.
 *
 * Credencial é a **vaga de premiação** que a feira tem para dar: a indicação a
 * uma feira nacional ou internacional, a bolsa de um parceiro, o prêmio de um
 * órgão. São poucas, têm dono e, no fim do evento, a organização precisa dizer
 * projeto a projeto quem recebeu o quê — hoje isso vive numa planilha que
 * ninguém audita.
 *
 * **Prêmio** cadastra-se do mesmo jeito e entra na mesma lista: o destaque, a
 * menção honrosa, o reconhecimento que se anuncia no palco sem entregar
 * crachá nenhum. A diferença é o `tipo` (`App\Enums\TipoCredencial`), e ela
 * importa num lugar só: no cerimonial, o card de **credenciais a separar**
 * conta o que tem objeto na mesa — as credenciais —, enquanto **premiado** (e
 * portanto a medalha) é quem recebeu qualquer uma das duas.
 *
 * Três coisas acontecem aqui: **cadastrar** o que existe para dar, **anexar**
 * cada credencial a um projeto e **gerar a lista de premiação**.
 *
 * Só **finalista** recebe credencial — é quem esteve na feira. E o teto de
 * vagas é conferido na atribuição: uma credencial com 3 vagas não vira 4 por
 * distração, mas uma **sem** número de vagas não trava nada, porque nem todo
 * órgão fecha o número antes do evento.
 */
class CredenciaisService
{
    /** @var array<int, string>|null os códigos da lista final vigente, por projeto */
    private ?array $codigos = null;

    public function __construct(private readonly ListaFinalService $listas) {}

    /**
     * As credenciais da edição, com quem já as recebeu. `$soAtivas` é o recorte
     * do comitê (Sprint 190), que atribui mas não cadastra: a desativada não
     * pode mais ser dada, então só atrapalharia a tela dele.
     *
     * Cada cartão traz também as **recomendações do comitê** para ela
     * (Sprint 201): as pendentes para decidir, as aprovadas para premiar e as
     * rejeitadas para reconsiderar. Os `$filtros` (título ou código do
     * projeto, área, categoria) recortam só as recomendações — o cartão e quem
     * já recebeu a credencial aparecem sempre.
     *
     * @param  array{q?: ?string, area_id?: ?int, categoria?: ?string}  $filtros
     * @return list<array<string, mixed>>
     */
    public function listar(bool $soAtivas = false, array $filtros = []): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        $credenciais = Credencial::where('edicao_id', $edicao->id)
            ->when($soAtivas, fn ($q) => $q->where('ativa', true))
            ->with(['projetos:id,titulo,categoria,area_id', 'projetos.area:id,nome'])
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get();

        $recomendacoes = $this->recomendacoesPorCredencial($credenciais->pluck('id')->all(), $filtros);

        return $credenciais
            ->map(fn (Credencial $c) => $this->resumo($c, $recomendacoes->get($c->id, collect())))
            ->all();
    }

    /**
     * O que os filtros das recomendações oferecem e quantas pendentes há no
     * recorte — a barra no topo da tela.
     *
     * @param  list<array<string, mixed>>  $cartoes  o que {@see self::listar()} devolveu
     * @return array<string, mixed>
     */
    public function resumoDasRecomendacoes(array $cartoes): array
    {
        return [
            'pendentes' => array_sum(array_map(fn (array $c) => count($c['pendentes']), $cartoes)),
            'opcoes' => [
                'areas' => Area::orderBy('nome')->get(['id', 'nome'])->toArray(),
                'categorias' => Categoria::opcoes(),
            ],
        ];
    }

    /**
     * O código do projeto na lista final vigente (FET.AGR-001), por id. Lido
     * uma vez por requisição: monta a lista inteira para numerar.
     *
     * @return array<int, string>
     */
    public function codigos(): array
    {
        if ($this->codigos === null) {
            $lista = ListaFinal::vigente();
            $this->codigos = $lista === null ? [] : $this->listas->codigosDaLista($lista);
        }

        return $this->codigos;
    }

    /**
     * Os projetos que podem receber credencial: os finalistas da lista
     * vigente, com o que cada um já recebeu.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatos(): array
    {
        $lista = ListaFinal::vigente();

        if ($lista === null) {
            return [];
        }

        $recebidas = DB::table('credencial_projeto')
            ->join('credenciais', 'credenciais.id', '=', 'credencial_projeto.credencial_id')
            ->select('credencial_projeto.projeto_id', 'credenciais.nome')
            ->get()
            ->groupBy('projeto_id');

        return Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
            ->with(['area:id,nome', 'instituicao:id,nome'])
            ->orderBy('titulo')
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                // O código da lista final (Sprint 203): é por ele que a
                // organização procura o projeto no evento.
                'codigo' => $this->codigos()[$p->id] ?? null,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'escola' => $p->instituicao?->nome,
                'credenciais' => $recebidas->get($p->id, collect())->pluck('nome')->all(),
            ])
            ->all();
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(array $dados): Credencial
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'nome' => 'Nenhuma edição em curso para cadastrar credenciais.',
            ]);
        }

        $nome = trim($dados['nome']);
        $this->garantirNomeLivre($edicao->id, $nome);

        return $this->gravandoNome($edicao->id, $nome, fn () => Credencial::create([
            'edicao_id' => $edicao->id,
            // Sem tipo, credencial: era o único que existia antes dos prêmios.
            'tipo' => TipoCredencial::tryFrom((string) ($dados['tipo'] ?? '')) ?? TipoCredencial::Credencial,
            'nome' => $nome,
            'orgao' => $dados['orgao'] ?? null,
            'descricao' => $dados['descricao'] ?? null,
            'informacoes' => $this->texto($dados['informacoes'] ?? null),
            'vagas' => $dados['vagas'] ?? null,
            'ordem' => (int) ($dados['ordem'] ?? 0),
            'ativa' => $dados['ativa'] ?? true,
        ]));
    }

    /**
     * Edita o item inteiro: tipo, nome, órgão, descrição, informações e
     * vagas. As vagas podem subir à vontade e descer até o número de projetos
     * que já receberam o item — abaixo disso, uma premiação dada sumiria da
     * conta. Em branco é sem teto, que cabe qualquer número.
     *
     * @param  array<string, mixed>  $dados
     */
    public function atualizar(Credencial $credencial, array $dados): Credencial
    {
        $atribuidas = $credencial->projetos()->count();

        if (array_key_exists('vagas', $dados) && $dados['vagas'] !== null && $dados['vagas'] < $atribuidas) {
            throw ValidationException::withMessages([
                'vagas' => "Este item já foi dado a {$atribuidas} projeto(s), então as vagas não podem ser menos que {$atribuidas}. Retire um projeto antes de reduzir.",
            ]);
        }

        if (array_key_exists('nome', $dados)) {
            $dados['nome'] = trim((string) $dados['nome']);

            // Só confere quando o nome muda: dois itens antigos que diferem
            // só na caixa ("Mostratec" e "MOSTRATEC") continuam editáveis.
            if ($this->chave($dados['nome']) !== $this->chave($credencial->nome)) {
                $this->garantirNomeLivre($credencial->edicao_id, $dados['nome'], $credencial->id);
            }
        }

        if (array_key_exists('informacoes', $dados)) {
            $dados['informacoes'] = $this->texto($dados['informacoes']);
        }

        $credencial->fill(array_intersect_key($dados, array_flip([
            'tipo', 'nome', 'orgao', 'descricao', 'informacoes', 'vagas', 'ordem', 'ativa',
        ])));

        $this->gravandoNome($credencial->edicao_id, $credencial->nome, fn () => $credencial->save());

        return $credencial->refresh();
    }

    /**
     * O nome é único na edição (índice `edicao_id` + `nome`), e é por ele que
     * a organização acha o item no cartão, na lista da cerimônia e nos slides.
     * Repetido, o banco recusava e a tela só dizia "erro interno"; agora a
     * recusa diz qual item já usa o nome — inclusive o desativado, que some da
     * tela do comitê mas continua ocupando o nome. A comparação ignora caixa,
     * acento e espaços nas pontas, para "Mostratec 2027" não nascer ao lado
     * de "MOSTRATEC 2027".
     */
    private function garantirNomeLivre(int $edicaoId, string $nome, ?int $exceto = null): void
    {
        $existente = Credencial::where('edicao_id', $edicaoId)
            ->when($exceto, fn ($q) => $q->whereKeyNot($exceto))
            ->get(['id', 'tipo', 'nome', 'ativa'])
            ->first(fn (Credencial $c) => $this->chave($c->nome) === $this->chave($nome));

        if ($existente !== null) {
            throw ValidationException::withMessages(['nome' => $this->mensagemNomeRepetido($existente)]);
        }
    }

    /**
     * Grava e, se outro cadastro com o mesmo nome entrou entre a conferência e
     * a gravação, devolve a mesma recusa em vez do erro do banco.
     *
     * @template T
     *
     * @param  callable(): T  $gravar
     * @return T
     */
    private function gravandoNome(int $edicaoId, string $nome, callable $gravar): mixed
    {
        try {
            return $gravar();
        } catch (UniqueConstraintViolationException $e) {
            $existente = Credencial::where('edicao_id', $edicaoId)->where('nome', $nome)->first();

            throw ValidationException::withMessages([
                'nome' => $existente !== null
                    ? $this->mensagemNomeRepetido($existente)
                    : 'Já existe um item com este nome nesta edição. Escolha outro nome.',
            ]);
        }
    }

    private function mensagemNomeRepetido(Credencial $existente): string
    {
        $premio = $existente->tipo === TipoCredencial::Premio;
        $item = $premio ? 'um prêmio chamado' : 'uma credencial chamada';
        $situacao = $existente->ativa ? '' : ($premio ? ' (desativado)' : ' (desativada)');

        return "Já existe {$item} “{$existente->nome}”{$situacao} nesta edição. Escolha outro nome ou edite o item existente.";
    }

    public function excluir(Credencial $credencial): void
    {
        if ($credencial->projetos()->exists()) {
            throw ValidationException::withMessages([
                'credencial' => 'Esta credencial já foi dada a algum projeto — retire as atribuições antes de excluí-la.',
            ]);
        }

        $credencial->delete();
    }

    /**
     * Anexa a credencial a um projeto finalista.
     */
    public function atribuir(Credencial $credencial, Projeto $projeto, User $admin, ?string $observacao = null): Credencial
    {
        $this->garantirFinalista($projeto);

        if (! $credencial->ativa) {
            throw ValidationException::withMessages([
                'credencial' => 'Esta credencial está desativada.',
            ]);
        }

        if ($credencial->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto já recebeu esta credencial.',
            ]);
        }

        if (! $credencial->temVaga()) {
            throw ValidationException::withMessages([
                'projeto_id' => "As {$credencial->vagas} vaga(s) desta credencial já foram distribuídas.",
            ]);
        }

        $credencial->projetos()->attach($projeto->id, [
            'atribuida_por' => $admin->id,
            'atribuida_em' => now(),
            'observacao' => $observacao,
        ]);

        return $credencial->refresh();
    }

    public function retirar(Credencial $credencial, Projeto $projeto): Credencial
    {
        $credencial->projetos()->detach($projeto->id);

        return $credencial->refresh();
    }

    /**
     * A **lista de premiação** em TXT: uma seção por credencial, com os
     * projetos que a receberam.
     *
     * Sai no mesmo espírito da lista final — legível em voz alta na cerimônia,
     * com escola, alunos e orientador de cada projeto.
     */
    public function exportarTxt(): string
    {
        $edicao = Edicao::atual();
        $blocos = [];

        foreach ($this->credenciaisComProjetos() as $credencial) {
            $linhas = [
                '== ['.mb_strtoupper($credencial->tipo->label()).'] '
                    .mb_strtoupper($credencial->nome)
                    .($credencial->orgao ? ' — '.$credencial->orgao : ''),
            ];

            if ($credencial->projetos->isEmpty()) {
                $linhas[] = '(nenhum projeto contemplado)';
            }

            foreach ($credencial->projetos->sortBy(fn (Projeto $p) => $this->chave($p->titulo)) as $projeto) {
                $linhas[] = '';
                $linhas[] = $projeto->titulo;
                $linhas[] = $this->escola($projeto);

                foreach ($projeto->alunos->sortBy(fn ($a) => $this->chave($a->nome)) as $aluno) {
                    $linhas[] = $aluno->nome;
                }

                if ($projeto->user?->name) {
                    $linhas[] = $projeto->user->name.' - Orientador(a)';
                }

                if ($projeto->coorientador?->nome) {
                    $linhas[] = $projeto->coorientador->nome.' - Coorientador(a)';
                }
            }

            $blocos[] = implode("\n", $linhas);
        }

        $cabecalho = 'LISTA DE PREMIAÇÃO'.($edicao ? ' · '.$edicao->nome : '');

        return $cabecalho."\n\n".implode("\n\n", $blocos)."\n";
    }

    /**
     * A lista de premiação para a tela: uma entrada por credencial, com os
     * projetos e as pessoas de cada um.
     *
     * @return list<array<string, mixed>>
     */
    public function premiacao(): array
    {
        return $this->credenciaisComProjetos()
            ->map(fn (Credencial $c) => [
                'id' => $c->id,
                'tipo' => $c->tipo->value,
                'tipo_label' => $c->tipo->label(),
                'nome' => $c->nome,
                'orgao' => $c->orgao,
                'vagas' => $c->vagas,
                'projetos' => $c->projetos
                    ->sortBy(fn (Projeto $p) => $this->chave($p->titulo))
                    ->values()
                    ->map(fn (Projeto $p) => [
                        'id' => $p->id,
                        'titulo' => $p->titulo,
                        'categoria' => $p->categoria?->label(),
                        'area' => $p->area?->nome,
                        'escola' => $this->escola($p),
                        'alunos' => $p->alunos->sortBy(fn ($a) => $this->chave($a->nome))->pluck('nome')->values()->all(),
                        'orientador' => $p->user?->name,
                        'coorientador' => $p->coorientador?->nome,
                    ])->all(),
            ])
            ->all();
    }

    /** @return Collection<int, Credencial> */
    private function credenciaisComProjetos()
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return collect();
        }

        return Credencial::where('edicao_id', $edicao->id)
            ->where('ativa', true)
            ->with([
                'projetos.area:id,nome',
                'projetos.user:id,name',
                'projetos.alunos:id,projeto_id,nome',
                'projetos.coorientador:id,projeto_id,nome',
                'projetos.instituicao:id,nome,cidade_id',
                'projetos.instituicao.cidade:id,nome,estado_id',
                'projetos.instituicao.cidade.estado:id,uf',
            ])
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get();
    }

    /**
     * As recomendações do comitê por credencial, no recorte dos filtros.
     *
     * @param  list<int>  $credencialIds
     * @param  array{q?: ?string, area_id?: ?int, categoria?: ?string}  $filtros
     * @return Collection<int, Collection<int, RecomendacaoPremiacao>>
     */
    private function recomendacoesPorCredencial(array $credencialIds, array $filtros): Collection
    {
        if ($credencialIds === []) {
            return collect();
        }

        $busca = Str::lower(trim((string) ($filtros['q'] ?? '')));
        $areaId = $filtros['area_id'] ?? null;
        $categoria = $filtros['categoria'] ?? null;

        // O código não é coluna do projeto: os ids cujo código casa com a
        // busca entram pelo `orWhereIn`, ao lado do título.
        $porCodigo = $busca === '' ? [] : array_keys(array_filter(
            $this->codigos(),
            fn (string $codigo) => str_contains(Str::lower($codigo), $busca),
        ));

        return RecomendacaoPremiacao::whereIn('credencial_id', $credencialIds)
            ->when($busca !== '' || $areaId || $categoria, fn ($q) => $q->whereHas('projeto', fn ($p) => $p
                ->when($busca !== '', fn ($sub) => $sub->where(fn ($w) => $w
                    ->whereRaw('LOWER(titulo) LIKE ?', ['%'.$busca.'%'])
                    ->orWhereIn('id', $porCodigo)))
                ->when($areaId, fn ($sub) => $sub->where('area_id', $areaId))
                ->when($categoria, fn ($sub) => $sub->where('categoria', $categoria))))
            ->with(['avaliador:id,name', 'decididaPor:id,name', 'projeto:id,titulo,categoria,area_id', 'projeto.area:id,nome'])
            ->orderBy('id')
            ->get()
            ->groupBy('credencial_id');
    }

    /** @return array<string, mixed> */
    private function linhaDaRecomendacao(RecomendacaoPremiacao $r): array
    {
        return [
            'id' => $r->id,
            'projeto_id' => $r->projeto_id,
            'codigo' => $this->codigos()[$r->projeto_id] ?? null,
            'projeto' => $r->projeto?->titulo,
            'categoria' => $r->projeto?->categoria?->label(),
            'area' => $r->projeto?->area?->nome,
            'avaliador' => $r->avaliador?->name,
            'justificativa' => $r->justificativa,
            'criada_em' => $r->created_at?->toIso8601String(),
            'decidida_por' => $r->decididaPor?->name,
            'decidida_em' => $r->decidida_em?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, RecomendacaoPremiacao>  $recomendacoes
     * @return array<string, mixed>
     */
    private function resumo(Credencial $credencial, ?Collection $recomendacoes = null): array
    {
        $usadas = $credencial->projetos->count();
        $premiados = $credencial->projetos->pluck('id')->flip();
        $porSituacao = ($recomendacoes ?? collect())->groupBy(fn (RecomendacaoPremiacao $r) => $r->status->value);

        // Aprovar só marca: o cartão mostra as aprovadas para o admin anexar
        // com um clique, e diz quais já foram anexadas — uma linha por
        // projeto, com todos que o recomendaram.
        $linhasAprovadas = $porSituacao->get(StatusRecomendacao::Aprovada->value, collect())
            ->groupBy('projeto_id')
            ->map(fn (Collection $doProjeto) => [
                'projeto_id' => $doProjeto->first()->projeto_id,
                'codigo' => $this->codigos()[$doProjeto->first()->projeto_id] ?? null,
                'projeto' => $doProjeto->first()->projeto?->titulo,
                'categoria' => $doProjeto->first()->projeto?->categoria?->label(),
                'area' => $doProjeto->first()->projeto?->area?->nome,
                'avaliadores' => $doProjeto->map(fn (RecomendacaoPremiacao $r) => $r->avaliador?->name)->filter()->values()->all(),
                'recomendacao_ids' => $doProjeto->pluck('id')->values()->all(),
                'ja_premiado' => $premiados->has($doProjeto->first()->projeto_id),
            ])
            ->values();

        return [
            'id' => $credencial->id,
            'tipo' => $credencial->tipo->value,
            'tipo_label' => $credencial->tipo->label(),
            'nome' => $credencial->nome,
            'orgao' => $credencial->orgao,
            'descricao' => $credencial->descricao,
            // Texto longo da organização (Sprint 192), lido também pelo comitê.
            'informacoes' => $credencial->informacoes,
            'vagas' => $credencial->vagas,
            'usadas' => $usadas,
            // Nulo = sem teto: a tela mostra "—" em vez de um número inventado.
            'disponiveis' => $credencial->vagas === null ? null : max(0, $credencial->vagas - $usadas),
            'ativa' => $credencial->ativa,
            'ordem' => $credencial->ordem,
            // As recomendações do comitê (Sprints 195 e 201).
            'pendentes' => $porSituacao->get(StatusRecomendacao::Pendente->value, collect())
                ->map(fn (RecomendacaoPremiacao $r) => $this->linhaDaRecomendacao($r))->values()->all(),
            'aprovadas' => $linhasAprovadas->all(),
            'aprovadas_pendentes' => $linhasAprovadas->where('ja_premiado', false)->count(),
            'rejeitadas' => $porSituacao->get(StatusRecomendacao::Rejeitada->value, collect())
                ->map(fn (RecomendacaoPremiacao $r) => $this->linhaDaRecomendacao($r))->values()->all(),
            'projetos' => $credencial->projetos->map(fn (Projeto $p) => [
                'id' => $p->id,
                'codigo' => $this->codigos()[$p->id] ?? null,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'observacao' => $p->pivot->observacao,
                'atribuida_em' => $p->pivot->atribuida_em,
            ])->all(),
        ];
    }

    public function garantirFinalista(Projeto $projeto): void
    {
        $lista = ListaFinal::vigente();

        if ($lista === null || ! $lista->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Só projetos finalistas recebem credencial ou prêmio.',
            ]);
        }
    }

    /** "Escola / Cidade - UF", como na lista final. */
    private function escola(Projeto $projeto): string
    {
        $instituicao = $projeto->instituicao;
        $local = trim(implode(' - ', array_filter([
            $instituicao?->cidade?->nome,
            $instituicao?->cidade?->estado?->uf,
        ])));

        $partes = array_filter([$instituicao?->nome, $local === '' ? null : $local]);

        return $partes === [] ? '—' : implode(' / ', $partes);
    }

    /** Texto livre aparado; em branco vira nulo. */
    private function texto(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }

    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
