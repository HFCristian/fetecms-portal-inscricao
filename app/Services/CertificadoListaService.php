<?php

namespace App\Services;

use App\Enums\AbaAdmin;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\TipoPessoaCredenciamento;
use App\Models\AvaliadorProfile;
use App\Models\CertificadoLinha;
use App\Models\CertificadoLista;
use App\Models\ContaTemporaria;
use App\Models\CredenciamentoPessoa;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\PlanilhaXlsx;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Listas de certificados (Sprints 259–260): o assistente da aba Certificados.
 *
 * O admin escolhe as **fases** (online, presencial ou as duas), o **público** e,
 * para cada categoria que resulta disso, a **carga horária** e o período. A
 * categoria é o par público × fase: orientador da fase online e orientador da
 * fase presencial são certificados diferentes, com cargas diferentes.
 *
 * Quem entra em cada categoria:
 *
 * - **Fase online**: a equipe de todo projeto submetido pela inscrição (o
 *   cadastro manual não passou pela fase online) e o avaliador com avaliação
 *   online concluída.
 * - **Fase presencial**: só quem o balcão do credenciamento marcou como
 *   presente num credenciamento concluído — quem não foi marcado veio, como no
 *   balcão —, o avaliador com avaliação no estande concluída e os voluntários.
 * - **Admins**: a organização, sem fase.
 *
 * Um certificado por projeto: o orientador de três projetos recebe três. O
 * avaliador recebe um por fase, com a quantidade de projetos avaliados.
 *
 * A carga é **fixa** ou **por unidade** — por projeto avaliado (avaliadores) ou
 * por turno (voluntários; a conta sem turnos vale um, a janela de acesso) —,
 * com um **teto** opcional para quem avaliou muito. O arquivo leva horas
 * inteiras, arredondadas para cima.
 *
 * Gerar congela o resultado em `certificado_linhas`; antes disso, tudo é
 * calculado na hora, com as correções de nome da lista por cima.
 */
class CertificadoListaService
{
    public const FASES = ['online' => 'Fase online', 'presencial' => 'Fase presencial'];

    public const MODO_FIXA = 'fixa';

    public const MODO_POR_PROJETO = 'por_projeto';

    public const MODO_POR_TURNO = 'por_turno';

    /**
     * O público-alvo: em que fases ele existe (nulo = não depende de fase) e
     * como a carga pode ser calculada.
     */
    public const PUBLICOS = [
        'avaliadores_online' => ['rotulo' => 'Avaliadores Online', 'fases' => ['online'], 'modos' => [self::MODO_FIXA, self::MODO_POR_PROJETO]],
        'avaliadores_presencial' => ['rotulo' => 'Avaliadores Presencial', 'fases' => ['presencial'], 'modos' => [self::MODO_FIXA, self::MODO_POR_PROJETO]],
        'orientadores' => ['rotulo' => 'Orientadores', 'fases' => ['online', 'presencial'], 'modos' => [self::MODO_FIXA]],
        'coorientadores' => ['rotulo' => 'Coorientadores', 'fases' => ['online', 'presencial'], 'modos' => [self::MODO_FIXA]],
        'estudantes' => ['rotulo' => 'Estudantes', 'fases' => ['online', 'presencial'], 'modos' => [self::MODO_FIXA]],
        'voluntarios' => ['rotulo' => 'Voluntários Cadastrados', 'fases' => ['presencial'], 'modos' => [self::MODO_FIXA, self::MODO_POR_TURNO]],
        'admins' => ['rotulo' => 'Admins Cadastrados', 'fases' => null, 'modos' => [self::MODO_FIXA]],
    ];

    /** O cabeçalho do modelo de importação do SICERT, na ordem dele. */
    public const CABECALHO_SICERT = [
        'CPF', 'NOME', 'E-MAIL', 'TELEFONE', 'COAUTORES', 'FUNCAO', 'TIPO DA ATIVIDADE',
        'TITULO DO TRABALHO/ATIVIDADE', 'CAMPO GENERICO', 'CARGA HORARIA', 'DATA INICIO', 'DATA FIM',
    ];

    public const ULTIMO_PASSO = 3;

    /**
     * Projetos e avaliadores já lidos, por fase: as categorias de uma mesma
     * fase (orientador, coorientador, estudante) leem os mesmos projetos.
     * Valem para uma operação só — cada entrada pública começa do zero, porque
     * o controller (e com ele este serviço) pode durar mais que uma requisição.
     *
     * @var array<string, Collection<int, Projeto>>
     */
    private array $projetos = [];

    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $avaliadores = [];

    public function __construct(private readonly CertificadosService $certificados) {}

    // --- Opções e categorias -----------------------------------------------------

