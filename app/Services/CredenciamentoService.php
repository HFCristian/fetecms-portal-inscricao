<?php

namespace App\Services;

use App\Enums\SituacaoDocumento;
use App\Enums\TipoPessoaCredenciamento;
use App\Models\Credenciamento;
use App\Models\CredenciamentoDocumento;
use App\Models\CredenciamentoPessoa;
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
 * No **modo de teste** o balcão troca de lista: em vez da oficial ele abre a
 * lista **demo** da edição (`php artisan demo:credenciamento`), com projetos de
 * mentira. É por isso que ensaiar não credencia ninguém de verdade — o
 * finalista de treinamento nem sequer está na lista oficial.
 *
 * **Quando**: só dentro da janela do evento (`edicoes.evento_de`/`evento_ate`),
 * que fica **fechada enquanto a data não for definida** — credenciar é ato
 * presencial, ninguém credencia por padrão. O **admin demo** tem um modo de
 * teste que ignora as datas, para conhecer a tela antes do evento.
 *
 * Fora da janela a aba continua abrindo em **leitura**: dá para conferir quem
 * já foi credenciado, mas não para credenciar.
 *
 * **Presença e kit** (Sprint 103) ficam em `credenciamento_pessoas`, uma linha
 * por pessoa do projeto. Marcar alguém **ausente** dispensa os documentos dela
 * — não se confere o RG de quem não veio — e não impede credenciar o projeto:
 * ele fica credenciado *com pendências*, e quem chegar depois é conferido numa
 * segunda passada. O **kit é por pessoa**, mas quase nunca sai todo de uma vez:
 * um aluno leva o dele e o de dois colegas, e o resto é retirado mais tarde, por
 * outra pessoa e em outro horário — por isso o responsável e o relógio ficam em
 * cada linha, e a retirada tem um caminho próprio, que só **acrescenta**.
 *
 * **Rascunho** (Sprint 109): o atendimento nem sempre termina de uma vez — o
 * aluno esqueceu o RG no ônibus e sai para buscar. O balcão salva o que já
 * conferiu (`finalizado_em` nulo) e retoma quando ele voltar. O rascunho tem
 * **dono** (`iniciado_por`): ninguém continua o atendimento de outra pessoa por
 * cima. A exceção é o **admin permanente**, que assume o rascunho de uma **conta
 * temporária** livremente (o turno dela acaba e o projeto não pode ficar preso)
 * e o de **outro admin permanente** mediante **justificativa**. Conta temporária
 * não assume rascunho de ninguém, nem de outra conta temporária.
 *
 * **Quem pode desfazer**: um credenciamento concluído pode ser **corrigido**
 * (regravado) ou **cancelado** — cancelar apaga a conferência e devolve o
 * projeto à fila de *Credenciar*. Mexer no credenciamento **de outra conta**
 * exige **admin permanente**: a conta temporária do balcão corrige e cancela o
 * que ela mesma registrou, e só isso. Cancelar pede **justificativa** e entra
 * em Registros → Credenciamento.
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
        if ($this->emTeste($user, $teste)) {
            return true;
        }

        return (bool) Edicao::atual()?->eventoEmAndamento();
    }

    /**
     * O modo de teste está mesmo valendo para esta pessoa? O parâmetro sozinho
     * não basta: quem não é demo pode mandar `teste=1` na URL e nada muda.
     */
    public function emTeste(?User $user, bool $teste): bool
    {
        return $teste && (bool) $user?->is_demo;
    }

    /**
     * A lista que define os finalistas para esta pessoa: a **demo** quando o
     * modo de teste vale, a **oficial** em qualquer outro caso.
     */
    public function listaFinal(?User $user = null, bool $teste = false): ?ListaFinal
    {
        return ListaFinal::vigente(null, $this->emTeste($user, $teste));
    }

    /**
     * Estado da janela do evento + o que a tela precisa saber para se explicar.
     *
     * @return array<string, mixed>
     */
    public function config(?User $user = null, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $emTeste = $this->emTeste($user, $teste);
        $vigente = ListaFinal::vigente($edicao, $emTeste);

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
            'modo_teste' => $emTeste,
            'itens' => $edicao?->itens_credenciamento ?? [],
            'minutos_atendimento' => self::MINUTOS_ATENDIMENTO,
            'lista' => $vigente === null ? null : [
                'id' => $vigente->id,
                'nome' => $vigente->nome,
                'versao' => $vigente->versao,
                'demo' => $vigente->demo,
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
    public function finalistas(
        array $filtros,
        int $porPagina = 25,
        ?User $user = null,
        bool $teste = false,
    ): LengthAwarePaginator {
        $pagina = $this->query($filtros, $user, $teste)->paginate($porPagina)->withQueryString();

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
    public function resumo(array $filtros, ?User $user = null, bool $teste = false): array
    {
        $base = $this->query(array_merge($filtros, ['situacao' => null]), $user, $teste)->reorder();

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
    public function ficha(Projeto $projeto, ?User $admin = null): array
    {
        $projeto->loadMissing(['alunos', 'coorientador', 'user', 'area', 'instituicao']);
        $credenciamento = $this->credenciamentoDe($projeto);
        $marcado = $this->marcacoes($credenciamento);
        $estados = $this->estados($credenciamento);
        $catalogo = $this->catalogo();

        $pessoas = [];

        foreach ($projeto->alunos as $aluno) {
            $pessoas[] = $this->pessoa(TipoPessoaCredenciamento::Aluno, $aluno->id, $aluno->nome, $catalogo, $marcado, $estados);
        }

        if ($projeto->user !== null) {
            $pessoas[] = $this->pessoa(
                TipoPessoaCredenciamento::Orientador, $projeto->user->id, $projeto->user->name, $catalogo, $marcado, $estados,
            );
        }

        if ($projeto->coorientador !== null) {
            $pessoas[] = $this->pessoa(
                TipoPessoaCredenciamento::Coorientador,
                $projeto->coorientador->id,
                $projeto->coorientador->nome,
                $catalogo,
                $marcado,
                $estados,
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
                'credenciado_por_mim' => $admin !== null && $credenciamento->credenciado_por === $admin->id,
                'observacao' => $credenciamento->observacao,
                'concluido' => $credenciamento->concluido(),
                // Rascunho: quem está com ele e o que esta pessoa pode fazer.
                'em_rascunho' => $credenciamento->emRascunho(),
                'iniciado_por' => $credenciamento->iniciador?->name,
                'meu_rascunho' => $admin !== null && $credenciamento->emRascunho()
                    && $this->donoDoRascunho($credenciamento, $admin),
                'exige_justificativa' => $admin !== null
                    && $this->exigeJustificativaParaAssumir($credenciamento, $admin),
                // Quem não pode alterar vê a ficha em leitura, com o motivo.
                'pode_alterar' => $admin === null || $this->podeAlterar($credenciamento, $admin),
                'motivo_bloqueio' => $admin !== null && ! $this->podeAlterar($credenciamento, $admin)
                    ? $this->motivoSemPermissao($credenciamento)
                    : null,
            ],
            'situacoes' => SituacaoDocumento::opcoes(),
        ];
    }

    /**
     * Este admin pode mexer neste credenciamento?
     *
     * **Concluído**: quem o fez sempre pode corrigir o próprio trabalho; para
     * mexer no de outra pessoa é preciso ser **admin permanente** — a conta
     * temporária existe para atender o balcão naquele turno, não para revisar o
     * turno alheio.
     *
     * **Rascunho**: é do dono. Um admin permanente ainda assume o de outra
     * pessoa (com justificativa, quando ela também for permanente), mas uma
     * conta temporária não assume o de ninguém.
     */
    public function podeAlterar(?Credenciamento $credenciamento, User $admin): bool
    {
        if ($credenciamento === null) {
            return true;
        }

        if ($credenciamento->concluido()) {
            return $credenciamento->credenciado_por === $admin->id || ! $admin->ehContaTemporaria();
        }

        return $this->donoDoRascunho($credenciamento, $admin) || ! $admin->ehContaTemporaria();
    }

    /** O rascunho é deste admin (ou não tem dono registrado)? */
    public function donoDoRascunho(Credenciamento $credenciamento, User $admin): bool
    {
        return $credenciamento->iniciado_por === null || $credenciamento->iniciado_por === $admin->id;
    }

    /**
     * Continuar este rascunho exige justificativa?
     *
     * Só quando ele é de **outro admin permanente**: o de uma conta temporária
     * é assumido sem cerimônia, porque o turno dela acaba e o projeto não pode
     * ficar preso esperando.
     */
    public function exigeJustificativaParaAssumir(?Credenciamento $credenciamento, User $admin): bool
    {
        if ($credenciamento === null || $credenciamento->concluido()) {
            return false;
        }

        if ($this->donoDoRascunho($credenciamento, $admin) || $admin->ehContaTemporaria()) {
            return false;
        }

        return ! (bool) $credenciamento->iniciador?->ehContaTemporaria();
    }

    /** A explicação que a tela mostra quando `podeAlterar()` diz não. */
    public function motivoSemPermissao(?Credenciamento $credenciamento): string
    {
        if ($credenciamento !== null && $credenciamento->emRascunho()) {
            return 'Este atendimento foi iniciado por '
                .($credenciamento->iniciador?->name ?? 'outra conta')
                .' e ainda está em rascunho. Só um administrador com conta permanente pode continuá-lo.';
        }

        return 'Este credenciamento foi feito por '
            .($credenciamento?->autor?->name ?? 'outra conta')
            .'. Só um administrador com conta permanente pode alterá-lo ou cancelá-lo.';
    }

    /**
     * Cancela um credenciamento concluído: a conferência é apagada e o projeto
     * volta para a fila de *Credenciar*.
     */
    public function cancelar(Projeto $projeto, User $admin, string $justificativa, bool $teste = false): void
    {
        if (! $this->podeCredenciar($admin, $teste)) {
            throw ValidationException::withMessages(['credenciamento' => $this->motivoFechado()]);
        }

        $credenciamento = $this->credenciamentoDe($projeto)?->load('autor', 'documentos.documento');

        if ($credenciamento === null || ! $credenciamento->concluido()) {
            throw ValidationException::withMessages([
                'credenciamento' => 'Este projeto ainda não foi credenciado.',
            ]);
        }

        if (! $this->podeAlterar($credenciamento, $admin)) {
            throw ValidationException::withMessages([
                'credenciamento' => $this->motivoSemPermissao($credenciamento),
            ]);
        }

        DB::transaction(function () use ($credenciamento, $projeto, $admin, $justificativa) {
            // O registro vai ANTES do delete: ele precisa de quem credenciou e
            // de quando, e essas informações somem junto com a linha.
            $this->registros->credenciamentoCancelado($credenciamento, $projeto, $admin, $justificativa);

            $credenciamento->documentos()->delete();
            $credenciamento->delete();
        });
    }

    /**
     * Salva o atendimento sem fechá-lo: o que já foi conferido fica guardado e
     * o projeto continua na fila de *Credenciar*.
     *
     * É o caso de quem saiu para buscar um documento — o balcão não recomeça a
     * conferência quando a pessoa voltar. O rascunho passa a ter **dono**, e é
     * ele quem o retoma (ver `podeAlterar()` e `assumir()`).
     *
     * @param  array<int, array<string, mixed>>  $marcacoes
     * @param  array<int, array<string, mixed>>  $pessoas
     * @param  array<string, mixed>|null  $kits
     */
    public function salvarRascunho(
        Projeto $projeto,
        User $admin,
        array $marcacoes,
        array $pessoas = [],
        ?array $kits = null,
        ?string $observacao = null,
        bool $teste = false,
        ?string $iniciadoEm = null,
    ): Credenciamento {
        if (! $this->podeCredenciar($admin, $teste)) {
            throw ValidationException::withMessages(['credenciamento' => $this->motivoFechado()]);
        }

        if (! $this->ehFinalista($projeto, $admin, $teste)) {
            throw ValidationException::withMessages([
                'credenciamento' => 'Este projeto não está na lista final vigente.',
            ]);
        }

        $existente = $this->credenciamentoDe($projeto);
        $this->garantirPosse($existente, $admin);
        $this->assumirSeNecessario($existente, $projeto, $admin);

        if ($existente?->concluido()) {
            throw ValidationException::withMessages([
                'credenciamento' => 'Este projeto já foi credenciado — não há rascunho a salvar.',
            ]);
        }

        $inicio = $this->interpretar($iniciadoEm);
        $lista = $this->listaFinal($admin, $teste);

        return DB::transaction(function () use ($projeto, $admin, $marcacoes, $pessoas, $kits, $observacao, $inicio, $lista) {
            $credenciamento = $this->credenciamentoDe($projeto) ?? Credenciamento::create([
                'projeto_id' => $projeto->id,
                'lista_final_id' => $lista?->id,
                'iniciado_em' => $inicio ?? now(),
                'iniciado_por' => $admin->id,
            ]);

            $this->salvarPessoas($credenciamento, $projeto, $pessoas);
            $this->salvarMarcacoes($credenciamento, $projeto, $marcacoes);

            if ($kits !== null) {
                $this->salvarKits($credenciamento, $projeto, $admin, $kits);
            }

            $credenciamento->update([
                'iniciado_em' => $inicio ?? $credenciamento->iniciado_em ?? now(),
                'iniciado_por' => $credenciamento->iniciado_por ?? $admin->id,
                'observacao' => $observacao,
            ]);

            return $credenciamento->fresh(['autor', 'iniciador', 'pessoas']);
        });
    }

    /**
     * Assume o rascunho de outra pessoa.
     *
     * O de uma **conta temporária** é assumido sem cerimônia — o turno dela
     * acaba e o projeto não pode ficar preso. O de **outro admin permanente**
     * exige **justificativa**: alguém está continuando uma conferência que não
     * fez, e isso precisa ficar registrado.
     */
    public function assumir(Projeto $projeto, User $admin, ?string $justificativa, bool $teste = false): Credenciamento
    {
        if (! $this->podeCredenciar($admin, $teste)) {
            throw ValidationException::withMessages(['credenciamento' => $this->motivoFechado()]);
        }

        $credenciamento = $this->credenciamentoDe($projeto);

        if ($credenciamento === null || $credenciamento->concluido()) {
            throw ValidationException::withMessages([
                'credenciamento' => 'Este projeto não tem atendimento em rascunho.',
            ]);
        }

        if (! $this->podeAlterar($credenciamento, $admin)) {
            throw ValidationException::withMessages([
                'credenciamento' => $this->motivoSemPermissao($credenciamento),
            ]);
        }

        if ($this->donoDoRascunho($credenciamento, $admin)) {
            return $credenciamento; // já é dele: nada a assumir
        }

        $justificativa = trim((string) $justificativa);

        if ($this->exigeJustificativaParaAssumir($credenciamento, $admin) && mb_strlen($justificativa) < 5) {
            throw ValidationException::withMessages([
                'justificativa' => 'Informe por que você está continuando o atendimento de outro administrador.',
            ]);
        }

        $anterior = $credenciamento->iniciador?->name ?? 'outra conta';

        return DB::transaction(function () use ($credenciamento, $projeto, $admin, $anterior, $justificativa) {
            $credenciamento->update(['iniciado_por' => $admin->id]);

            $this->registros->rascunhoCredenciamentoAssumido(
                $projeto,
                $admin,
                $anterior,
                $justificativa === '' ? null : $justificativa,
            );

            return $credenciamento->fresh(['autor', 'iniciador', 'pessoas']);
        });
    }

    /**
     * Passa para este admin o rascunho que era de outra pessoa e que ele pode
     * assumir **sem justificativa** — o de uma conta temporária.
     *
     * Acontece sozinho, no primeiro salvamento: obrigar um clique a mais no
     * balcão não protegeria nada, e sem isto a troca de mãos não apareceria na
     * trilha, que é o que importa registrar.
     */
    private function assumirSeNecessario(?Credenciamento $credenciamento, Projeto $projeto, User $admin): void
    {
        if ($credenciamento === null
            || $credenciamento->concluido()
            || $this->donoDoRascunho($credenciamento, $admin)) {
            return;
        }

        $anterior = $credenciamento->iniciador?->name ?? 'outra conta';
        $credenciamento->update(['iniciado_por' => $admin->id]);
        $this->registros->rascunhoCredenciamentoAssumido($projeto, $admin, $anterior);
    }

    /**
     * Barra quem não pode escrever neste atendimento, com a mensagem certa: ou
     * ele não pode mexer de jeito nenhum, ou precisa assumir o rascunho antes.
     */
    private function garantirPosse(?Credenciamento $credenciamento, User $admin): void
    {
        if (! $this->podeAlterar($credenciamento, $admin)) {
            throw ValidationException::withMessages([
                'credenciamento' => $this->motivoSemPermissao($credenciamento),
            ]);
        }

        if ($this->exigeJustificativaParaAssumir($credenciamento, $admin)) {
            throw ValidationException::withMessages([
                'credenciamento' => 'Este atendimento foi iniciado por '
                    .($credenciamento?->iniciador?->name ?? 'outro administrador')
                    .'. Assuma o rascunho, com justificativa, antes de continuar.',
            ]);
        }
    }

    /**
     * Grava a conferência e conclui o credenciamento.
     *
     * @param  array<int, array{documento_id:int, pessoa_tipo:string, pessoa_id:?int, situacao:string}>  $marcacoes
     * @param  array<int, array{pessoa_tipo:string, pessoa_id:?int, presente:bool}>  $pessoas
     * @param  array{responsavel_tipo?:string, responsavel_id?:?int, pessoas?:array<int, array{pessoa_tipo:string, pessoa_id:?int}>}|null  $kits
     */
    public function registrar(
        Projeto $projeto,
        User $admin,
        array $marcacoes,
        ?string $observacao = null,
        bool $teste = false,
        ?string $iniciadoEm = null,
        array $pessoas = [],
        ?array $kits = null,
    ): Credenciamento {
        if (! $this->podeCredenciar($admin, $teste)) {
            throw ValidationException::withMessages([
                'credenciamento' => $this->motivoFechado(),
            ]);
        }

        if (! $this->ehFinalista($projeto, $admin, $teste)) {
            throw ValidationException::withMessages([
                'credenciamento' => 'Este projeto não está na lista final vigente.',
            ]);
        }

        $existente = $this->credenciamentoDe($projeto);
        $this->garantirPosse($existente, $admin);
        $this->assumirSeNecessario($existente, $projeto, $admin);

        // Início informado = lançamento retroativo: o atendimento não está
        // acontecendo agora, então o fim é calculado, não cronometrado.
        $inicio = $this->interpretar($iniciadoEm);

        $lista = $this->listaFinal($admin, $teste);

        return DB::transaction(function () use ($projeto, $admin, $marcacoes, $observacao, $inicio, $lista, $pessoas, $kits) {
            $credenciamento = $this->credenciamentoDe($projeto) ?? Credenciamento::create([
                'projeto_id' => $projeto->id,
                'lista_final_id' => $lista?->id,
                'iniciado_em' => $inicio ?? now(),
                'iniciado_por' => $admin->id,
            ]);

            $this->salvarPessoas($credenciamento, $projeto, $pessoas);
            $this->salvarMarcacoes($credenciamento, $projeto, $marcacoes);

            if ($kits !== null) {
                $this->salvarKits($credenciamento, $projeto, $admin, $kits);
            }

            $credenciamento->update([
                'credenciado_por' => $admin->id,
                'iniciado_por' => $credenciamento->iniciado_por ?? $admin->id,
                'iniciado_em' => $inicio ?? $credenciamento->iniciado_em ?? now(),
                'finalizado_em' => $inicio !== null
                    ? $inicio->copy()->addMinutes(self::MINUTOS_ATENDIMENTO)
                    : now(),
                'observacao' => $observacao,
            ]);

            $this->registros->credenciamento($credenciamento->fresh(['documentos.documento', 'pessoas']), $projeto, $admin);

            return $credenciamento->fresh(['autor', 'pessoas']);
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

    /**
     * O projeto está na lista final que vale para esta pessoa? Em modo de teste
     * a pergunta é sobre a lista demo — é o que impede o ensaio de abrir a
     * ficha de um finalista de verdade, e vice-versa.
     */
    public function ehFinalista(Projeto $projeto, ?User $user = null, bool $teste = false): bool
    {
        $vigente = $this->listaFinal($user, $teste);

        return $vigente !== null && $vigente->projetos()->whereKey($projeto->id)->exists();
    }

    public function credenciamentoDe(Projeto $projeto): ?Credenciamento
    {
        return Credenciamento::with(['autor', 'iniciador.contaTemporaria', 'pessoas'])
            ->where('projeto_id', $projeto->id)
            ->first();
    }

    /**
     * Consulta base dos finalistas: os projetos da lista vigente da edição.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<Projeto>
     */
    private function query(array $filtros, ?User $user = null, bool $teste = false): Builder
    {
        $vigente = $this->listaFinal($user, $teste);
        $busca = trim((string) ($filtros['busca'] ?? ''));

        return Projeto::query()
            // Sem lista oficial não há finalista: a consulta devolve vazio.
            ->when($vigente === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($vigente !== null, fn ($q) => $q->whereIn(
                'id',
                $vigente->projetos()->select('projetos.id'),
            ))
            ->with([
                'area:id,nome', 'user:id,name', 'instituicao:id,nome',
                'credenciamento.autor:id,name', 'credenciamento.iniciador:id,name',
                'credenciamento.pessoas',
            ])
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
            // Atendimento aberto: a lista mostra com quem ele está.
            'em_rascunho' => (bool) $credenciamento?->emRascunho(),
            'rascunho_de' => $credenciamento?->emRascunho() ? $credenciamento->iniciador?->name : null,
            // O que ficou para trás: quem faltou ao balcão e os kits que ninguém
            // levou. É por aqui que a lista mostra "credenciado com pendências".
            'ausentes' => (int) $credenciamento?->pessoas->where('presente', false)->count(),
            'kits_pendentes' => (int) $credenciamento?->pessoas->whereNull('kit_retirado_em')->count(),
        ];
    }

    /**
     * Uma pessoa da ficha, com os documentos do papel dela e o que já foi
     * marcado antes.
     *
     * @param  array<string, list<DocumentoCredenciamento>>  $catalogo
     * @param  array<string, string>  $marcado
     * @param  array<string, CredenciamentoPessoa>  $estados
     * @return array<string, mixed>
     */
    private function pessoa(
        TipoPessoaCredenciamento $tipo,
        ?int $id,
        string $nome,
        array $catalogo,
        array $marcado,
        array $estados = [],
    ): array {
        $estado = $estados[$tipo->value.':'.$id] ?? null;

        return [
            'tipo' => $tipo->value,
            'tipo_label' => $tipo->label(),
            'id' => $id,
            'nome' => $nome,
            // Quem nunca foi marcado conta como presente: é o caso comum, e a
            // ausência é que precisa de decisão de alguém.
            'presente' => $estado?->presente ?? true,
            'kit' => [
                'retirado' => (bool) $estado?->kitRetirado(),
                'em' => $estado?->kit_retirado_em?->toIso8601String(),
                'por_nome' => $estado?->kit_retirado_por_nome,
                'por_tipo' => $estado?->kit_retirado_por_tipo,
                'por_id' => $estado?->kit_retirado_por_id,
            ],
            'documentos' => array_map(fn (DocumentoCredenciamento $d) => [
                'id' => $d->id,
                'nome' => $d->nome,
                'situacao' => $marcado[$this->chave($d->id, $tipo->value, $id)] ?? null,
            ], $catalogo[$tipo->value] ?? []),
        ];
    }

    /**
     * Presença e kit já gravados, indexados por "tipo:id".
     *
     * @return array<string, CredenciamentoPessoa>
     */
    private function estados(?Credenciamento $credenciamento): array
    {
        if ($credenciamento === null) {
            return [];
        }

        return $credenciamento->pessoas
            ->mapWithKeys(fn (CredenciamentoPessoa $p) => [$p->pessoa_tipo->value.':'.$p->pessoa_id => $p])
            ->all();
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
     * Registra a retirada de kits **depois** do credenciamento: o colega que
     * não veio no primeiro atendimento aparece mais tarde e leva o dele.
     *
     * Esta ação só **acrescenta** — nunca apaga nem reescreve o que outra conta
     * conferiu —, e por isso não passa pelo `podeAlterar()`: uma conta
     * temporária de balcão precisa poder atender a segunda visita de um projeto
     * que outro turno credenciou. Corrigir e cancelar continuam restritos.
     *
     * @param  array{responsavel_tipo:string, responsavel_id:?int, pessoas:array<int, array{pessoa_tipo:string, pessoa_id:?int}>}  $dados
     */
    public function retirarKits(Projeto $projeto, User $admin, array $dados, bool $teste = false): Credenciamento
    {
        if (! $this->podeCredenciar($admin, $teste)) {
            throw ValidationException::withMessages(['credenciamento' => $this->motivoFechado()]);
        }

        $credenciamento = $this->credenciamentoDe($projeto);

        if ($credenciamento === null) {
            throw ValidationException::withMessages([
                'kits' => 'Este projeto ainda não passou pelo balcão — registre o credenciamento primeiro.',
            ]);
        }

        return DB::transaction(function () use ($credenciamento, $projeto, $admin, $dados) {
            $this->salvarKits($credenciamento, $projeto, $admin, $dados);

            return $credenciamento->fresh(['autor', 'pessoas']);
        });
    }

    /**
     * Presença de cada pessoa do projeto. Quem não vem informado mantém o que
     * estava (e nasce **presente**): a ausência é que é decisão de alguém.
     *
     * A linha é criada para **todas** as pessoas do projeto, mesmo as que
     * ninguém marcou — é ela que carrega o kit depois, e um projeto credenciado
     * precisa saber quantos kits ainda faltam sair.
     *
     * @param  array<int, array<string, mixed>>  $pessoas
     */
    private function salvarPessoas(Credenciamento $credenciamento, Projeto $projeto, array $pessoas): void
    {
        $validos = $this->pessoasValidas($projeto);
        $informado = [];

        foreach ($pessoas as $pessoa) {
            $chave = ((string) ($pessoa['pessoa_tipo'] ?? '')).':'.($pessoa['pessoa_id'] ?? null);

            if (isset($validos[$chave])) {
                $informado[$chave] = (bool) ($pessoa['presente'] ?? true);
            }
        }

        foreach ($validos as $chave => $nome) {
            [$tipo, $id] = $this->destrinchar($chave);

            $linha = CredenciamentoPessoa::firstOrNew([
                'credenciamento_id' => $credenciamento->id,
                'pessoa_tipo' => $tipo,
                'pessoa_id' => $id,
            ]);

            $linha->pessoa_nome = $nome;

            if (array_key_exists($chave, $informado)) {
                $linha->presente = $informado[$chave];
            } elseif (! $linha->exists) {
                $linha->presente = true;
            }

            $linha->save();
        }

        // Ausente dispensa os documentos: o que porventura já tenha sido
        // conferido para quem não veio sai junto, senão a ficha ficaria dizendo
        // que o RG de um ausente está presente.
        $ausentes = $credenciamento->pessoas()->where('presente', false)->get();

        foreach ($ausentes as $ausente) {
            $credenciamento->documentos()
                ->where('pessoa_tipo', $ausente->pessoa_tipo->value)
                ->where('pessoa_id', $ausente->pessoa_id)
                ->delete();
        }

        $credenciamento->load('pessoas');
    }

    /**
     * Marca os kits que saíram agora, todos no nome de **um** responsável — que
     * precisa ser gente deste projeto. Kit já retirado não é retirado de novo
     * (o horário e o nome de quem levou são os da primeira vez).
     *
     * @param  array<string, mixed>  $dados
     */
    private function salvarKits(Credenciamento $credenciamento, Projeto $projeto, User $admin, array $dados): void
    {
        $escolhidas = $dados['pessoas'] ?? [];

        if ($escolhidas === []) {
            return;
        }

        $validos = $this->pessoasValidas($projeto);
        $responsavelChave = ((string) ($dados['responsavel_tipo'] ?? '')).':'.($dados['responsavel_id'] ?? null);

        if (! isset($validos[$responsavelChave])) {
            throw ValidationException::withMessages([
                'responsavel_id' => 'Quem retira o kit precisa ser um aluno, o orientador ou o coorientador deste projeto.',
            ]);
        }

        [$responsavelTipo, $responsavelId] = $this->destrinchar($responsavelChave);
        $agora = now();
        $levados = [];

        foreach ($escolhidas as $pessoa) {
            $chave = ((string) ($pessoa['pessoa_tipo'] ?? '')).':'.($pessoa['pessoa_id'] ?? null);

            if (! isset($validos[$chave])) {
                continue;
            }

            [$tipo, $id] = $this->destrinchar($chave);

            $linha = CredenciamentoPessoa::firstOrNew([
                'credenciamento_id' => $credenciamento->id,
                'pessoa_tipo' => $tipo,
                'pessoa_id' => $id,
            ]);

            if ($linha->exists && $linha->kitRetirado()) {
                continue;
            }

            $linha->pessoa_nome = $validos[$chave];
            $linha->presente = $linha->exists ? $linha->presente : true;
            $linha->kit_retirado_em = $agora;
            $linha->kit_retirado_por_tipo = $responsavelTipo;
            $linha->kit_retirado_por_id = $responsavelId;
            $linha->kit_retirado_por_nome = $validos[$responsavelChave];
            $linha->kit_registrado_por = $admin->id;
            $linha->save();

            $levados[] = $validos[$chave];
        }

        if ($levados !== []) {
            $this->registros->kitRetirado(
                $projeto,
                $admin,
                $validos[$responsavelChave],
                $levados,
                $agora,
            );
        }

        $credenciamento->load('pessoas');
    }

    /**
     * Quebra a chave "tipo:id" de volta em par. O id vem vazio quando a pessoa
     * não tem linha própria — nunca é o caso hoje, mas a chave aceita.
     *
     * @return array{0: string, 1: ?int}
     */
    private function destrinchar(string $chave): array
    {
        [$tipo, $id] = array_pad(explode(':', $chave, 2), 2, '');

        return [$tipo, $id === '' ? null : (int) $id];
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
        // Quem foi marcado ausente não tem documento conferido: a marcação que
        // vier para essa pessoa é descartada, e a que já existia foi apagada
        // por `salvarPessoas()`.
        $ausentes = $credenciamento->pessoas
            ->where('presente', false)
            ->map(fn (CredenciamentoPessoa $p) => $p->pessoa_tipo->value.':'.$p->pessoa_id)
            ->all();
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

            if ($situacao === null || ! isset($validos[$chavePessoa]) || in_array($chavePessoa, $ausentes, true)) {
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
