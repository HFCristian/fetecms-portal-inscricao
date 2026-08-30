<?php

namespace App\Services;

use App\Enums\SituacaoDocumento;
use App\Enums\TipoPessoaCredenciamento;
use App\Models\Credenciamento;
use App\Models\CredenciamentoDocumento;
use App\Models\DocumentoCredenciamento;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credenciamento dos finalistas no dia do evento.
 *
 * **Quem é finalista** sai da lista final **vigente** da edição: se ainda não
 * há lista oficial, não há quem credenciar — é a lista que define quem sobe ao
 * evento. Cada projeto passa uma vez pelo balcão, e a conferência é documento a
 * documento, pessoa a pessoa (alunos, orientador e coorientador), marcando
 * **presente**, **ausente** ou **não necessário**.
 *
 * **Quando**: só dentro da janela do evento (`edicoes.evento_de`/`evento_ate`),
 * que fica **fechada enquanto a data não for definida** — credenciar é ato
 * presencial, ninguém credencia por padrão. O **admin demo** tem um modo de
 * teste que ignora as datas, para conhecer a tela antes do evento.
 *
 * Fora da janela a aba continua abrindo em **leitura**: dá para conferir quem
 * já foi credenciado, mas não para credenciar.
 *
 * **Horários**: o início é preenchido sozinho com o momento do atendimento, mas
 * pode ser alterado — é assim que se lança um credenciamento que aconteceu
 * antes e só está sendo digitado agora. Quando o início vem alterado, o fim é
 * **início + 5 minutos** (a duração típica de um atendimento); quando não vem,
 * o fim é o instante da conclusão.
 */
class CredenciamentoService
{
    /**
     * Duração atribuída a um credenciamento lançado depois: sem cronômetro, o
     * fim é o início + este intervalo.
     */
    public const MINUTOS_ATENDIMENTO = 5;

    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /** O evento está aberto para este usuário? O demo em modo teste ignora as datas. */
    public function podeCredenciar(?User $user, bool $teste = false): bool
    {
        if ($teste && (bool) $user?->is_demo) {
            return true;
        }

        return (bool) Edicao::atual()?->eventoEmAndamento();
    }

    /**
     * Estado da janela do evento + o que a tela precisa saber para se explicar.
     *
     * @return array<string, mixed>
     */
    public function config(?User $user = null, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $vigente = ListaFinal::vigente($edicao);

        return [
            'aberto' => $this->podeCredenciar($user, $teste),
            'iniciado' => (bool) $edicao?->eventoIniciado(),
            'encerrado' => (bool) $edicao?->eventoEncerrado(),
            'inicio_label' => $edicao?->evento_de?->format('d/m/Y H:i'),
            'fim_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            'inicio_input' => $edicao?->evento_de?->format('Y-m-d\TH:i'),
            'fim_input' => $edicao?->evento_ate?->format('Y-m-d\TH:i'),
            // Só o admin demo enxerga o toggle de modo de teste.
            'pode_testar' => (bool) $user?->is_demo,
            'modo_teste' => $teste && (bool) $user?->is_demo,
            'itens' => $edicao?->itens_credenciamento ?? [],
            'minutos_atendimento' => self::MINUTOS_ATENDIMENTO,
            'lista' => $vigente === null ? null : [
                'id' => $vigente->id,
                'nome' => $vigente->nome,
                'versao' => $vigente->versao,
            ],
        ];
    }

    /**
     * Os finalistas da edição, com a situação de cada um no balcão.
     *
     * Filtros: `busca` (título, escola ou orientador), `area_id`, `categoria` e
     * `situacao` (`credenciados` | `pendentes`).
     *
     * @param  array<string, mixed>  $filtros
     */
    public function finalistas(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        $pagina = $this->query($filtros)->paginate($porPagina)->withQueryString();

        $pagina->getCollection()->transform(fn (Projeto $p) => $this->linha($p));

        return $pagina;
    }