    /** @return array<string, mixed> */
    public function opcoes(): array
    {
        return [
            'fases' => collect(self::FASES)->map(fn ($rotulo, $valor) => ['valor' => $valor, 'rotulo' => $rotulo])->values()->all(),
            'publicos' => collect(self::PUBLICOS)->map(fn ($p, $valor) => [
                'valor' => $valor, 'rotulo' => $p['rotulo'], 'fases' => $p['fases'], 'modos' => $p['modos'],
            ])->values()->all(),
            'datas' => $this->datasDasFases(),
        ];
    }

    /**
     * As categorias que as fases e o público escolhidos produzem, na ordem do
     * público e, dentro dele, da fase.
     *
     * @param  list<string>  $fases
     * @param  list<string>  $publicos
     * @return list<array{chave: string, publico: string, fase: ?string, rotulo: string, modos: list<string>}>
     */
    public function categorias(array $fases, array $publicos): array
    {
        $categorias = [];

        foreach (self::PUBLICOS as $publico => $def) {
            if (! in_array($publico, $publicos, true)) {
                continue;
            }

            if ($def['fases'] === null) {
                $categorias[] = ['chave' => $publico, 'publico' => $publico, 'fase' => null, 'rotulo' => $def['rotulo'], 'modos' => $def['modos']];

                continue;
            }

            $dasFases = array_values(array_intersect(array_keys(self::FASES), $def['fases'], $fases));
            foreach ($dasFases as $fase) {
                $chave = count($def['fases']) > 1 ? "{$publico}_{$fase}" : $publico;
                $rotulo = count($def['fases']) > 1 ? $def['rotulo'].' — '.mb_strtolower(self::FASES[$fase]) : $def['rotulo'];
                $categorias[] = ['chave' => $chave, 'publico' => $publico, 'fase' => $fase, 'rotulo' => $rotulo, 'modos' => $def['modos']];
            }
        }

        return $categorias;
    }

    /**
     * Normaliza o que o assistente mandou: só ficam as fases e os públicos que
     * existem (e que as fases permitem), e cada categoria nasce com o padrão —
     * a regra do perfil do avaliador (2h30 por projeto, até 120h) e o período
     * da fase em Datas e períodos.
     *
     * @param  array<string, mixed>  $config
     * @return array{fases: list<string>, publicos: list<string>, categorias: array<string, array<string, mixed>>}
     */
    public function normalizar(array $config): array
    {
        $fases = array_values(array_intersect(array_keys(self::FASES), (array) ($config['fases'] ?? [])));
        $publicos = array_values(array_filter(
            array_keys(self::PUBLICOS),
            fn (string $p) => in_array($p, (array) ($config['publicos'] ?? []), true)
                && (self::PUBLICOS[$p]['fases'] === null || array_intersect(self::PUBLICOS[$p]['fases'], $fases) !== []),
        ));

        $enviadas = (array) ($config['categorias'] ?? []);
        $categorias = [];

        foreach ($this->categorias($fases, $publicos) as $c) {
            $padrao = $this->padrao($c, $fases);
            $dado = (array) ($enviadas[$c['chave']] ?? []);
            $modo = in_array($dado['modo'] ?? null, $c['modos'], true) ? $dado['modo'] : $padrao['modo'];

            $categoria = [
                'modo' => $modo,
                'minutos' => array_key_exists('minutos', $dado) ? $this->inteiro($dado['minutos']) : $padrao['minutos'],
                'teto_minutos' => array_key_exists('teto_minutos', $dado) ? $this->inteiro($dado['teto_minutos']) : $padrao['teto_minutos'],
                'data_inicio' => array_key_exists('data_inicio', $dado) ? $this->data($dado['data_inicio']) : $padrao['data_inicio'],
                'data_fim' => array_key_exists('data_fim', $dado) ? $this->data($dado['data_fim']) : $padrao['data_fim'],
            ];

            if ($categoria['data_inicio'] !== null && $categoria['data_fim'] !== null && $categoria['data_fim'] < $categoria['data_inicio']) {
                throw ValidationException::withMessages([
                    "config.categorias.{$c['chave']}.data_fim" => "{$c['rotulo']}: a data final é anterior à inicial.",
                ]);
            }

            $categorias[$c['chave']] = $categoria;
        }

        return ['fases' => $fases, 'publicos' => $publicos, 'categorias' => $categorias];
    }

