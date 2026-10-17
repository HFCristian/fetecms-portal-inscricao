<?php

namespace App\Services;

use App\Enums\StatusSuporte;
use App\Enums\TipoInterprete;
use App\Enums\TipoRegistro;
use App\Enums\TipoSuporte;
use App\Enums\Turno;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\Interprete;
use App\Models\InterpreteVinculo;
use App\Models\ListaFinal;
use App\Models\MapaLayout;
use App\Models\Projeto;
use App\Models\SuporteProjeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\EtiquetasPdf;
use App\Support\PlanilhaXlsx;
use App\Support\PlantaEvento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial → **Intérpretes** (Sprints 214–215).
 *
 * O cadastro de quem interpreta no evento — línguas estrangeiras e atendimento
 * a pessoa com deficiência — e **a quem** cada um atende: o projeto inteiro ou
 * um participante dele, em quantos projetos for preciso. O intérprete pode
 * nascer sem vínculo nenhum e ganhá-lo depois.
 *
 * O vínculo é sempre a um **projeto** (o do participante, quando é a uma
 * pessoa), e é por ele que o intérprete chega ao estande e ao turno: o mapa de
 * designação é só um jeito visual de criar o vínculo ao projeto inteiro
 * (`origem = mapa`), e por isso a designação **segue o projeto** quando ele
 * muda de lugar. Tirar a designação no mapa desfaz só o que o mapa criou — o
 * vínculo feito no cadastro continua.
 *
 * Os pedidos de intérprete que os orientadores fazem na aba Suporte no evento
 * (Sprint 162) aparecem aqui enquanto o projeto não tem intérprete, com um
 * atalho para vincular.
 *
 * A chegada no evento é de {@see InterpreteCheckinService}.
 */
class InterpretesService
{
    public function __construct(
        private readonly RegistroAtividadeService $registros,
        private readonly ListaFinalService $listas,
        private readonly EstandesProjetosService $estandes,
    ) {}

    /**
     * A tela do cadastro: os intérpretes, as opções de tipo e os pedidos de
     * intérprete que ainda esperam alguém.
     *
     * @return array<string, mixed>
     */
    public function painel(): array
    {
        $contexto = $this->contexto();

        return [
            'interpretes' => $this->doEdicao()
                ->map(fn (Interprete $i) => $this->linha($i, $contexto))
                ->values()
                ->all(),
            'tipos' => TipoInterprete::opcoes(),
            'pedidos' => $this->pedidosSemInterprete($contexto),
            'tem_lista' => $contexto['lista'] !== null,
        ];
    }