    /**
     * Quantos finalistas há e quantos já passaram pelo balcão (no recorte dos
     * filtros, menos o de situação — senão o card mostraria só a aba aberta).
     *
     * @param  array<string, mixed>  $filtros
     * @return array{finalistas:int, credenciados:int, pendentes:int}
     */
    public function resumo(array $filtros): array
    {
        $base = $this->query(array_merge($filtros, ['situacao' => null]))->reorder();

        $total = (clone $base)->count();
        $credenciados = (clone $base)
            ->whereHas('credenciamento', fn ($q) => $q->whereNotNull('finalizado_em'))
            ->count();

        return [
            'finalistas' => $total,
            'credenciados' => $credenciados,
            'pendentes' => $total - $credenciados,
        ];
    }

    /**
     * A ficha de credenciamento de um projeto: cada pessoa com a lista de
     * documentos que o papel dela exige e o que já foi marcado.
     *
     * @return array<string, mixed>
     */
    public function ficha(Projeto $projeto): array
    {
        $projeto->loadMissing(['alunos', 'coorientador', 'user', 'area', 'instituicao']);
        $credenciamento = $this->credenciamentoDe($projeto);
        $marcado = $this->marcacoes($credenciamento);
        $catalogo = $this->catalogo();

        $pessoas = [];

        foreach ($projeto->alunos as $aluno) {
            $pessoas[] = $this->pessoa(TipoPessoaCredenciamento::Aluno, $aluno->id, $aluno->nome, $catalogo, $marcado);
        }

        if ($projeto->user !== null) {
            $pessoas[] = $this->pessoa(
                TipoPessoaCredenciamento::Orientador, $projeto->user->id, $projeto->user->name, $catalogo, $marcado,
            );
        }

        if ($projeto->coorientador !== null) {
            $pessoas[] = $this->pessoa(
                TipoPessoaCredenciamento::Coorientador,
                $projeto->coorientador->id,
                $projeto->coorientador->nome,
                $catalogo,
                $marcado,
            );
        }

        return [
            'projeto' => [
                'id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                'escola' => $projeto->instituicao?->nome,
            ],
            'pessoas' => $pessoas,
            'credenciamento' => $credenciamento === null ? null : [
                'iniciado_em' => $credenciamento->iniciado_em?->toIso8601String(),
                'finalizado_em' => $credenciamento->finalizado_em?->toIso8601String(),
                'credenciado_por' => $credenciamento->autor?->name,
                'observacao' => $credenciamento->observacao,
                'concluido' => $credenciamento->concluido(),
            ],
            'situacoes' => SituacaoDocumento::opcoes(),
        ];
    }

    /**
     * Grava a conferência e conclui o credenciamento.
     *
     * @param  array<int, array{documento_id:int, pessoa_tipo:string, pessoa_id:?int, situacao:string}>  $marcacoes
     */
    public function registrar(
        Projeto $projeto,
        User $admin,
        array $marcacoes,
        ?string $observacao = null,
        bool $teste = false,
        ?string $iniciadoEm = null,
    ): Credenciamento {
        if (! $this->podeCredenciar($admin, $teste)) {
            throw ValidationException::withMessages([
                'credenciamento' => $this->motivoFechado(),
            ]);
        }

        if (! $this->ehFinalista($projeto)) {
            throw ValidationException::withMessages([
                'credenciamento' => 'Este projeto não está na lista final vigente.',
            ]);
        }

        // Início informado = lançamento retroativo: o atendimento não está
        // acontecendo agora, então o fim é calculado, não cronometrado.
        $inicio = $this->interpretar($iniciadoEm);

        return DB::transaction(function () use ($projeto, $admin, $marcacoes, $observacao, $inicio) {
            $credenciamento = $this->credenciamentoDe($projeto) ?? Credenciamento::create([
                'projeto_id' => $projeto->id,
                'lista_final_id' => ListaFinal::vigente()?->id,
                'iniciado_em' => $inicio ?? now(),
            ]);

            $this->salvarMarcacoes($credenciamento, $projeto, $marcacoes);

            $credenciamento->update([
                'credenciado_por' => $admin->id,
                'iniciado_em' => $inicio ?? $credenciamento->iniciado_em ?? now(),
                'finalizado_em' => $inicio !== null
                    ? $inicio->copy()->addMinutes(self::MINUTOS_ATENDIMENTO)
                    : now(),
                'observacao' => $observacao,
            ]);

            $this->registros->credenciamento($credenciamento->fresh(), $projeto, $admin);

            return $credenciamento->fresh(['autor']);
        });
    }