    // --- CRUD da lista ------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return CertificadoLista::query()
            ->where('edicao_id', Edicao::atual()?->id)
            ->with(['gerador:id,name', 'autor:id,name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CertificadoLista $l) => $this->resumoDaLista($l))
            ->all();
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(array $dados, User $autor): CertificadoLista
    {
        $config = $this->normalizar((array) ($dados['config'] ?? []));

        return CertificadoLista::create([
            'edicao_id' => Edicao::atual()?->id,
            'nome' => $this->nome($dados['nome'] ?? null),
            'passo' => min(self::ULTIMO_PASSO, (int) ($dados['passo'] ?? 0)),
            'config' => $config,
            'nomes' => [],
            'criada_por' => $autor->id,
        ]);
    }

    /**
     * Grava o que o assistente tem até aqui. Mexer na configuração de uma
     * lista já gerada marca o retrato como desatualizado.
     *
     * @param  array<string, mixed>  $dados
     */
    public function atualizar(CertificadoLista $lista, array $dados): CertificadoLista
    {
        if (array_key_exists('nome', $dados)) {
            $lista->nome = $this->nome($dados['nome']);
        }

        if (array_key_exists('passo', $dados) && $dados['passo'] !== null) {
            $lista->passo = min(self::ULTIMO_PASSO, (int) $dados['passo']);
        }

        if (array_key_exists('config', $dados) && is_array($dados['config'])) {
            $config = $this->normalizar($dados['config']);

            if ($lista->gerada() && $config != $lista->config) {
                $lista->alterada_em = now();
            }

            $lista->config = $config;
        }

        $lista->save();

        return $lista;
    }

    /**
     * As correções de nome da lista, por pessoa. Nome vazio, ou igual ao do
     * cadastro, desfaz a correção.
     *
     * @param  array<string, ?string>  $nomes  chave da pessoa → nome
     * @param  array<string, string>  $originais  chave da pessoa → nome do cadastro
     */
    public function salvarNomes(CertificadoLista $lista, array $nomes, array $originais = []): CertificadoLista
    {
        $atuais = $lista->nomes ?? [];

        foreach ($nomes as $chave => $nome) {
            $nome = trim(preg_replace('/\s+/u', ' ', (string) $nome) ?? '');

            if ($nome === '' || (isset($originais[$chave]) && $nome === $originais[$chave])) {
                unset($atuais[$chave]);
            } else {
                $atuais[$chave] = $nome;
            }
        }

        if ($lista->gerada() && $atuais != ($lista->nomes ?? [])) {
            $lista->alterada_em = now();
        }

        $lista->nomes = $atuais;
        $lista->save();

        return $lista;
    }

    /** @return array<string, mixed> */
    public function detalhe(CertificadoLista $lista): array
    {
        $lista->loadMissing(['gerador:id,name', 'autor:id,name']);
        $config = $this->normalizar($lista->config ?? []);

        return $this->resumoDaLista($lista) + [
            'config' => $config,
            'categorias' => $this->categorias($config['fases'], $config['publicos']),
            'nomes' => (object) ($lista->nomes ?? []),
        ];
    }

    // --- Cálculo ------------------------------------------------------------------

    /**
     * Cada categoria com quantas pessoas e certificados ela tem agora, e o que
     * merece atenção antes de gerar (sem CPF, sem e-mail).
     *
     * @return list<array<string, mixed>>
     */
    public function resumo(CertificadoLista $lista): array
    {
        $this->esquecer();
        $config = $this->normalizar($lista->config ?? []);

        return collect($this->categorias($config['fases'], $config['publicos']))
            ->map(function (array $c) use ($lista, $config) {
                $linhas = $this->calcular($lista, $c['chave']);
                $cfg = $config['categorias'][$c['chave']];

                return $c + [
                    'carga' => $cfg,
                    'carga_texto' => $this->cargaTexto($cfg),
                    'pessoas' => $linhas->pluck('pessoa_chave')->unique()->count(),
                    'certificados' => $linhas->count(),
                    'sem_cpf' => $linhas->whereNull('cpf')->count(),
                    'sem_email' => $linhas->whereNull('email')->count(),
                    'nomes_corrigidos' => $linhas->filter(fn (array $l) => $l['nome'] !== $l['nome_original'])->count(),
                    'teto_aplicado' => $linhas->where('teto_aplicado', true)->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Quem está numa categoria agora, com a carga calculada e o nome corrigido
     * por cima do cadastro — o que "Conferir nomes" mostra.
     *
     * @return list<array<string, mixed>>
     */
    public function pessoas(CertificadoLista $lista, string $categoria): array
    {
        $this->esquecer();
        $config = $this->normalizar($lista->config ?? []);
        abort_unless(isset($config['categorias'][$categoria]), 404, 'Categoria fora desta lista.');

        return $this->calcular($lista, $categoria)
            ->map(fn (array $l) => $l + ['horas' => $this->horas($l['minutos']), 'corrigido' => $l['nome'] !== $l['nome_original']])
            ->values()
            ->all();
    }

    /**
     * Congela a lista: calcula todas as categorias e grava o retrato. Gerar de
     * novo substitui o retrato inteiro.
     */
    public function gerar(CertificadoLista $lista, User $autor): CertificadoLista
    {
        $this->esquecer();
        $config = $this->normalizar($lista->config ?? []);
        $categorias = $this->categorias($config['fases'], $config['publicos']);

        if ($config['fases'] === []) {
            throw ValidationException::withMessages(['config.fases' => 'Escolha ao menos uma fase.']);
        }
        if ($categorias === []) {
            throw ValidationException::withMessages(['config.publicos' => 'Escolha ao menos um público-alvo.']);
        }

        $erros = [];
        foreach ($categorias as $c) {
            if ((int) ($config['categorias'][$c['chave']]['minutos'] ?? 0) <= 0) {
                $erros["config.categorias.{$c['chave']}.minutos"] = "{$c['rotulo']}: informe a carga horária.";
            }
        }
        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        $linhas = collect($categorias)->flatMap(fn (array $c) => $this->calcular($lista, $c['chave']))->values();

        DB::transaction(function () use ($lista, $linhas, $autor, $config) {
            $lista->linhas()->delete();

            $agora = now();
            foreach ($linhas->chunk(500) as $lote) {
                CertificadoLinha::insert($lote->map(fn (array $l, int $i) => [
                    'lista_id' => $lista->id,
                    'categoria' => $l['categoria'],
                    'pessoa_chave' => $l['pessoa_chave'],
                    'nome' => $l['nome'],
                    'nome_original' => $l['nome_original'],
                    'cpf' => $l['cpf'],
                    'email' => $l['email'],
                    'telefone' => $l['telefone'],
                    'funcao' => $l['funcao'],
                    'tipo_atividade' => $l['tipo_atividade'],
                    'titulo' => $l['titulo'],
                    'campo_generico' => $l['campo_generico'],
                    'quantidade' => $l['quantidade'],
                    'minutos' => $l['minutos'],
                    'teto_aplicado' => $l['teto_aplicado'],
                    'data_inicio' => $l['data_inicio'],
                    'data_fim' => $l['data_fim'],
                    'projeto_id' => $l['projeto_id'],
                    'ordem' => $i,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ])->values()->all());
            }

            $lista->forceFill([
                'config' => $config,
                'status' => CertificadoLista::GERADA,
                'passo' => self::ULTIMO_PASSO,
                'gerada_em' => $agora,
                'gerada_por' => $autor->id,
                'alterada_em' => null,
                'total_pessoas' => $linhas->pluck('pessoa_chave')->unique()->count(),
                'total_certificados' => $linhas->count(),
            ])->save();
        });

        return $lista->refresh();
    }

    /**
     * As linhas de uma categoria, calculadas agora: quem entra, a carga com o
     * teto e o período, e o nome corrigido da lista.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function calcular(CertificadoLista $lista, string $chave): Collection
    {
        $config = $this->normalizar($lista->config ?? []);
        $categoria = collect($this->categorias($config['fases'], $config['publicos']))->firstWhere('chave', $chave);
        $cfg = $config['categorias'][$chave] ?? null;

        if ($categoria === null || $cfg === null) {
            return collect();
        }

        $nomes = $lista->nomes ?? [];

        return $this->pessoasDa($categoria['publico'], $categoria['fase'])
            ->map(function (array $l) use ($chave, $cfg, $nomes) {
                [$minutos, $teto] = $this->minutos($cfg, $l['quantidade']);

                return $l + [
                    'categoria' => $chave,
                    'nome' => $nomes[$l['pessoa_chave']] ?? $l['nome_original'],
                    'minutos' => $minutos,
                    'teto_aplicado' => $teto,
                    'data_inicio' => $cfg['data_inicio'],
                    'data_fim' => $cfg['data_fim'],
                ];
            })
            ->sortBy(fn (array $l) => [Str::lower(Str::ascii($l['nome'])), Str::lower(Str::ascii((string) $l['titulo']))])
            ->values();
    }

    /**
     * Quem o público tem naquela fase, uma linha por certificado, ainda sem a
     * carga.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pessoasDa(string $publico, ?string $fase): Collection
    {
        $edicao = Edicao::atual()?->nome ?? 'XVI FETECMS';
        $tipo = $fase === null ? 'Organização' : self::FASES[$fase];

        return match ($publico) {
            'orientadores', 'coorientadores', 'estudantes' => $this->equipe($publico, (string) $fase),
            'avaliadores_online', 'avaliadores_presencial' => $this->avaliadoresDa((string) $fase)
                ->map(fn (array $a) => $this->linha(
                    "user:{$a['id']}", $a['nome'], $a['cpf'], $a['email'], null, 'Avaliador(a)', $tipo,
                    "Avaliação de {$a[$fase]} projeto(s)", null, $a[$fase], avaliadorId: $a['id'],
                )),
            'voluntarios' => ContaTemporaria::query()
                ->whereNull('removida_em')
                ->whereHas('user', fn (Builder $q) => $q->where('is_demo', false))
                ->with('user:id,name,email')
                ->withCount('turnos')
                ->get()
                ->filter(fn (ContaTemporaria $c) => $c->user !== null)
                ->map(fn (ContaTemporaria $c) => $this->linha(
                    "user:{$c->user_id}", (string) $c->user->name, $c->cpf, $c->user->email, null, 'Voluntário(a)', $tipo,
                    AbaAdmin::tryFrom((string) $c->setor)?->label() ?? (string) $c->setor, null,
                    // Sem turnos, a janela de acesso inteira é um turno.
                    max(1, (int) $c->turnos_count),
                )),
            'admins' => User::query()
                ->where('role', Role::Admin->value)
                ->where('is_active', true)
                ->where('is_demo', false)
                ->whereDoesntHave('contaTemporaria')
                ->get()
                ->map(fn (User $u) => $this->linha(
                    "user:{$u->id}", $u->name, $u->cpf, $u->email, null, 'Comissão organizadora', $tipo,
                    "Organização da {$edicao}", null, null,
                )),
            default => collect(),
        };
    }

    /**
     * Orientadores, coorientadores ou estudantes de uma fase: na online, a
     * equipe dos projetos submetidos pela inscrição; na presencial, quem
     * compareceu ao credenciamento.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function equipe(string $publico, string $fase): Collection
    {
        $linhas = collect();
        $tipo = self::FASES[$fase];

        foreach ($this->projetosDa($fase) as $p) {
            $presente = function (TipoPessoaCredenciamento $papel, ?int $id) use ($p, $fase): bool {
                if ($fase !== 'presencial') {
                    return true;
                }

                $marcacao = $p->credenciamento?->pessoas
                    ->first(fn (CredenciamentoPessoa $m) => $m->pessoa_tipo === $papel && (int) $m->pessoa_id === (int) $id);

                // Quem não foi marcado veio ao balcão — a regra do credenciamento.
                return $marcacao === null || $marcacao->presente;
            };
            $categoria = $p->categoria?->label();

            if ($publico === 'orientadores' && $p->user !== null && $presente(TipoPessoaCredenciamento::Orientador, $p->user->id)) {
                $linhas->push($this->linha(
                    "user:{$p->user->id}", $p->user->name, $p->user->orientadorProfile?->cpf, $p->user->email,
                    $p->user->orientadorProfile?->telefone, 'Orientador(a)', $tipo, $p->titulo, $categoria, null, $p->id,
                ));
            }

            if ($publico === 'coorientadores' && $p->coorientador !== null && $presente(TipoPessoaCredenciamento::Coorientador, $p->coorientador->id)) {
                $c = $p->coorientador;
                $linhas->push($this->linha(
                    "coorientador:{$c->id}", $c->nome, $c->cpf, $c->email, $c->telefone, 'Coorientador(a)', $tipo,
                    $p->titulo, $categoria, null, $p->id,
                ));
            }

            if ($publico === 'estudantes') {
                foreach ($p->alunos as $a) {
                    if ($presente(TipoPessoaCredenciamento::Aluno, $a->id)) {
                        $linhas->push($this->linha(
                            "aluno:{$a->id}", $a->nome, $a->cpf, $a->email, $a->telefone, 'Estudante', $tipo,
                            $p->titulo, $categoria, null, $p->id,
                        ));
                    }
                }
            }
        }

        return $linhas;
    }

    private function esquecer(): void
    {
        $this->projetos = [];
        $this->avaliadores = [];
    }

    /** @return Collection<int, Projeto> */
    private function projetosDa(string $fase): Collection
    {
        return $this->projetos[$fase] ??= ($fase === 'presencial'
            ? Projeto::semDemo()
                ->whereHas('credenciamento', fn (Builder $q) => $q->whereNotNull('finalizado_em'))
                ->with(['user.orientadorProfile', 'coorientador', 'alunos', 'credenciamento.pessoas'])
            : Projeto::avaliacaoOnline()
                ->whereIn('status', [ProjetoStatus::Submetido->value, ProjetoStatus::Aprovado->value, ProjetoStatus::Rejeitado->value])
                ->with(['user.orientadorProfile', 'coorientador', 'alunos'])
        )->orderBy('titulo')->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function avaliadoresDa(string $fase): Collection
    {
        return $this->avaliadores[$fase] ??= $this->certificados->avaliadores($fase);
    }

    /** @return array<string, mixed> */
    private function linha(
        string $chave, string $nome, ?string $cpf, ?string $email, ?string $telefone, string $funcao,
        string $tipo, ?string $titulo, ?string $campo, ?int $quantidade, ?int $projetoId = null, ?int $avaliadorId = null,
    ): array {
        $cpf = preg_replace('/\D/', '', (string) $cpf);
        $email = trim((string) $email);

        return [
            'pessoa_chave' => $chave,
            'nome_original' => trim($nome),
            'cpf' => strlen((string) $cpf) === 11 ? $cpf : null,
            'email' => $email !== '' ? $email : null,
            'telefone' => $this->telefone($telefone),
            'funcao' => $funcao,
            'tipo_atividade' => $tipo,
            'titulo' => $titulo,
            'campo_generico' => $campo,
            'quantidade' => $quantidade,
            'projeto_id' => $projetoId,
            'avaliador_id' => $avaliadorId,
        ];
    }

    /**
     * A carga em minutos e se o teto cortou. O teto só vale para a carga por
     * unidade: na fixa quem decide o número é o próprio admin.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{0: int, 1: bool}
     */
    private function minutos(array $cfg, ?int $quantidade): array
    {
        $unidade = (int) ($cfg['minutos'] ?? 0);

        if ($cfg['modo'] === self::MODO_FIXA) {
            return [$unidade, false];
        }

        $total = $unidade * max(0, (int) $quantidade);
        $teto = $cfg['teto_minutos'] ?? null;

        return $teto !== null && $total > $teto ? [(int) $teto, true] : [$total, false];
    }

    // --- Lista gerada -----------------------------------------------------------

    /**
     * As linhas congeladas, com filtro de categoria e busca por nome, CPF ou
     * título.
     */
    public function linhasGeradas(CertificadoLista $lista, ?string $categoria, string $busca, int $porPagina = 50): LengthAwarePaginator
    {
        return $this->consultaGerada($lista, $categoria, $busca)
            ->paginate($porPagina)
            ->through(fn (CertificadoLinha $l) => $this->linhaGerada($l));
    }

    /**
     * Cada categoria do retrato: certificados, pessoas, horas somadas e o que
     * merece atenção.
     *
     * @return list<array<string, mixed>>
     */
    public function resumoGerado(CertificadoLista $lista): array
    {
        $config = $this->normalizar($lista->config ?? []);
        $rotulos = collect($this->categorias($config['fases'], $config['publicos']))->keyBy('chave');
        $linhas = $lista->linhas()->get(['categoria', 'pessoa_chave', 'cpf', 'email', 'minutos', 'teto_aplicado', 'nome', 'nome_original']);

        return $linhas->groupBy('categoria')
            ->map(fn (Collection $grupo, string $chave) => [
                'chave' => $chave,
                'rotulo' => $rotulos[$chave]['rotulo'] ?? $chave,
                'carga_texto' => isset($config['categorias'][$chave]) ? $this->cargaTexto($config['categorias'][$chave]) : null,
                'certificados' => $grupo->count(),
                'pessoas' => $grupo->pluck('pessoa_chave')->unique()->count(),
                'horas' => $grupo->sum(fn (CertificadoLinha $l) => $this->horas($l->minutos)),
                'sem_cpf' => $grupo->whereNull('cpf')->count(),
                'sem_email' => $grupo->whereNull('email')->count(),
                'teto_aplicado' => $grupo->where('teto_aplicado', true)->count(),
                'nomes_corrigidos' => $grupo->filter(fn (CertificadoLinha $l) => $l->nome !== $l->nome_original)->count(),
            ])
            ->sortBy(fn (array $c) => array_search($c['chave'], $rotulos->keys()->all(), true))
            ->values()
            ->all();
    }

    /**
     * O arquivo da lista gerada: Excel e CSV no modelo do SICERT, PDF para
     * conferir e TXT para colar num e-mail.
     *
     * @return array{conteudo: string, nome: string, tipo: string}
     */
    public function exportar(CertificadoLista $lista, string $formato, ?string $categoria = null): array
    {
        abort_unless($lista->gerada(), 422, 'Gere a lista antes de exportar.');

        $linhas = $this->consultaGerada($lista, $categoria, '')->get();
        $base = 'certificados-'.(Str::slug($lista->nome) ?: 'lista').'-'.now()->format('Y-m-d');
        $sicert = $linhas->map(fn (CertificadoLinha $l) => [
            $l->cpf ?? '', $l->nome, $l->email ?? '', $l->telefone ?? '', '', $l->funcao, $l->tipo_atividade,
            $l->titulo ?? '', $l->campo_generico ?? '', $this->horas($l->minutos),
            $l->data_inicio?->format('d/m/Y') ?? '', $l->data_fim?->format('d/m/Y') ?? '',
        ])->all();

        return match ($formato) {
            'xlsx' => [
                'conteudo' => PlanilhaXlsx::gerar('Certificados', self::CABECALHO_SICERT, $sicert, [
                    ['largura' => 14, 'texto' => true], ['largura' => 36], ['largura' => 30], ['largura' => 17, 'texto' => true],
                    ['largura' => 14], ['largura' => 22], ['largura' => 18], ['largura' => 48], ['largura' => 18],
                    ['largura' => 10], ['largura' => 12, 'texto' => true], ['largura' => 12, 'texto' => true],
                ]),
                'nome' => $base.'.xlsx',
                'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'csv' => ['conteudo' => $this->csv($sicert), 'nome' => $base.'.csv', 'tipo' => 'text/csv; charset=UTF-8'],
            'pdf' => [
                'conteudo' => app(PdfService::class)->render('pdf.certificados', [
                    'lista' => $lista->loadMissing('gerador:id,name'),
                    'edicao' => Edicao::atual()?->nome,
                    'grupos' => $this->gruposParaArquivo($lista, $linhas),
                    'gerado_em' => now()->format('d/m/Y H:i'),
                ], 'a4', 'landscape'),
                'nome' => $base.'.pdf',
                'tipo' => 'application/pdf',
            ],
            default => ['conteudo' => $this->txt($lista, $linhas), 'nome' => $base.'.txt', 'tipo' => 'text/plain; charset=UTF-8'],
        };
    }

    /** @return Builder<CertificadoLinha> */
    private function consultaGerada(CertificadoLista $lista, ?string $categoria, string $busca): Builder
    {
        $busca = mb_strtolower(trim($busca));
        $digitos = preg_replace('/\D/', '', $busca);

        return CertificadoLinha::query()
            ->where('lista_id', $lista->id)
            ->when($categoria, fn (Builder $q) => $q->where('categoria', $categoria))
            ->when($busca !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->whereRaw('LOWER(nome) LIKE ?', ['%'.$busca.'%'])
                ->orWhereRaw('LOWER(nome_original) LIKE ?', ['%'.$busca.'%'])
                ->orWhereRaw('LOWER(titulo) LIKE ?', ['%'.$busca.'%'])
                ->when(strlen((string) $digitos) >= 3, fn (Builder $c) => $c->orWhere('cpf', 'like', '%'.$digitos.'%'))))
            ->orderBy('ordem');
    }

    /** @return array<string, mixed> */
    private function linhaGerada(CertificadoLinha $l): array
    {
        return [
            'id' => $l->id,
            'categoria' => $l->categoria,
            'pessoa_chave' => $l->pessoa_chave,
            'nome' => $l->nome,
            'nome_original' => $l->nome_original,
            'corrigido' => $l->nome !== $l->nome_original,
            'cpf' => $l->cpf,
            'email' => $l->email,
            'telefone' => $l->telefone,
            'funcao' => $l->funcao,
            'tipo_atividade' => $l->tipo_atividade,
            'titulo' => $l->titulo,
            'campo_generico' => $l->campo_generico,
            'quantidade' => $l->quantidade,
            'minutos' => $l->minutos,
            'horas' => $this->horas($l->minutos),
            'teto_aplicado' => $l->teto_aplicado,
            'data_inicio' => $l->data_inicio?->format('d/m/Y'),
            'data_fim' => $l->data_fim?->format('d/m/Y'),
        ];
    }

    /**
     * @param  Collection<int, CertificadoLinha>  $linhas
     * @return list<array{rotulo: string, linhas: list<array<string, mixed>>}>
     */
    private function gruposParaArquivo(CertificadoLista $lista, Collection $linhas): array
    {
        $rotulos = collect($this->resumoGerado($lista))->keyBy('chave');

        return $linhas->groupBy('categoria')
            ->map(fn (Collection $grupo, string $chave) => [
                'rotulo' => $rotulos[$chave]['rotulo'] ?? $chave,
                'carga_texto' => $rotulos[$chave]['carga_texto'] ?? null,
                'linhas' => $grupo->map(fn (CertificadoLinha $l) => $this->linhaGerada($l))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @param  list<list<mixed>>  $linhas */
    private function csv(array $linhas): string
    {
        // Como o modelo do SICERT: ponto e vírgula, aspas só quando o campo
        // precisa delas, e sem BOM — o importador lê o cabeçalho pelo nome, e
        // um BOM grudaria na primeira coluna. Quem abre no Excel usa o .xlsx.
        $campo = function (mixed $valor): string {
            $texto = str_replace(["\r\n", "\r", "\n"], ' ', (string) $valor);

            return preg_match('/[;"]/', $texto) ? '"'.str_replace('"', '""', $texto).'"' : $texto;
        };

        return collect([self::CABECALHO_SICERT, ...$linhas])
            ->map(fn (array $linha) => implode(';', array_map($campo, $linha)))
            ->implode("\r\n")."\r\n";
    }

    /** @param  Collection<int, CertificadoLinha>  $linhas */
    private function txt(CertificadoLista $lista, Collection $linhas): string
    {
        $saida = [
            mb_strtoupper($lista->nome),
            (Edicao::atual()?->nome ?? '').' · '.$linhas->count().' certificado(s) · gerada em '.$lista->gerada_em?->format('d/m/Y H:i'),
            '',
        ];

        foreach ($this->gruposParaArquivo($lista, $linhas) as $grupo) {
            $saida[] = '== '.mb_strtoupper($grupo['rotulo']).' ('.count($grupo['linhas']).')'.($grupo['carga_texto'] ? ' · '.$grupo['carga_texto'] : '');
            foreach ($grupo['linhas'] as $l) {
                $periodo = $l['data_inicio'] ? ' · '.$l['data_inicio'].($l['data_fim'] ? ' a '.$l['data_fim'] : '') : '';
                $saida[] = $l['nome'].' · CPF '.($l['cpf'] ? $this->cpfFormatado($l['cpf']) : 'não informado').' · '.$l['horas'].'h';
                $saida[] = '   '.$l['funcao'].' · '.$l['titulo'].$periodo;
            }
            $saida[] = '';
        }

        return implode("\n", $saida);
    }

    // --- Apoio ----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function resumoDaLista(CertificadoLista $l): array
    {
        $config = $this->normalizar($l->config ?? []);

        return [
            'id' => $l->id,
            'nome' => $l->nome,
            'status' => $l->status,
            'passo' => $l->passo,
            'fases' => $config['fases'],
            'publicos' => collect($config['publicos'])->map(fn (string $p) => self::PUBLICOS[$p]['rotulo'])->all(),
            'total_pessoas' => $l->total_pessoas,
            'total_certificados' => $l->total_certificados,
            'gerada_em' => $l->gerada_em?->toIso8601String(),
            'gerada_por' => $l->gerador?->name,
            'criada_por' => $l->autor?->name,
            'atualizada_em' => $l->updated_at?->toIso8601String(),
            'desatualizada' => $l->gerada() && $l->alterada_em !== null,
        ];
    }

    /**
     * O padrão de uma categoria: avaliador com a regra do perfil (2h30 por
     * projeto, até 120h), os demais em branco; o período vem da fase.
     *
     * @param  array{publico: string, fase: ?string}  $c
     * @param  list<string>  $fases
     * @return array<string, mixed>
     */
    private function padrao(array $c, array $fases): array
    {
        $datas = $this->datasDasFases();
        $avaliador = str_starts_with($c['publico'], 'avaliadores_');

        if ($c['fase'] !== null) {
            [$inicio, $fim] = [$datas[$c['fase']]['inicio'], $datas[$c['fase']]['fim']];
        } else {
            // Sem fase (a organização): do começo da primeira fase escolhida ao fim da última.
            $inicio = $datas[$fases[0] ?? 'online']['inicio'];
            $fim = $datas[$fases[count($fases) - 1] ?? 'presencial']['fim'];
        }

        return [
            'modo' => $avaliador ? self::MODO_POR_PROJETO : self::MODO_FIXA,
            'minutos' => $avaliador ? AvaliadorProfile::MINUTOS_POR_AVALIACAO : null,
            'teto_minutos' => $avaliador ? AvaliadorProfile::MAX_MINUTOS_CERTIFICADO : null,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
        ];
    }

    /** @return array<string, array{inicio: ?string, fim: ?string}> */
    private function datasDasFases(): array
    {
        $e = Edicao::atual();
        $dia = fn ($d) => $d?->timezone(config('app.timezone'))->format('Y-m-d');

        return [
            'online' => ['inicio' => $dia($e?->avaliacao_liberada_em), 'fim' => $dia($e?->avaliacao_encerrada_em)],
            'presencial' => ['inicio' => $dia($e?->evento_de), 'fim' => $dia($e?->evento_ate)],
        ];
    }

    /** @param  array<string, mixed>  $cfg */
    private function cargaTexto(array $cfg): string
    {
        $minutos = (int) ($cfg['minutos'] ?? 0);
        if ($minutos <= 0) {
            return 'Carga não informada';
        }

        $texto = match ($cfg['modo']) {
            self::MODO_POR_PROJETO => $this->duracao($minutos).' por projeto avaliado',
            self::MODO_POR_TURNO => $this->duracao($minutos).' por turno',
            default => $this->duracao($minutos).' fixas',
        };

        return $cfg['modo'] !== self::MODO_FIXA && ! empty($cfg['teto_minutos'])
            ? $texto.', até '.$this->duracao((int) $cfg['teto_minutos'])
            : $texto;
    }

    private function duracao(int $minutos): string
    {
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;

        return $m === 0 ? "{$h}h" : $h.'h'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }

    /** Horas inteiras, arredondadas para cima: 7h30 vira 8. */
    public function horas(int $minutos): int
    {
        return (int) ceil($minutos / 60);
    }

    private function nome(mixed $nome): string
    {
        $nome = trim((string) $nome);

        return $nome !== '' ? mb_substr($nome, 0, 120) : 'Certificados · '.now()->format('d/m/Y H:i');
    }

    private function inteiro(mixed $valor): ?int
    {
        return $valor === null || $valor === '' ? null : max(0, (int) $valor);
    }

    private function data(mixed $valor): ?string
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $valor)->format('Y-m-d') === $valor ? $valor : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function telefone(?string $telefone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $telefone);

        return match (strlen((string) $d)) {
            11 => preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $d),
            10 => preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $d),
            0 => null,
            default => trim((string) $telefone),
        };
    }

    private function cpfFormatado(string $cpf): string
    {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $cpf);
    }
}
