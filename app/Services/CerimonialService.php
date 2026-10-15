<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\TipoCredencial;
use App\Models\CerimonialCheckin;
use App\Models\CerimonialEvento;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\CodigoParticipante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aba **Cerimonial** — a porta da cerimônia de premiação.
 *
 * É um balcão diferente do credenciamento, e de propósito: o credenciamento
 * acontece na chegada ao evento e confere documento a documento; a cerimônia é
 * outro momento, outra sala e outra fila, e ali a pergunta é uma só — **quem já
 * está aqui dentro?**. Por isso o check-in **não depende** do credenciamento: a
 * ficha mostra se o projeto passou pelo balcão, mas não trava quem não passou.
 *
 * **Quem pode** fazer check-in são os **finalistas da lista vigente**, que é a
 * mesma base do credenciamento e do almoxarifado — sem lista final publicada
 * não há cerimônia a organizar. O check-in é **por pessoa**: é gente que entra
 * na sala, é gente que recebe medalha, e é nominalmente que a organização
 * precisa saber quem falta.
 *
 * **Quando**: dentro da janela do evento (`edicoes.evento_de`/`evento_ate`),
 * como o credenciamento. Fora dela a aba abre em leitura. A conta demo tem o
 * *modo de teste*, que ignora as datas, troca a lista oficial pela de
 * demonstração e isola os check-ins do ensaio (`cerimonial_checkins.demo`).
 *
 * **Premiado** é o projeto que recebeu uma credencial **ou** um prêmio
 * (Avaliação presencial → Credenciais e Prêmios). A distinção entre os dois
 * tipos pesa num lugar só: a **medalha** segue a pessoa premiada que chegou, e
 * a **credencial a separar** segue o projeto premiado presente — contando só o
 * que tem objeto na mesa, isto é, `tipo = credencial`.
 *
 * **Eventos de cerimônia** (Sprint 207): a feira faz mais de uma premiação —
 * uma para a FETECMS, outra para a FETEC Jr e a FETECMS FUNDECT —, com o mesmo
 * funcionamento e públicos diferentes. Cada {@see CerimonialEvento} diz quais
 * **categorias** recebe, e tudo aqui é por evento: o check-in, a busca, a
 * ficha, a Visão Geral e os premiados. Sem evento ("Todos"), a Visão Geral e
 * os premiados somam o público de todos os eventos da edição, contando cada
 * pessoa uma vez — presente se entrou em qualquer um deles.
 *
 * **A mesma pessoa em mais de um papel** (Sprint 210): o professor que orienta
 * um projeto e coorienta outro tem um crachá por vínculo, e cada crachá marca o
 * seu. Como não dá para reimprimir as etiquetas, o portal reconhece a pessoa —
 * pelo **CPF**, ou pelo e-mail com o mesmo nome quando falta CPF — e marcar um
 * vínculo dela (crachá ou ficha) marca **todos** os vínculos dela no mesmo
 * evento; desfazer também leva todos. Ver {@see mesmaPessoa()}.
 *
 * **Quem opera**: a conta temporária do setor `cerimonial` atende o check-in e
 * nada mais. A Visão Geral e as contas ficam para a organização — quem está na
 * porta não precisa saber quantas medalhas há na mesa. O corte é do middleware
 * `admin.permanente`, não do menu.
 */
class CerimonialService
{
    /** Intervalo de atualização do painel quando a edição não diz nada. */
    public const ATUALIZACAO_PADRAO = 30;

    public function __construct(private readonly RegistroAtividadeService $registros) {}

    // -----------------------------------------------------------------
    // Janela, lista e modo de teste
    // -----------------------------------------------------------------

    /**
     * O modo de teste está mesmo valendo para esta pessoa? O parâmetro sozinho
     * não basta: quem não é demo pode mandar `teste=1` na URL e nada muda.
     */
    public function emTeste(?User $user, bool $teste): bool
    {
        return $teste && (bool) $user?->is_demo;
    }

    /** A cerimônia está aberta para registrar? O demo em teste ignora as datas. */
    public function podeRegistrar(?User $user, bool $teste = false): bool
    {
        if ($this->emTeste($user, $teste)) {
            return true;
        }

        return (bool) Edicao::atual()?->eventoEmAndamento();
    }

    /** A lista que define os finalistas para esta pessoa: a demo ou a oficial. */
    public function listaFinal(?User $user = null, bool $teste = false): ?ListaFinal
    {
        return ListaFinal::vigente(null, $this->emTeste($user, $teste));
    }

    /**
     * O que a tela precisa saber para se explicar.
     *
     * @return array<string, mixed>
     */
    public function config(?User $user = null, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $emTeste = $this->emTeste($user, $teste);
        $vigente = ListaFinal::vigente($edicao, $emTeste);

        return [
            'aberto' => $this->podeRegistrar($user, $teste),
            'iniciado' => (bool) $edicao?->eventoIniciado(),
            'encerrado' => (bool) $edicao?->eventoEncerrado(),
            'inicio_label' => $edicao?->evento_de?->format('d/m/Y H:i'),
            'fim_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            'pode_testar' => (bool) $user?->is_demo,
            'modo_teste' => $emTeste,
            // Conta temporária atende a porta: não abre a Visão Geral nem as contas.
            'pode_gerir' => ! (bool) $user?->ehContaTemporaria(),
            // Nulo = sem atualização automática, só o botão de atualizar.
            'atualizacao_segundos' => $edicao?->cerimonial_atualizacao_segundos,
            'eventos' => $this->eventos(),
            'lista' => $vigente === null ? null : [
                'id' => $vigente->id,
                'nome' => $vigente->nome,
                'versao' => $vigente->versao,
                'demo' => $vigente->demo,
            ],
        ];
    }