    /** Por que o balcão está fechado agora. */
    public function motivoFechado(): string
    {
        $edicao = Edicao::atual();

        if ($edicao?->eventoEncerrado()) {
            return 'O evento foi encerrado em '.$edicao->evento_ate->format('d/m/Y H:i').'.';
        }

        return $edicao?->evento_de !== null
            ? 'O credenciamento abre em '.$edicao->evento_de->format('d/m/Y H:i').'.'
            : 'O período do evento ainda não foi definido pela organização.';
    }

    /** O projeto está na lista final vigente? */
    public function ehFinalista(Projeto $projeto): bool
    {
        $vigente = ListaFinal::vigente();

        return $vigente !== null && $vigente->projetos()->whereKey($projeto->id)->exists();
    }

    public function credenciamentoDe(Projeto $projeto): ?Credenciamento
    {
        return Credenciamento::with('autor')->where('projeto_id', $projeto->id)->first();
    }

    /**
     * Consulta base dos finalistas: os projetos da lista vigente da edição.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<Projeto>
     */
    private function query(array $filtros): Builder
    {
        $vigente = ListaFinal::vigente();
        $busca = trim((string) ($filtros['busca'] ?? ''));

        return Projeto::query()
            // Sem lista oficial não há finalista: a consulta devolve vazio.
            ->when($vigente === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($vigente !== null, fn ($q) => $q->whereIn(
                'id',
                $vigente->projetos()->select('projetos.id'),
            ))
            ->with(['area:id,nome', 'user:id,name', 'instituicao:id,nome', 'credenciamento.autor:id,name'])
            ->when(! empty($filtros['area_id']), fn ($q) => $q->where('area_id', $filtros['area_id']))
            ->when(! empty($filtros['categoria']), fn ($q) => $q->where('categoria', $filtros['categoria']))
            ->when(($filtros['situacao'] ?? null) === 'credenciados', fn ($q) => $q
                ->whereHas('credenciamento', fn ($c) => $c->whereNotNull('finalizado_em')))
            ->when(($filtros['situacao'] ?? null) === 'pendentes', fn ($q) => $q
                ->whereDoesntHave('credenciamento', fn ($c) => $c->whereNotNull('finalizado_em')))
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
    private function linha(Projeto $projeto): array
    {
        $credenciamento = $projeto->credenciamento;

        return [
            'id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'categoria' => $projeto->categoria?->value,
            'categoria_label' => $projeto->categoria?->label(),
            'area_id' => $projeto->area_id,
            'area' => $projeto->area?->nome,
            'escola' => $projeto->instituicao?->nome,
            'orientador' => $projeto->user?->name,
            'credenciado' => (bool) $credenciamento?->finalizado_em,
            'credenciado_em' => $credenciamento?->finalizado_em?->toIso8601String(),
            'credenciado_por' => $credenciamento?->autor?->name,
        ];
    }

    /**
     * Uma pessoa da ficha, com os documentos do papel dela e o que já foi
     * marcado antes.
     *
     * @param  array<string, list<DocumentoCredenciamento>>  $catalogo
     * @param  array<string, string>  $marcado
     * @return array<string, mixed>
     */
    private function pessoa(
        TipoPessoaCredenciamento $tipo,
        ?int $id,
        string $nome,
        array $catalogo,
        array $marcado,
    ): array {
        return [
            'tipo' => $tipo->value,
            'tipo_label' => $tipo->label(),
            'id' => $id,
            'nome' => $nome,
            'documentos' => array_map(fn (DocumentoCredenciamento $d) => [
                'id' => $d->id,
                'nome' => $d->nome,
                'situacao' => $marcado[$this->chave($d->id, $tipo->value, $id)] ?? null,
            ], $catalogo[$tipo->value] ?? []),
        ];
    }

    /**
     * O catálogo de documentos por papel, numa consulta só.
     *
     * @return array<string, list<DocumentoCredenciamento>>
     */
    private function catalogo(): array
    {
        $porTipo = DocumentoCredenciamento::query()
            ->where('ativo', true)
            ->orderBy('ordem')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (DocumentoCredenciamento $d) => $d->tipo_pessoa->value);

        return array_map(
            fn (string $tipo) => $porTipo->get($tipo, collect())->all(),
            array_combine(TipoPessoaCredenciamento::valores(), TipoPessoaCredenciamento::valores()),
        );
    }

    /**
     * O que já estava marcado, indexado por documento+pessoa.
     *
     * @return array<string, string>
     */
    private function marcacoes(?Credenciamento $credenciamento): array
    {
        if ($credenciamento === null) {
            return [];
        }

        return $credenciamento->documentos
            ->mapWithKeys(fn (CredenciamentoDocumento $d) => [
                $this->chave($d->documento_credenciamento_id, $d->pessoa_tipo->value, $d->pessoa_id) => $d->situacao->value,
            ])
            ->all();
    }

    /**
     * Grava a conferência. Marcações de documentos ou pessoas que não pertencem
     * a este projeto são ignoradas — o balcão não escreve fora da ficha.
     *
     * @param  array<int, array<string, mixed>>  $marcacoes
     */
    private function salvarMarcacoes(Credenciamento $credenciamento, Projeto $projeto, array $marcacoes): void
    {
        $validos = $this->pessoasValidas($projeto);
        // `pluck` devolve o enum já convertido; o mapa guarda o valor cru.
        $documentos = DocumentoCredenciamento::where('ativo', true)
            ->pluck('tipo_pessoa', 'id')
            ->map(fn ($tipo) => $tipo instanceof TipoPessoaCredenciamento ? $tipo->value : (string) $tipo);

        foreach ($marcacoes as $marcacao) {
            $documentoId = (int) ($marcacao['documento_id'] ?? 0);
            $tipo = (string) ($marcacao['pessoa_tipo'] ?? '');
            $pessoaId = $marcacao['pessoa_id'] ?? null;
            $situacao = SituacaoDocumento::tryFrom((string) ($marcacao['situacao'] ?? ''));

            $chavePessoa = $tipo.':'.$pessoaId;

            if ($situacao === null || ! isset($validos[$chavePessoa])) {
                continue;
            }

            // O documento precisa ser do papel da pessoa marcada.
            if (($documentos[$documentoId] ?? null) !== $tipo) {
                continue;
            }

            CredenciamentoDocumento::updateOrCreate(
                [
                    'credenciamento_id' => $credenciamento->id,
                    'documento_credenciamento_id' => $documentoId,
                    'pessoa_tipo' => $tipo,
                    'pessoa_id' => $pessoaId,
                ],
                [
                    'pessoa_nome' => $validos[$chavePessoa],
                    'situacao' => $situacao->value,
                ],
            );
        }
    }

    /**
     * Quem pode ser conferido neste projeto: "tipo:id" => nome.
     *
     * @return array<string, string>
     */
    private function pessoasValidas(Projeto $projeto): array
    {
        $projeto->loadMissing(['alunos', 'coorientador', 'user']);
        $pessoas = [];

        foreach ($projeto->alunos as $aluno) {
            $pessoas[TipoPessoaCredenciamento::Aluno->value.':'.$aluno->id] = $aluno->nome;
        }

        if ($projeto->user !== null) {
            $pessoas[TipoPessoaCredenciamento::Orientador->value.':'.$projeto->user->id] = $projeto->user->name;
        }

        if ($projeto->coorientador !== null) {
            $pessoas[TipoPessoaCredenciamento::Coorientador->value.':'.$projeto->coorientador->id] = $projeto->coorientador->nome;
        }

        return $pessoas;
    }

    private function chave(int $documentoId, string $tipo, ?int $pessoaId): string
    {
        return $documentoId.':'.$tipo.':'.$pessoaId;
    }

    /** Hora de parede local (o balcão digita o horário do relógio da mesa). */
    private function interpretar(?string $data): ?Carbon
    {
        return ($data !== null && $data !== '')
            ? Carbon::parse($data, config('app.timezone'))
            : null;
    }
}