    /**
     * Os finalistas da lista vigente com as pessoas de cada um, para o
     * cadastro escolher o projeto inteiro ou um participante.
     *
     * @return list<array<string, mixed>>
     */
    public function finalistas(string $termo = '', int $limite = 15): array
    {
        $contexto = $this->contexto();
        $lista = $contexto['lista'];

        if ($lista === null) {
            return [];
        }

        $termo = $this->chave($termo);

        return Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
            ->with(['alunos:id,projeto_id,nome', 'coorientador:id,projeto_id,nome', 'user:id,name', 'area:id,nome'])
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'codigo' => $contexto['codigos'][$p->id] ?? null,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'turno' => $contexto['turnos'][$p->id] ?? null,
                'numero' => $contexto['estandes'][$p->id] ?? null,
                'pessoas' => $this->pessoasDo($p),
            ])
            ->filter(function (array $p) use ($termo) {
                if ($termo === '') {
                    return true;
                }

                $alvo = $this->chave($p['titulo'].' '.$p['codigo'].' '.collect($p['pessoas'])->pluck('nome')->implode(' '));

                return str_contains($alvo, $termo);
            })
            ->sortBy(fn (array $p) => $p['codigo'] ?? 'zzz'.$this->chave($p['titulo']))
            ->take($limite)
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(array $dados, User $admin): Interprete
    {
        $edicao = $this->edicaoOuFalha();

        $interprete = DB::transaction(function () use ($edicao, $dados, $admin) {
            $interprete = Interprete::create($this->campos($dados) + [
                'edicao_id' => $edicao->id,
                'criado_por' => $admin->id,
            ]);

            $this->sincronizarVinculos($interprete, $dados['vinculos'] ?? []);

            $this->registros->interprete(TipoRegistro::InterpreteCadastrado, $admin, $this->resumo($interprete));

            return $interprete;
        });

        return $interprete->fresh();
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizar(Interprete $interprete, array $dados, User $admin): Interprete
    {
        $this->daEdicaoOuFalha($interprete);

        DB::transaction(function () use ($interprete, $dados, $admin) {
            $interprete->update($this->campos($dados));
            $this->sincronizarVinculos($interprete, $dados['vinculos'] ?? []);

            $this->registros->interprete(TipoRegistro::InterpreteAlterado, $admin, $this->resumo($interprete->fresh()));
        });

        return $interprete->fresh();
    }

    public function excluir(Interprete $interprete, User $admin): void
    {
        $this->daEdicaoOuFalha($interprete);

        DB::transaction(function () use ($interprete, $admin) {
            $this->registros->interprete(TipoRegistro::InterpreteExcluido, $admin, $this->resumo($interprete));
            $interprete->delete();
        });
    }

    /**
     * Atende um pedido de suporte: vincula o intérprete ao projeto — ou ao
     * estudante, quando o pedido é de um.
     */
    public function atenderPedido(Interprete $interprete, SuporteProjeto $pedido, User $admin): Interprete
    {
        $this->daEdicaoOuFalha($interprete);

        if (! in_array($pedido->tipo, [TipoSuporte::InterpreteLibras, TipoSuporte::InterpreteLingua], true)
            || $pedido->status !== StatusSuporte::Aprovado) {
            throw ValidationException::withMessages([
                'pedido' => 'Só um pedido de intérprete aprovado pode ser atendido por aqui.',
            ]);
        }

        $tipo = $pedido->aluno_id ? InterpreteVinculo::PESSOA_ALUNO : null;
        $alunoId = $pedido->aluno_id ? (int) $pedido->aluno_id : null;

        $existe = $interprete->vinculos()
            ->where('projeto_id', $pedido->projeto_id)
            ->where('pessoa_tipo', $tipo)
            ->where('pessoa_id', $alunoId)
            ->exists();

        if (! $existe) {
            $interprete->vinculos()->create([
                'projeto_id' => $pedido->projeto_id,
                'pessoa_tipo' => $tipo,
                'pessoa_id' => $alunoId,
                'origem' => InterpreteVinculo::ORIGEM_PEDIDO,
            ]);

            $this->registros->interprete(TipoRegistro::InterpreteAlterado, $admin, $this->resumo($interprete->fresh()));
        }

        return $interprete->fresh();
    }

    // --- O mapa de designação ---------------------------------------------

    /**
     * A planta com a ocupação e, para cada intérprete, onde ele está: os
     * estandes dos projetos a que atende, turno a turno.
     *
     * @return array<string, mixed>
     */
    public function mapa(): array
    {
        $edicao = Edicao::atual();
        $layout = $edicao === null ? null : MapaLayout::vigente($edicao);
        $dados = $layout?->dados ?? PlantaEvento::padrao();
        $contexto = $this->contexto();

        return [
            'layout' => $dados,
            'ruas' => PlantaEvento::ruas($dados),
            'ocupacao' => $this->estandes->porEstande(),
            'estandes_gerados' => $edicao?->estandes_gerados_em !== null
                || ($edicao !== null && EstandeProjeto::where('edicao_id', $edicao->id)->exists()),
            'interpretes' => $this->doEdicao()
                ->map(fn (Interprete $i) => $this->linha($i, $contexto))
                ->values()
                ->all(),
        ];
    }

    /**
     * Designa (ou tira) o intérprete no estande de um turno: é o vínculo ao
     * projeto que está ali. Estande vazio não recebe designação — não há quem
     * atender, e a designação segue o projeto, não o número.
     *
     * @return array<string, mixed> a linha do intérprete atualizada
     */
    public function designar(Interprete $interprete, int $numero, Turno $turno, bool $designar, User $admin): array
    {
        $edicao = $this->daEdicaoOuFalha($interprete);

        $estande = EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('turno', $turno->value)
            ->where('numero', $numero)
            ->first();

        if ($estande === null) {
            throw ValidationException::withMessages([
                'numero' => sprintf(
                    'O estande %s está vazio no %s: a designação é ao projeto que apresenta ali.',
                    str_pad((string) $numero, 3, '0', STR_PAD_LEFT),
                    $turno->label(),
                ),
            ]);
        }

        $doMapa = $interprete->vinculos()
            ->where('projeto_id', $estande->projeto_id)
            ->whereNull('pessoa_tipo')
            ->where('origem', InterpreteVinculo::ORIGEM_MAPA);

        if ($designar && ! $doMapa->exists()) {
            $interprete->vinculos()->create([
                'projeto_id' => $estande->projeto_id,
                'origem' => InterpreteVinculo::ORIGEM_MAPA,
            ]);
        }

        if (! $designar) {
            $doMapa->delete();
        }

        return $this->linha($interprete->fresh(), $this->contexto());
    }

    // --- Exportação --------------------------------------------------------

    /**
     * A lista de intérpretes com os códigos, em Excel ou CSV.
     *
     * @return array{conteudo: string, nome: string, tipo: string}
     */
    public function exportar(string $formato): array
    {
        $contexto = $this->contexto();
        $cabecalho = [
            'Nome', 'CPF', 'Data de nascimento', 'Tipos', 'Projetos', 'Participantes', 'Turnos e estandes', 'Código do crachá',
        ];

        $linhas = $this->doEdicao()->map(function (Interprete $i) use ($contexto) {
            $linha = $this->linha($i, $contexto);
            $projetos = collect($linha['vinculos'])->whereNull('pessoa_tipo')
                ->map(fn (array $v) => trim(($v['codigo'] ? $v['codigo'].' - ' : '').$v['projeto_titulo']));
            $pessoas = collect($linha['vinculos'])->whereNotNull('pessoa_tipo')
                ->map(fn (array $v) => $v['pessoa_nome'].' ('.$v['pessoa_papel'].', '.($v['codigo'] ?? $v['projeto_titulo']).')');

            return [
                $i->nome,
                $linha['cpf'],
                $i->data_nascimento?->format('d/m/Y'),
                implode('; ', $linha['tipos_labels']),
                $projetos->implode('; '),
                $pessoas->implode('; '),
                $linha['locais_label'],
                $linha['codigo'],
            ];
        })->all();

        $base = 'interpretes-'.now()->format('Y-m-d');

        if ($formato === 'xlsx') {
            return [
                'conteudo' => PlanilhaXlsx::gerar('Intérpretes', $cabecalho, $linhas, [
                    ['largura' => 32], ['largura' => 16, 'texto' => true], ['largura' => 14], ['largura' => 28],
                    ['largura' => 48], ['largura' => 48], ['largura' => 28], ['largura' => 20, 'texto' => true],
                ]),
                'nome' => $base.'.xlsx',
                'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}");
        fputcsv($saida, $cabecalho, ';', '"', '');
        foreach ($linhas as $linha) {
            fputcsv($saida, array_map(fn ($v) => $v === null ? '' : (string) $v, $linha), ';', '"', '');
        }
        rewind($saida);
        $csv = (string) stream_get_contents($saida);
        fclose($saida);

        return ['conteudo' => $csv, 'nome' => $base.'.csv', 'tipo' => 'text/csv; charset=UTF-8'];
    }

    /**
     * As etiquetas de identificação, no mesmo layout das dos participantes:
     * nome com o papel, o que ele interpreta e onde está, e os dois códigos.
     *
     * @param  list<int>|null  $ids  só estes (null = todos)
     */
    public function etiquetasPdf(?array $ids = null): string
    {
        $etiquetas = $this->etiquetas($ids);

        if ($etiquetas === []) {
            throw ValidationException::withMessages([
                'interpretes' => 'Não há intérprete cadastrado para gerar etiquetas.',
            ]);
        }

        return EtiquetasPdf::gerar(
            'Identificação dos intérpretes',
            (Edicao::atual()?->nome ?? 'FETECMS').' · '.count($etiquetas).' etiqueta(s)',
            $etiquetas,
        );
    }

    /**
     * O conteúdo da etiqueta de cada intérprete da edição — também usado pela
     * etiqueta avulsa do credenciamento (Sprint 218), para a reimpressão sair
     * igual à da folha original.
     *
     * @param  list<int>|null  $ids  só estes (null = todos)
     * @return list<array{papel_label: string, nome: string, codigo: string, turno: string, estande: null, interprete_id: int}>
     */
    public function etiquetas(?array $ids = null): array
    {
        $interpretes = $this->doEdicao()
            ->when($ids !== null, fn (Collection $c) => $c->whereIn('id', $ids));

        if ($interpretes->isEmpty()) {
            return [];
        }

        $contexto = $this->contexto();

        return $interpretes->map(function (Interprete $i) use ($contexto) {
            $linha = $this->linha($i, $contexto);
            $tipos = implode(', ', $linha['tipos_labels']);

            return [
                'papel_label' => 'Intérprete',
                'nome' => $i->nome,
                'codigo' => $linha['codigo'],
                'turno' => implode(' · ', array_filter([$tipos, $linha['locais_label']])),
                'estande' => null,
                'interprete_id' => $i->id,
            ];
        })->values()->all();
    }

    // --- A linha de um intérprete -----------------------------------------

    /**
     * O que a tela e a exportação sabem de um intérprete.
     *
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    public function linha(Interprete $interprete, array $contexto): array
    {
        $interprete->loadMissing('vinculos');

        $vinculos = $interprete->vinculos->map(function (InterpreteVinculo $v) use ($contexto) {
            $projeto = $contexto['projetos'][$v->projeto_id] ?? null;
            $pessoa = $v->pessoa_tipo === null || $projeto === null
                ? null
                : collect($this->pessoasDo($projeto))->first(fn (array $p) => $p['tipo'] === $v->pessoa_tipo && $p['id'] === $v->pessoa_id);

            return [
                'id' => $v->id,
                'projeto_id' => $v->projeto_id,
                'projeto_titulo' => $projeto?->titulo ?? 'Projeto removido',
                'codigo' => $contexto['codigos'][$v->projeto_id] ?? null,
                'na_lista' => in_array($v->projeto_id, $contexto['ids_lista'], true),
                'pessoa_tipo' => $v->pessoa_tipo,
                'pessoa_id' => $v->pessoa_id,
                'pessoa_nome' => $pessoa['nome'] ?? ($v->pessoa_tipo ? 'Pessoa fora da equipe' : null),
                'pessoa_papel' => $pessoa['papel_label'] ?? null,
                'origem' => $v->origem,
                'turno' => $contexto['turnos'][$v->projeto_id] ?? null,
                'numero' => $contexto['estandes'][$v->projeto_id] ?? null,
            ];
        })->values();

        // Onde ele está: um local por projeto, sem repetir, turno A primeiro.
        $locais = $vinculos
            ->filter(fn (array $v) => $v['turno'] !== null)
            ->map(fn (array $v) => ['turno' => $v['turno'], 'numero' => $v['numero']])
            ->unique(fn (array $l) => $l['turno'].'-'.$l['numero'])
            ->sortBy(fn (array $l) => [$l['turno'], $l['numero'] ?? PHP_INT_MAX])
            ->values();

        return [
            'id' => $interprete->id,
            'nome' => $interprete->nome,
            'cpf' => $this->cpf($interprete->cpf),
            'cpf_digitos' => $interprete->cpf,
            'data_nascimento' => $interprete->data_nascimento?->toDateString(),
            'data_nascimento_label' => $interprete->data_nascimento?->format('d/m/Y'),
            'tipos' => array_values($interprete->tipos ?? []),
            'tipos_labels' => $interprete->tiposLabels(),
            'outra_lingua' => $interprete->outra_lingua,
            'outro_pcd' => $interprete->outro_pcd,
            'codigo' => $interprete->codigo(),
            'vinculos' => $vinculos->all(),
            'locais' => $locais->all(),
            'locais_label' => $this->descreverLocais($locais),
        ];
    }

    /**
     * O que várias linhas compartilham, lido uma vez: a lista vigente, os
     * códigos, turnos e estandes, e os projetos dos vínculos com as equipes.
     *
     * @return array<string, mixed>
     */
    public function contexto(): array
    {
        $edicao = Edicao::atual();
        $lista = $edicao === null ? null : ListaFinal::vigente($edicao);
        $idsLista = $lista === null ? [] : $lista->projetos()->pluck('projetos.id')->map(fn ($id) => (int) $id)->all();

        $idsVinculos = $edicao === null ? collect() : InterpreteVinculo::whereHas(
            'interprete',
            fn ($q) => $q->where('edicao_id', $edicao->id),
        )->pluck('projeto_id');

        return [
            'lista' => $lista,
            'ids_lista' => $idsLista,
            'codigos' => $lista === null ? [] : $this->listas->codigosDaLista($lista),
            'turnos' => $edicao === null ? [] : TurnoApresentacao::where('edicao_id', $edicao->id)
                ->get(['projeto_id', 'turno'])
                ->mapWithKeys(fn (TurnoApresentacao $t) => [$t->projeto_id => $t->turno->value])
                ->all(),
            'estandes' => $edicao === null ? [] : EstandeProjeto::where('edicao_id', $edicao->id)->pluck('numero', 'projeto_id')->all(),
            'projetos' => Projeto::whereIn('id', $idsVinculos->unique())
                ->with(['alunos:id,projeto_id,nome', 'coorientador:id,projeto_id,nome', 'user:id,name'])
                ->get()
                ->keyBy('id')
                ->all(),
        ];
    }

    // --- Apoio -------------------------------------------------------------

    /** @return Collection<int, Interprete> */
    private function doEdicao(): Collection
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return collect();
        }

        return Interprete::where('edicao_id', $edicao->id)
            ->with(['vinculos', 'edicao'])
            ->get()
            ->sortBy(fn (Interprete $i) => $this->chave($i->nome))
            ->values();
    }

    /**
     * Os pedidos de intérprete aprovados de projetos da lista vigente que ainda
     * não têm intérprete — nem no projeto inteiro, nem no estudante do pedido.
     *
     * @param  array<string, mixed>  $contexto
     * @return list<array<string, mixed>>
     */
    private function pedidosSemInterprete(array $contexto): array
    {
        if ($contexto['lista'] === null) {
            return [];
        }

        $pedidos = SuporteProjeto::whereIn('tipo', [TipoSuporte::InterpreteLibras->value, TipoSuporte::InterpreteLingua->value])
            ->where('status', StatusSuporte::Aprovado->value)
            ->whereIn('projeto_id', $contexto['ids_lista'])
            ->with(['projeto:id,titulo', 'aluno:id,nome'])
            ->get();

        if ($pedidos->isEmpty()) {
            return [];
        }

        $edicao = Edicao::atual();
        $vinculos = InterpreteVinculo::whereIn('projeto_id', $pedidos->pluck('projeto_id'))
            ->whereHas('interprete', fn ($q) => $q->where('edicao_id', $edicao?->id))
            ->get();

        return $pedidos
            ->reject(fn (SuporteProjeto $p) => $vinculos->contains(
                fn (InterpreteVinculo $v) => $v->projeto_id === $p->projeto_id
                    && ($v->pessoa_tipo === null || ($p->aluno_id && $v->pessoa_tipo === InterpreteVinculo::PESSOA_ALUNO && $v->pessoa_id === (int) $p->aluno_id)),
            ))
            ->map(fn (SuporteProjeto $p) => [
                'id' => $p->id,
                'tipo' => $p->tipo->value,
                'tipo_label' => $p->tipo->label(),
                'idioma' => $p->idioma,
                'observacao' => $p->observacao,
                'projeto_id' => $p->projeto_id,
                'projeto_titulo' => $p->projeto?->titulo,
                'codigo' => $contexto['codigos'][$p->projeto_id] ?? null,
                'aluno' => $p->aluno?->nome,
                'turno' => $contexto['turnos'][$p->projeto_id] ?? null,
                'numero' => $contexto['estandes'][$p->projeto_id] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Grava a lista de vínculos que veio da tela: o que já existia fica (com a
     * origem de sempre — a designação do mapa continua sendo do mapa), o que
     * saiu é apagado e o que é novo nasce do cadastro. Vínculo novo só a
     * projeto da lista final vigente, e a pessoa tem de ser daquele projeto.
     *
     * @param  list<array<string, mixed>>  $entradas
     */
    private function sincronizarVinculos(Interprete $interprete, array $entradas): void
    {
        $chave = fn ($projeto, $tipo, $pessoa) => $projeto.'|'.($tipo ?? '').'|'.($tipo === null ? '' : (int) $pessoa);

        $desejados = [];

        foreach ($entradas as $e) {
            $tipo = ($e['pessoa_tipo'] ?? null) ?: null;
            $desejados[$chave((int) $e['projeto_id'], $tipo, $e['pessoa_id'] ?? null)] = [
                'projeto_id' => (int) $e['projeto_id'],
                'pessoa_tipo' => $tipo,
                'pessoa_id' => $tipo === null ? null : (int) ($e['pessoa_id'] ?? 0),
            ];
        }

        $atuais = $interprete->vinculos()->get()
            ->keyBy(fn (InterpreteVinculo $v) => $chave($v->projeto_id, $v->pessoa_tipo, $v->pessoa_id));

        foreach ($atuais as $k => $vinculo) {
            if (! isset($desejados[$k])) {
                $vinculo->delete();
            }
        }

        $novos = array_diff_key($desejados, $atuais->all());

        if ($novos === []) {
            return;
        }

        $lista = ListaFinal::vigente();
        $idsLista = $lista === null ? [] : $lista->projetos()->pluck('projetos.id')->map(fn ($id) => (int) $id)->all();
        $projetos = Projeto::whereIn('id', array_column($novos, 'projeto_id'))
            ->with(['alunos:id,projeto_id,nome', 'coorientador:id,projeto_id,nome', 'user:id,name'])
            ->get()
            ->keyBy('id');

        foreach ($novos as $novo) {
            $projeto = $projetos->get($novo['projeto_id']);

            if ($projeto === null || ! in_array($projeto->id, $idsLista, true)) {
                throw ValidationException::withMessages([
                    'vinculos' => 'Só é possível vincular a projetos da lista final vigente.',
                ]);
            }

            if ($novo['pessoa_tipo'] !== null) {
                $existe = collect($this->pessoasDo($projeto))
                    ->contains(fn (array $p) => $p['tipo'] === $novo['pessoa_tipo'] && $p['id'] === $novo['pessoa_id']);

                if (! $existe) {
                    throw ValidationException::withMessages([
                        'vinculos' => sprintf('A pessoa escolhida não é da equipe de "%s".', $projeto->titulo),
                    ]);
                }
            }

            $interprete->vinculos()->create($novo + ['origem' => InterpreteVinculo::ORIGEM_CADASTRO]);
        }
    }

    /**
     * As pessoas da equipe, no formato do vínculo.
     *
     * @return list<array{tipo: string, id: int, nome: string, papel_label: string}>
     */
    private function pessoasDo(Projeto $projeto): array
    {
        $pessoas = [];

        foreach ($projeto->alunos->sortBy('nome') as $aluno) {
            $pessoas[] = ['tipo' => InterpreteVinculo::PESSOA_ALUNO, 'id' => $aluno->id, 'nome' => $aluno->nome, 'papel_label' => 'Estudante'];
        }

        if ($projeto->user !== null) {
            $pessoas[] = ['tipo' => InterpreteVinculo::PESSOA_ORIENTADOR, 'id' => $projeto->user->id, 'nome' => $projeto->user->name, 'papel_label' => 'Orientador(a)'];
        }

        if ($projeto->coorientador !== null) {
            $pessoas[] = ['tipo' => InterpreteVinculo::PESSOA_COORIENTADOR, 'id' => $projeto->coorientador->id, 'nome' => $projeto->coorientador->nome, 'papel_label' => 'Coorientador(a)'];
        }

        return $pessoas;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function campos(array $dados): array
    {
        $tipos = array_values(array_unique($dados['tipos'] ?? []));

        return [
            'nome' => trim((string) $dados['nome']),
            'cpf' => preg_replace('/\D/', '', (string) $dados['cpf']),
            'data_nascimento' => $dados['data_nascimento'] ?? null,
            'tipos' => $tipos,
            'outra_lingua' => in_array(TipoInterprete::LinguaOutra->value, $tipos, true) ? (trim((string) ($dados['outra_lingua'] ?? '')) ?: null) : null,
            'outro_pcd' => in_array(TipoInterprete::PcdOutro->value, $tipos, true) ? (trim((string) ($dados['outro_pcd'] ?? '')) ?: null) : null,
        ];
    }

    /** "Turno A · 012, 045 / Turno B · 003" — onde ele atende. */
    private function descreverLocais(Collection $locais): string
    {
        return $locais->groupBy('turno')
            ->map(function (Collection $doTurno, string $turno) {
                $numeros = $doTurno->pluck('numero')->filter()->map(fn ($n) => str_pad((string) $n, 3, '0', STR_PAD_LEFT));

                return 'Turno '.$turno.($numeros->isEmpty() ? '' : ' · Estande '.$numeros->implode(', '));
            })
            ->values()
            ->implode(' / ');
    }

    private function resumo(Interprete $interprete): string
    {
        $tipos = $interprete->tiposLabels();
        $vinculos = $interprete->vinculos()->count();

        return sprintf(
            '%s%s · %d vínculo(s)',
            $interprete->nome,
            $tipos === [] ? '' : ' ('.implode(', ', $tipos).')',
            $vinculos,
        );
    }

    private function cpf(?string $cpf): ?string
    {
        $d = preg_replace('/\D/', '', (string) $cpf);

        return strlen((string) $d) === 11
            ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d)
            : $cpf;
    }

    private function daEdicaoOuFalha(Interprete $interprete): Edicao
    {
        $edicao = $this->edicaoOuFalha();

        if ($interprete->edicao_id !== $edicao->id) {
            abort(404);
        }

        return $edicao;
    }

    private function edicaoOuFalha(): Edicao
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'edicao' => 'Nenhuma edição em escopo. Crie a edição da feira antes.',
            ]);
        }

        return $edicao;
    }

    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