    /** De quantos em quantos segundos o painel se atualiza (nulo desliga). */
    public function definirAtualizacao(?int $segundos): ?int
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return null;
        }

        $edicao->cerimonial_atualizacao_segundos = $segundos;
        $edicao->save();

        return $segundos;
    }

    // -----------------------------------------------------------------
    // Eventos de cerimônia
    // -----------------------------------------------------------------

    /**
     * Os eventos da edição em uso, na ordem em que foram criados, com o
     * público e quantas pessoas já entraram em cada um (fora do ensaio).
     *
     * @return list<array<string, mixed>>
     */
    public function eventos(): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        return CerimonialEvento::where('edicao_id', $edicao->id)
            ->withCount(['checkins' => fn ($q) => $q->where('demo', false)])
            ->orderBy('id')
            ->get()
            ->map(fn (CerimonialEvento $e) => $this->resumoEvento($e))
            ->all();
    }

    /** O evento pelo id — só os da edição em uso. */
    public function evento(int $id): CerimonialEvento
    {
        $evento = CerimonialEvento::where('edicao_id', Edicao::atual()?->id)->find($id);

        if ($evento === null) {
            abort(404, 'Evento de cerimônia não encontrado nesta edição.');
        }

        return $evento;
    }

    /**
     * Cria (sem `$evento`) ou altera um evento: o nome e as categorias do
     * público. Tirar uma categoria de um evento que já recebeu gente não apaga
     * check-in nenhum — eles só deixam de contar ali.
     *
     * @param  list<string>  $categorias  valores de {@see Categoria}
     * @return array<string, mixed>
     */
    public function salvarEvento(?CerimonialEvento $evento, string $nome, array $categorias): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages(['nome' => 'Não há edição em uso para receber o evento.']);
        }

        $evento ??= new CerimonialEvento(['edicao_id' => $edicao->id]);
        $evento->fill([
            'nome' => trim($nome),
            'categorias' => array_values(array_unique($categorias)),
        ])->save();

        return $this->resumoEvento($evento->loadCount(['checkins' => fn ($q) => $q->where('demo', false)]));
    }

    /**
     * Exclui um evento que ainda não recebeu ninguém. Depois do primeiro
     * check-in de verdade ele é a memória de quem esteve na sala, e some só
     * com a edição. Os check-ins do ensaio vão junto.
     */
    public function excluirEvento(CerimonialEvento $evento): void
    {
        if ($evento->checkins()->where('demo', false)->exists()) {
            throw ValidationException::withMessages([
                'evento' => 'Este evento já tem check-ins e não pode ser excluído. Para mudar quem ele recebe, edite o público.',
            ]);
        }

        $evento->delete();
    }

    /** @return array<string, mixed> */
    private function resumoEvento(CerimonialEvento $evento): array
    {
        return [
            'id' => $evento->id,
            'nome' => $evento->nome,
            'categorias' => array_values(array_map(
                fn (Categoria $c) => $c->value,
                array_filter(Categoria::ordemDaLista(), fn (Categoria $c) => $evento->recebe($c)),
            )),
            'publico' => $evento->publicoLabel(),
            'checkins' => (int) ($evento->checkins_count ?? 0),
        ];
    }

    /** O projeto é do público do evento? Diz por quê não, quando não é. */
    private function garantirPublico(Projeto $projeto, CerimonialEvento $evento, string $campo, ?string $pessoa = null): void
    {
        if ($evento->recebe($projeto->categoria)) {
            return;
        }

        $categoria = $projeto->categoria?->label() ?? 'sem categoria';

        throw ValidationException::withMessages([
            $campo => $pessoa !== null
                ? "{$pessoa} é de um projeto da {$categoria}, que não está no público de {$evento->nome}."
                : "Este projeto é da {$categoria}, que não está no público de {$evento->nome}.",
        ]);
    }

    // -----------------------------------------------------------------
    // Balcão: achar a pessoa
    // -----------------------------------------------------------------

    /**
     * Resolve o crachá lido (QR ou código de barras): o projeto e a pessoa.
     *
     * A conferência é a mesma do credenciamento, pelas mesmas três razões:
     * **formato** (etiqueta amassada, QR de outro evento), **finalista** (o
     * projeto não está na lista que vale para esta pessoa) e **pessoa** (papel,
     * id e os três dígitos do CPF — é o que pega o crachá trocado entre
     * colegas).
     *
     * @return array{0: Projeto, 1: array<string, mixed>}
     */
    private function lerCracha(string $codigo, User $user, bool $teste): array
    {
        $lido = CodigoParticipante::ler($codigo);

        if ($lido === null) {
            throw ValidationException::withMessages([
                'codigo' => 'Código não reconhecido. Leia de novo ou digite o que está escrito na etiqueta.',
            ]);
        }

        $projeto = Projeto::with(['alunos', 'coorientador', 'user.orientadorProfile'])
            ->find($lido['projeto_id']);

        if ($projeto === null || ! $this->ehFinalista($projeto, $user, $teste)) {
            throw ValidationException::withMessages([
                'codigo' => $this->emTeste($user, $teste)
                    ? 'Este código não é de um projeto da lista de demonstração. Desligue o modo de teste para atender um finalista de verdade.'
                    : 'Este código não é de um projeto da lista final vigente.',
            ]);
        }

        $pessoa = collect($this->participantesDo($projeto))
            ->firstWhere('chave', $lido['papel'].$lido['participante_id']);

        if ($pessoa === null) {
            throw ValidationException::withMessages([
                'codigo' => 'O código é deste projeto, mas a pessoa não está mais na equipe dele.',
            ]);
        }

        if (CodigoParticipante::cpf3($pessoa['cpf']) !== $lido['cpf3']) {
            throw ValidationException::withMessages([
                'codigo' => 'A etiqueta não confere com o cadastro desta pessoa. Confira se o crachá é dela mesma.',
            ]);
        }

        return [$projeto, $pessoa];
    }

    /**
     * Busca por **nome ou CPF** entre os participantes dos projetos finalistas.
     *
     * A fila da cerimônia tem gente sem crachá na mão, e é este o caminho para
     * ela. A busca é em memória porque a lista de finalistas já vem inteira
     * daqui — são algumas centenas de projetos, não a base do portal — e assim
     * o mesmo termo acha o nome do aluno, o do orientador e o CPF de qualquer
     * um deles sem três consultas e uma união.
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(string $termo, CerimonialEvento $evento, ?User $user = null, bool $teste = false, int $limite = 25): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < 2) {
            return [];
        }

        $digitos = preg_replace('/\D/', '', $termo);
        $alvo = $this->normalizar($termo);
        $presentes = $this->checkinsPorChave($user, $teste, $evento);

        return $this->participantes($user, $teste, $evento)
            ->filter(function (array $p) use ($alvo, $digitos) {
                if (str_contains($this->normalizar($p['nome']), $alvo)) {
                    return true;
                }

                // Só procura por CPF quando o termo tem dígito suficiente para
                // ser um: "3" acharia meia feira.
                return $digitos !== '' && mb_strlen($digitos) >= 3
                    && str_contains((string) preg_replace('/\D/', '', (string) $p['cpf']), $digitos);
            })
            ->take($limite)
            ->map(fn (array $p) => [
                'projeto_id' => $p['projeto_id'],
                'projeto_titulo' => $p['projeto_titulo'],
                'categoria' => $p['categoria'],
                'area' => $p['area'],
                'escola' => $p['escola'],
                'chave' => $p['chave'],
                'nome' => $p['nome'],
                'papel' => $p['papel'],
                'papel_label' => $p['papel_label'],
                'presente' => isset($presentes[$p['projeto_id'].':'.$p['chave']]),
            ])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Balcão: a ficha do projeto
    // -----------------------------------------------------------------

    /**
     * A ficha de um projeto: os integrantes, quem já entrou e a situação do
     * credenciamento — informação, não trava.
     *
     * @return array<string, mixed>
     */
    public function ficha(Projeto $projeto, CerimonialEvento $evento, ?User $user = null, bool $teste = false): array
    {
        if (! $this->ehFinalista($projeto, $user, $teste)) {
            abort(404, 'Este projeto não está na lista final vigente.');
        }

        if (! $evento->recebe($projeto->categoria)) {
            abort(404, "Este projeto não está no público de {$evento->nome}.");
        }

        $projeto->loadMissing([
            'alunos', 'coorientador', 'user', 'area', 'instituicao.cidade.estado',
        ]);

        $checkins = CerimonialCheckin::where('projeto_id', $projeto->id)
            ->where('evento_id', $evento->id)
            ->where('demo', $this->emTeste($user, $teste))
            ->with('autor:id,name')
            ->get()
            ->keyBy(fn (CerimonialCheckin $c) => $c->chave());

        $pessoas = array_map(function (array $p) use ($checkins) {
            $checkin = $checkins->get($p['chave']);

            return [
                'chave' => $p['chave'],
                'papel' => $p['papel'],
                'papel_label' => $p['papel_label'],
                'nome' => $p['nome'],
                'presente' => $checkin !== null,
                'checkin_em' => $checkin?->checkin_em?->format('d/m/Y H:i'),
                'registrado_por' => $checkin?->autor?->name,
            ];
        }, $this->participantesDo($projeto));

        $presentes = count(array_filter($pessoas, fn (array $p) => $p['presente']));

        return [
            'evento' => ['id' => $evento->id, 'nome' => $evento->nome],
            'projeto' => [
                'id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                'escola' => $this->escola($projeto),
            ],
            'pessoas' => $pessoas,
            'presentes' => $presentes,
            'total' => count($pessoas),
            'premiacoes' => $this->premiacoesDoProjeto($projeto->id),
            // Informativo: a cerimônia não exige ter passado pelo balcão.
            'credenciado' => DB::table('credenciamentos')
                ->where('projeto_id', $projeto->id)
                ->whereNotNull('finalizado_em')
                ->exists(),
        ];
    }

    /**
     * Registra o check-in de uma ou mais pessoas do projeto.
     *
     * Quem já entrou é **ignorado em silêncio**: a equipe chega em levas, e o
     * atendente que marca a caixa de novo não pode receber erro por isso.
     *
     * @param  list<string>  $chaves  papel + id de cada pessoa ("A45", "O12")
     * @return array<string, mixed>
     */
    public function registrar(Projeto $projeto, array $chaves, User $admin, CerimonialEvento $evento, bool $teste = false): array
    {
        $emTeste = $this->emTeste($admin, $teste);

        if (! $this->podeRegistrar($admin, $teste)) {
            throw ValidationException::withMessages([
                'checkin' => 'O check-in do cerimonial só acontece durante o período do evento.',
            ]);
        }

        if (! $this->ehFinalista($projeto, $admin, $teste)) {
            abort(404, 'Este projeto não está na lista final vigente.');
        }

        $this->garantirPublico($projeto, $evento, 'participantes');

        $pessoas = collect($this->participantesDo($projeto))->keyBy('chave');
        $desconhecidas = array_values(array_diff($chaves, $pessoas->keys()->all()));

        if ($desconhecidas !== []) {
            throw ValidationException::withMessages([
                'participantes' => 'Alguma das pessoas marcadas não está mais na equipe deste projeto.',
            ]);
        }

        $marcadas = array_map(fn (string $chave) => $pessoas->get($chave), $chaves);
        $this->gravar($projeto, $marcadas, $admin, $evento, $emTeste);
        $vinculados = $this->marcarVinculos($projeto, $marcadas, $admin, $evento, $teste);

        return $this->ficha($projeto, $evento, $admin, $teste) + ['vinculados' => $vinculados];
    }

    /**
     * Lê o crachá e já registra a entrada **daquela pessoa** — a porta da
     * cerimônia com leitor ou câmera.
     *
     * Na leitura o atendente não abre ficha nem marca caixa: bipou, entrou. A
     * equipe que chega junta passa crachá a crachá; a ficha continua sendo o
     * caminho de quem chega **sem** crachá (busca por nome ou CPF), onde marcar
     * quatro de uma vez faz a fila andar.
     *
     * Ler o crachá de quem **já entrou** não grava nada nem dá erro — volta
     * `ja_presente` com a hora da entrada, porque é o que o atendente precisa
     * saber diante de alguém que diz que saiu e voltou (ou que emprestou o
     * crachá). As conferências do código são as de {@see lerCracha()}.
     *
     * @return array<string, mixed>
     */
    public function checkinPorCodigo(string $codigo, User $admin, CerimonialEvento $evento, bool $teste = false): array
    {
        // A janela vem antes do código: com o evento fechado, "código não
        // reconhecido" mandaria o atendente bipar de novo à toa.
        if (! $this->podeRegistrar($admin, $teste)) {
            throw ValidationException::withMessages([
                'codigo' => 'O check-in do cerimonial só acontece durante o período do evento.',
            ]);
        }

        [$projeto, $pessoa] = $this->lerCracha($codigo, $admin, $teste);
        $emTeste = $this->emTeste($admin, $teste);

        // O crachá certo na porta errada: diz de quem é, para o atendente
        // mandar a pessoa para a cerimônia dela.
        $this->garantirPublico($projeto, $evento, 'codigo', $pessoa['nome']);

        $novos = $this->gravar($projeto, [$pessoa], $admin, $evento, $emTeste);
        $vinculados = $this->marcarVinculos($projeto, [$pessoa], $admin, $evento, $teste);

        $checkin = CerimonialCheckin::where('projeto_id', $projeto->id)
            ->where('evento_id', $evento->id)
            ->where('papel', $pessoa['papel'])
            ->where('participante_id', $pessoa['participante_id'])
            ->where('demo', $emTeste)
            ->first();

        return [
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'participante' => [
                'chave' => $pessoa['chave'],
                'nome' => $pessoa['nome'],
                'papel' => $pessoa['papel'],
                'papel_label' => $pessoa['papel_label'],
            ],
            'ja_presente' => $novos === [],
            'checkin_em' => $checkin?->checkin_em?->format('H:i'),
            // Os outros papéis da mesma pessoa, marcados junto.
            'vinculos' => $vinculados,
        ];
    }

    /**
     * Grava a entrada das pessoas dadas. Quem já entrou é **ignorado em
     * silêncio** — a equipe chega em levas, e marcar de novo não é erro.
     *
     * @param  list<array<string, mixed>>  $pessoas  linhas de {@see participantesDo()}
     * @return list<array<string, mixed>> só as que entraram agora
     */
    private function gravar(Projeto $projeto, array $pessoas, User $admin, CerimonialEvento $evento, bool $emTeste, ?string $junto = null): array
    {
        $edicao = Edicao::atual();

        $novos = DB::transaction(function () use ($pessoas, $projeto, $admin, $edicao, $evento, $emTeste) {
            $criados = [];

            foreach ($pessoas as $pessoa) {
                $checkin = CerimonialCheckin::firstOrCreate(
                    [
                        'evento_id' => $evento->id,
                        'projeto_id' => $projeto->id,
                        'papel' => $pessoa['papel'],
                        'participante_id' => $pessoa['participante_id'],
                        'demo' => $emTeste,
                    ],
                    [
                        'edicao_id' => $edicao?->id ?? $projeto->edicao_id,
                        'nome' => $pessoa['nome'],
                        'checkin_em' => now(),
                        'registrado_por' => $admin->id,
                    ],
                );

                if ($checkin->wasRecentlyCreated) {
                    $criados[] = $pessoa;
                }
            }

            return $criados;
        });

        // Um registro por pessoa: é a pessoa que entra, e é nominalmente que a
        // trilha precisa responder depois.
        foreach ($novos as $pessoa) {
            $this->registros->cerimonialCheckin(
                $projeto,
                $admin,
                $pessoa['nome'],
                $pessoa['papel_label'],
                $emTeste,
                $evento->nome,
                $junto,
            );
        }

        return $novos;
    }

    /**
     * Desfaz o check-in de uma pessoa — com justificativa obrigatória.
     *
     * O número de check-ins decide quantas medalhas e quantas credenciais saem
     * da mesa, então apagar um é mexer no que vai ser entregue no palco. A linha
     * é removida (a contagem tem de voltar a bater), mas o registro é gravado
     * **antes**, com o nome de quem era e o motivo.
     *
     * @return array<string, mixed>
     */
    public function desfazer(Projeto $projeto, string $chave, string $justificativa, User $admin, CerimonialEvento $evento, bool $teste = false): array
    {
        $emTeste = $this->emTeste($admin, $teste);

        if (! $this->podeRegistrar($admin, $teste)) {
            throw ValidationException::withMessages([
                'checkin' => 'O check-in do cerimonial só é alterado durante o período do evento.',
            ]);
        }

        $papel = strtoupper(substr($chave, 0, 1));
        $participanteId = (int) substr($chave, 1);

        $checkin = CerimonialCheckin::where('projeto_id', $projeto->id)
            ->where('evento_id', $evento->id)
            ->where('papel', $papel)
            ->where('participante_id', $participanteId)
            ->where('demo', $emTeste)
            ->first();

        if ($checkin === null) {
            throw ValidationException::withMessages([
                'checkin' => 'Esta pessoa não consta como presente.',
            ]);
        }

        $this->registros->cerimonialCheckinDesfeito(
            $projeto,
            $admin,
            $checkin->nome,
            $checkin->papelLabel(),
            trim($justificativa),
            $emTeste,
            $evento->nome,
        );

        $checkin->delete();

        // A mesma pessoa nos outros papéis sai junto, com a mesma justificativa:
        // quem saiu da sala (ou foi marcado por engano) saiu em todos eles.
        $pessoa = collect($this->participantesDo($projeto))->firstWhere('chave', $chave);
        $desfeitos = [];

        foreach ($pessoa === null ? [] : $this->outrosVinculos($projeto, [$pessoa], $evento, $admin, $teste) as [$outro, $vinculo]) {
            $linha = CerimonialCheckin::where('projeto_id', $outro->id)
                ->where('evento_id', $evento->id)
                ->where('papel', $vinculo['papel'])
                ->where('participante_id', $vinculo['participante_id'])
                ->where('demo', $emTeste)
                ->first();

            if ($linha === null) {
                continue;
            }

            $this->registros->cerimonialCheckinDesfeito(
                $outro,
                $admin,
                $linha->nome,
                $linha->papelLabel(),
                trim($justificativa),
                $emTeste,
                $evento->nome,
                $pessoa['papel_label'].' de "'.$projeto->titulo.'"',
            );
            $linha->delete();

            $desfeitos[] = $this->resumoVinculo($outro, $vinculo, true);
        }

        return $this->ficha($projeto, $evento, $admin, $teste) + ['desfeitos_juntos' => $desfeitos];
    }

    // -----------------------------------------------------------------
    // Visão geral
    // -----------------------------------------------------------------

    /**
     * Os cards do painel — **só os números**.
     *
     * O detalhe nominal de cada card mora em {@see detalhe()}, e não aqui, por
     * causa do polling: o painel se recarrega sozinho de meio em meio minuto, e
     * mandar duas mil pessoas em cada volta para mostrar cinco números seria
     * pagar a lista inteira o tempo todo.
     *
     * @return array<string, mixed>
     */
    public function visaoGeral(?User $user = null, bool $teste = false, ?CerimonialEvento $evento = null): array
    {
        $contexto = $this->contexto($user, $teste, $evento);

        return [
            'pessoas' => $contexto['pessoas'],
            'projetos' => $contexto['projetos'],
            'premiados' => $contexto['premiados'],
            'medalhas' => $contexto['medalhas'],
            'credenciais' => $contexto['credenciais'],
            'atualizado_em' => now()->format('H:i:s'),
        ];
    }

    /**
     * A lista nominal de um card: quem já chegou e quem falta.
     *
     * @return array<string, mixed>
     */
    public function detalhe(string $card, ?User $user = null, bool $teste = false, ?CerimonialEvento $evento = null): array
    {
        $participantes = $this->participantes($user, $teste, $evento);
        $presentes = $this->checkinsPorChave($user, $teste, $evento);
        $premiados = $this->projetosPremiados();

        $daPessoa = fn (array $p) => [
            'chave' => $p['chave'],
            'nome' => $p['nome'],
            'papel' => $p['papel'],
            'papel_label' => $p['papel_label'],
            'projeto_id' => $p['projeto_id'],
            'projeto_titulo' => $p['projeto_titulo'],
            'categoria' => $p['categoria'],
            'checkin_em' => $presentes[$p['projeto_id'].':'.$p['chave']] ?? null,
        ];

        if ($card === 'pessoas' || $card === 'medalhas') {
            $lista = $card === 'medalhas'
                ? $participantes->filter(fn (array $p) => isset($premiados[$p['projeto_id']]))
                : $participantes;

            [$chegaram, $faltam] = $lista
                ->partition(fn (array $p) => isset($presentes[$p['projeto_id'].':'.$p['chave']]));

            return [
                'presentes' => $chegaram->map($daPessoa)->values()->all(),
                'faltantes' => $faltam->map($daPessoa)->values()->all(),
            ];
        }

        // projetos / premiados / credenciais: a unidade é o projeto.
        $porProjeto = $this->porProjeto($participantes, $presentes);

        if ($card === 'premiados' || $card === 'credenciais') {
            $porProjeto = $porProjeto->filter(fn (array $p) => isset($premiados[$p['id']]));
        }

        $daLinha = function (array $p) use ($premiados, $card) {
            $premiacoes = $premiados[$p['id']] ?? [];

            return [
                'id' => $p['id'],
                'titulo' => $p['titulo'],
                'categoria' => $p['categoria'],
                'area' => $p['area'],
                'escola' => $p['escola'],
                'presentes' => $p['presentes'],
                'total' => $p['total'],
                'completo' => $p['completo'],
                'premiacoes' => array_values(array_map(fn (array $c) => $c['nome'], $premiacoes)),
                // Só no card de credenciais: quantos objetos este projeto tira da mesa.
                'credenciais' => $card === 'credenciais'
                    ? count(array_filter($premiacoes, fn (array $c) => $c['tipo'] === TipoCredencial::Credencial->value))
                    : null,
            ];
        };

        [$chegaram, $faltam] = $porProjeto->partition(fn (array $p) => $p['presentes'] > 0);

        return [
            'presentes' => $chegaram->map($daLinha)->values()->all(),
            'faltantes' => $faltam->map($daLinha)->values()->all(),
        ];
    }

    /**
     * A visualização dos **premiados**: um cartão por projeto premiado, com
     * cada integrante e se ele já chegou.
     *
     * @return list<array<string, mixed>>
     */
    public function premiados(?User $user = null, bool $teste = false, ?CerimonialEvento $evento = null): array
    {
        $presentes = $this->checkinsPorChave($user, $teste, $evento);
        $premiados = $this->projetosPremiados();

        return $this->participantes($user, $teste, $evento)
            ->filter(fn (array $p) => isset($premiados[$p['projeto_id']]))
            ->groupBy('projeto_id')
            ->map(function (Collection $pessoas, int $projetoId) use ($presentes, $premiados) {
                $primeiro = $pessoas->first();
                $lista = $pessoas->map(fn (array $p) => [
                    'chave' => $p['chave'],
                    'nome' => $p['nome'],
                    'papel' => $p['papel'],
                    'papel_label' => $p['papel_label'],
                    'presente' => isset($presentes[$p['projeto_id'].':'.$p['chave']]),
                    'checkin_em' => $presentes[$p['projeto_id'].':'.$p['chave']] ?? null,
                ])->values()->all();

                $chegaram = count(array_filter($lista, fn (array $p) => $p['presente']));

                return [
                    'id' => $projetoId,
                    'titulo' => $primeiro['projeto_titulo'],
                    'categoria' => $primeiro['categoria'],
                    'area' => $primeiro['area'],
                    'escola' => $primeiro['escola'],
                    'premiacoes' => array_values($premiados[$projetoId]),
                    'pessoas' => $lista,
                    'presentes' => $chegaram,
                    'total' => count($lista),
                    'completo' => $chegaram === count($lista),
                ];
            })
            ->sortBy(fn (array $p) => $this->normalizar($p['titulo']))
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Interno
    // -----------------------------------------------------------------

    public function ehFinalista(Projeto $projeto, ?User $user = null, bool $teste = false): bool
    {
        $vigente = $this->listaFinal($user, $teste);

        return $vigente !== null && $vigente->projetos()->whereKey($projeto->id)->exists();
    }

    /**
     * Os números dos cinco cards, todos derivados da mesma varredura.
     *
     * @return array<string, mixed>
     */
    private function contexto(?User $user, bool $teste, ?CerimonialEvento $evento): array
    {
        $participantes = $this->participantes($user, $teste, $evento);
        $presentes = $this->checkinsPorChave($user, $teste, $evento);
        // Só os premiados do público: a outra cerimônia separa as medalhas dela.
        $premiados = array_intersect_key(
            $this->projetosPremiados(),
            $participantes->pluck('projeto_id')->flip()->all(),
        );

        $chegou = fn (array $p) => isset($presentes[$p['projeto_id'].':'.$p['chave']]);

        $porPapel = fn (Collection $pessoas) => [
            'alunos' => $pessoas->where('papel', CodigoParticipante::PAPEL_ALUNO)->count(),
            'orientadores' => $pessoas->where('papel', CodigoParticipante::PAPEL_ORIENTADOR)->count(),
            'coorientadores' => $pessoas->where('papel', CodigoParticipante::PAPEL_COORIENTADOR)->count(),
        ];

        $chegaram = $participantes->filter($chegou);
        $projetos = $this->porProjeto($participantes, $presentes);
        $premiadosPresentes = $projetos->filter(fn (array $p) => isset($premiados[$p['id']]) && $p['presentes'] > 0);

        // A medalha segue a pessoa: quem é de projeto premiado e já entrou.
        $medalhas = $chegaram->filter(fn (array $p) => isset($premiados[$p['projeto_id']]));

        // A credencial segue o projeto, e só o que tem objeto na mesa — prêmio
        // se anuncia, não se separa.
        $credenciais = $premiadosPresentes->sum(fn (array $p) => count(array_filter(
            $premiados[$p['id']],
            fn (array $c) => $c['tipo'] === TipoCredencial::Credencial->value,
        )));

        $credenciaisTotal = collect($premiados)->sum(fn (array $cs) => count(array_filter(
            $cs,
            fn (array $c) => $c['tipo'] === TipoCredencial::Credencial->value,
        )));

        return [
            'pessoas' => [
                'presentes' => $chegaram->count(),
                'total' => $participantes->count(),
                'faltam' => $participantes->count() - $chegaram->count(),
                'por_papel' => $porPapel($chegaram),
                'por_papel_total' => $porPapel($participantes),
            ],
            'projetos' => [
                // "Parcial" e "completo" são números diferentes e ambos importam:
                // o palco chama a equipe inteira, a porta conta quem entrou.
                'completos' => $projetos->where('completo', true)->count(),
                'parciais' => $projetos->filter(fn (array $p) => $p['presentes'] > 0 && ! $p['completo'])->count(),
                'presentes' => $projetos->filter(fn (array $p) => $p['presentes'] > 0)->count(),
                'total' => $projetos->count(),
                'faltam' => $projetos->filter(fn (array $p) => $p['presentes'] === 0)->count(),
            ],
            'premiados' => [
                'presentes' => $premiadosPresentes->count(),
                'completos' => $premiadosPresentes->where('completo', true)->count(),
                'total' => count($premiados),
                'faltam' => count($premiados) - $premiadosPresentes->count(),
            ],
            'medalhas' => [
                'separar' => $medalhas->count(),
                'total' => $participantes->filter(fn (array $p) => isset($premiados[$p['projeto_id']]))->count(),
                'por_papel' => $porPapel($medalhas),
            ],
            'credenciais' => [
                'separar' => $credenciais,
                'total' => $credenciaisTotal,
            ],
        ];
    }

    /**
     * Os participantes dos projetos finalistas do público do evento (ou de
     * todos os eventos), uma linha por pessoa.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function participantes(?User $user, bool $teste, ?CerimonialEvento $evento): Collection
    {
        $lista = $this->listaFinal($user, $teste);
        $categorias = $this->categoriasDoPublico($evento);

        if ($lista === null || $categorias === []) {
            return collect();
        }

        return Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
            ->whereIn('categoria', $categorias)
            ->with(['alunos', 'coorientador', 'user.orientadorProfile', 'area:id,nome', 'instituicao.cidade.estado'])
            ->orderBy('titulo')
            ->get()
            ->flatMap(fn (Projeto $projeto) => array_map(fn (array $pessoa) => $pessoa + [
                'projeto_id' => $projeto->id,
                'projeto_titulo' => $projeto->titulo,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                'escola' => $this->escola($projeto),
            ], $this->participantesDo($projeto)));
    }

    /**
     * Marca a entrada da mesma pessoa nos **outros** vínculos dela dentro do
     * evento — o professor que orienta um projeto e coorienta outro entrou na
     * sala uma vez, com um crachá só.
     *
     * @param  list<array<string, mixed>>  $pessoas  as que acabaram de ser marcadas em `$origem`
     * @return list<array<string, mixed>> cada vínculo alcançado, e se já constava
     */
    private function marcarVinculos(Projeto $origem, array $pessoas, User $admin, CerimonialEvento $evento, bool $teste): array
    {
        $emTeste = $this->emTeste($admin, $teste);
        $resultado = [];

        foreach ($pessoas as $pessoa) {
            foreach ($this->outrosVinculos($origem, [$pessoa], $evento, $admin, $teste) as [$projeto, $vinculo]) {
                $novos = $this->gravar(
                    $projeto,
                    [$vinculo],
                    $admin,
                    $evento,
                    $emTeste,
                    $pessoa['papel_label'].' de "'.$origem->titulo.'"',
                );

                $resultado[] = $this->resumoVinculo($projeto, $vinculo, $novos === []);
            }
        }

        return $resultado;
    }

    /**
     * Os outros vínculos das pessoas dadas — mesmo CPF (ou mesmo e-mail e nome)
     * em outro projeto, ou noutro papel do mesmo projeto — entre os finalistas
     * do público do evento. Fica fora o próprio vínculo de onde se partiu.
     *
     * A busca vai direto às três tabelas de pessoas pelo CPF e pelo e-mail, em
     * vez de montar a lista inteira de finalistas: ela roda a cada crachá lido
     * na porta.
     *
     * @param  list<array<string, mixed>>  $pessoas
     * @return list<array{0: Projeto, 1: array<string, mixed>}>
     */
    private function outrosVinculos(Projeto $origem, array $pessoas, CerimonialEvento $evento, User $user, bool $teste): array
    {
        $lista = $this->listaFinal($user, $teste);
        $cpfs = array_values(array_filter(array_map(fn (array $p) => $this->cpfDaPessoa($p['cpf'] ?? null), $pessoas)));
        $emails = array_values(array_filter(array_map(fn (array $p) => $this->emailDaPessoa($p['email'] ?? null), $pessoas)));

        if ($lista === null || ($cpfs === [] && $emails === [])) {
            return [];
        }

        $casa = function ($q) use ($cpfs, $emails) {
            $q->where(function ($w) use ($cpfs, $emails) {
                if ($cpfs !== []) {
                    $w->orWhereIn('cpf', $cpfs);
                }
                if ($emails !== []) {
                    $w->orWhereIn(DB::raw('lower(email)'), $emails);
                }
            });
        };

        $projetos = Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
            ->whereIn('categoria', $evento->categorias ?? [])
            ->where(function ($q) use ($casa, $cpfs, $emails) {
                $q->whereHas('alunos', $casa)
                    ->orWhereHas('coorientador', $casa)
                    ->orWhereHas('user', function ($u) use ($cpfs, $emails) {
                        $u->where(function ($w) use ($cpfs, $emails) {
                            if ($emails !== []) {
                                $w->orWhereIn(DB::raw('lower(email)'), $emails);
                            }
                            if ($cpfs !== []) {
                                $w->orWhereHas('orientadorProfile', fn ($p) => $p->whereIn('cpf', $cpfs));
                            }
                        });
                    });
            })
            ->with(['alunos', 'coorientador', 'user.orientadorProfile'])
            ->get();

        $partida = array_map(fn (array $p) => $origem->id.':'.$p['chave'], $pessoas);
        $achados = [];

        foreach ($projetos as $projeto) {
            foreach ($this->participantesDo($projeto) as $candidato) {
                if (in_array($projeto->id.':'.$candidato['chave'], $partida, true)) {
                    continue;
                }

                foreach ($pessoas as $pessoa) {
                    if ($this->mesmaPessoa($pessoa, $candidato)) {
                        $achados[$projeto->id.':'.$candidato['chave']] = [$projeto, $candidato];
                    }
                }
            }
        }

        return array_values($achados);
    }

    /**
     * Duas linhas de participante são a mesma pessoa?
     *
     * Com CPF dos dois lados, só o CPF decide — é o documento. Sem ele (o
     * cadastro manual deixa o CPF em branco), vale o e-mail **e** o nome: o
     * e-mail sozinho não basta, porque estudante sem e-mail próprio às vezes
     * é cadastrado com o do professor.
     */
    private function mesmaPessoa(array $a, array $b): bool
    {
        $cpfA = $this->cpfDaPessoa($a['cpf'] ?? null);
        $cpfB = $this->cpfDaPessoa($b['cpf'] ?? null);

        if ($cpfA !== null && $cpfB !== null) {
            return $cpfA === $cpfB;
        }

        $emailA = $this->emailDaPessoa($a['email'] ?? null);

        return $emailA !== null
            && $emailA === $this->emailDaPessoa($b['email'] ?? null)
            && $this->normalizar($a['nome']) === $this->normalizar($b['nome']);
    }

    /** O CPF que identifica alguém: onze dígitos que não sejam todos iguais. */
    private function cpfDaPessoa(?string $cpf): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $cpf) ?? '';

        return strlen($digitos) === 11 && ! preg_match('/^(\d)\1{10}$/', $digitos) ? $digitos : null;
    }

    private function emailDaPessoa(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /** @return array<string, mixed> */
    private function resumoVinculo(Projeto $projeto, array $pessoa, bool $jaConstava): array
    {
        return [
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'chave' => $pessoa['chave'],
            'nome' => $pessoa['nome'],
            'papel_label' => $pessoa['papel_label'],
            'ja_presente' => $jaConstava,
        ];
    }

    /**
     * As categorias do público: as do evento, ou a soma das de todos os
     * eventos da edição ("Todos").
     *
     * @return list<string>
     */
    private function categoriasDoPublico(?CerimonialEvento $evento): array
    {
        if ($evento !== null) {
            return $evento->categorias ?? [];
        }

        return $this->eventosDaEdicao()->get(['categorias'])
            ->flatMap(fn (CerimonialEvento $e) => $e->categorias ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /** @return Builder<CerimonialEvento> */
    private function eventosDaEdicao(): Builder
    {
        return CerimonialEvento::where('edicao_id', Edicao::atual()?->id);
    }

    /**
     * As pessoas de um projeto, na ordem em que a ficha as mostra.
     *
     * @return list<array<string, mixed>>
     */
    private function participantesDo(Projeto $projeto): array
    {
        $projeto->loadMissing(['alunos', 'coorientador', 'user.orientadorProfile']);
        $pessoas = [];

        foreach ($projeto->alunos as $aluno) {
            $pessoas[] = $this->pessoa(CodigoParticipante::PAPEL_ALUNO, $aluno->id, $aluno->nome, $aluno->cpf, $aluno->email);
        }

        if ($projeto->user !== null) {
            $pessoas[] = $this->pessoa(
                CodigoParticipante::PAPEL_ORIENTADOR,
                $projeto->user->id,
                $projeto->user->name,
                $projeto->user->orientadorProfile?->cpf,
                $projeto->user->email,
            );
        }

        if ($projeto->coorientador !== null) {
            $pessoas[] = $this->pessoa(
                CodigoParticipante::PAPEL_COORIENTADOR,
                $projeto->coorientador->id,
                $projeto->coorientador->nome,
                $projeto->coorientador->cpf,
                $projeto->coorientador->email,
            );
        }

        return $pessoas;
    }

    /** @return array<string, mixed> */
    private function pessoa(string $papel, int $id, string $nome, ?string $cpf, ?string $email = null): array
    {
        return [
            'chave' => $papel.$id,
            'papel' => $papel,
            'papel_label' => CodigoParticipante::papelLabel($papel),
            'participante_id' => $id,
            'nome' => $nome,
            'cpf' => $cpf,
            'email' => $email,
        ];
    }

    /**
     * Os check-ins já registrados, indexados por "projeto:papelId" — a chave
     * com que toda a tela pergunta "esta pessoa chegou?".
     *
     * @return array<string, string> valor = a hora do check-in, para a lista nominal
     */
    private function checkinsPorChave(?User $user, bool $teste, ?CerimonialEvento $evento): array
    {
        $lista = $this->listaFinal($user, $teste);

        if ($lista === null) {
            return [];
        }

        return CerimonialCheckin::whereIn('projeto_id', $lista->projetos()->select('projetos.id'))
            ->where('demo', $this->emTeste($user, $teste))
            ->when(
                $evento !== null,
                fn ($q) => $q->where('evento_id', $evento->id),
                fn ($q) => $q->whereIn('evento_id', $this->eventosDaEdicao()->select('id')),
            )
            // Em "Todos", quem entrou nas duas cerimônias vale pela primeira
            // entrada: a mais recente vem antes e é sobrescrita.
            ->orderByDesc('checkin_em')
            ->get()
            ->mapWithKeys(fn (CerimonialCheckin $c) => [
                $c->projeto_id.':'.$c->chave() => $c->checkin_em?->format('d/m/Y H:i'),
            ])
            ->all();
    }

    /**
     * Agrupa os participantes por projeto, com quantos já chegaram.
     *
     * @param  Collection<int, array<string, mixed>>  $participantes
     * @param  array<string, string>  $presentes
     * @return Collection<int, array<string, mixed>>
     */
    private function porProjeto(Collection $participantes, array $presentes): Collection
    {
        return $participantes
            ->groupBy('projeto_id')
            ->map(function (Collection $pessoas, int $projetoId) use ($presentes) {
                $primeiro = $pessoas->first();
                $chegaram = $pessoas->filter(
                    fn (array $p) => isset($presentes[$p['projeto_id'].':'.$p['chave']]),
                )->count();

                return [
                    'id' => $projetoId,
                    'titulo' => $primeiro['projeto_titulo'],
                    'categoria' => $primeiro['categoria'],
                    'area' => $primeiro['area'],
                    'escola' => $primeiro['escola'],
                    'presentes' => $chegaram,
                    'total' => $pessoas->count(),
                    'completo' => $chegaram === $pessoas->count(),
                ];
            })
            ->values();
    }

    /**
     * Os projetos premiados da edição: os que receberam credencial **ou**
     * prêmio ativo.
     *
     * @return array<int, list<array{id:int, nome:string, tipo:string, tipo_label:string}>>
     */
    private function projetosPremiados(): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        $premiados = [];

        $credenciais = Credencial::where('edicao_id', $edicao->id)
            ->where('ativa', true)
            ->with('projetos:id')
            ->get();

        foreach ($credenciais as $credencial) {
            foreach ($credencial->projetos as $projeto) {
                $premiados[$projeto->id][] = [
                    'id' => $credencial->id,
                    'nome' => $credencial->nome,
                    'tipo' => $credencial->tipo->value,
                    'tipo_label' => $credencial->tipo->label(),
                ];
            }
        }

        return $premiados;
    }

    /** @return list<array<string, mixed>> */
    private function premiacoesDoProjeto(int $projetoId): array
    {
        return array_values($this->projetosPremiados()[$projetoId] ?? []);
    }

    /** "Escola / Cidade - UF", como na lista final. */
    private function escola(Projeto $projeto): string
    {
        $instituicao = $projeto->instituicao;
        $local = trim(implode(' - ', array_filter([
            $instituicao?->cidade?->nome,
            $instituicao?->cidade?->estado?->uf,
        ])));

        return trim(implode(' / ', array_filter([$instituicao?->nome, $local])));
    }

    /** Sem acento e em minúsculas: a busca da fila não pede o acento certo. */
    private function normalizar(string $texto): string
    {
        $sem = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return mb_strtolower($sem === false ? $texto : $sem);
    }
}
