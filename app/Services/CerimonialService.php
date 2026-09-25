<?php

namespace App\Services;

use App\Enums\TipoCredencial;
use App\Models\CerimonialCheckin;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\CodigoParticipante;
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
    // Balcão: achar a pessoa
    // -----------------------------------------------------------------

    /**
     * Resolve o crachá lido (QR ou código de barras) e diz de quem ele é.
     *
     * A conferência é a mesma do credenciamento, pelas mesmas três razões:
     * **formato** (etiqueta amassada, QR de outro evento), **finalista** (o
     * projeto não está na lista que vale para esta pessoa) e **pessoa** (papel,
     * id e os três dígitos do CPF — é o que pega o crachá trocado entre
     * colegas). A ficha do projeto é que abre em seguida: na cerimônia a equipe
     * costuma chegar junta, e marcar um a um seria obrigar quatro leituras.
     *
     * @return array<string, mixed>
     */
    public function resolverCodigo(string $codigo, ?User $user = null, bool $teste = false): array
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

        if (substr((string) preg_replace('/\D/', '', (string) $pessoa['cpf']), 0, 3) !== $lido['cpf3']) {
            throw ValidationException::withMessages([
                'codigo' => 'A etiqueta não confere com o cadastro desta pessoa. Confira se o crachá é dela mesma.',
            ]);
        }

        return [
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'participante' => [
                'chave' => $pessoa['chave'],
                'nome' => $pessoa['nome'],
                'papel' => $pessoa['papel'],
                'papel_label' => $pessoa['papel_label'],
            ],
        ];
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
    public function buscar(string $termo, ?User $user = null, bool $teste = false, int $limite = 25): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < 2) {
            return [];
        }

        $digitos = preg_replace('/\D/', '', $termo);
        $alvo = $this->normalizar($termo);
        $presentes = $this->checkinsPorChave($user, $teste);

        return $this->participantes($user, $teste)
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
    public function ficha(Projeto $projeto, ?User $user = null, bool $teste = false): array
    {
        if (! $this->ehFinalista($projeto, $user, $teste)) {
            abort(404, 'Este projeto não está na lista final vigente.');
        }

        $projeto->loadMissing([
            'alunos', 'coorientador', 'user', 'area', 'instituicao.cidade.estado',
        ]);

        $checkins = CerimonialCheckin::where('projeto_id', $projeto->id)
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
    public function registrar(Projeto $projeto, array $chaves, User $admin, bool $teste = false): array
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

        $edicao = Edicao::atual();
        $pessoas = collect($this->participantesDo($projeto))->keyBy('chave');
        $desconhecidas = array_values(array_diff($chaves, $pessoas->keys()->all()));

        if ($desconhecidas !== []) {
            throw ValidationException::withMessages([
                'participantes' => 'Alguma das pessoas marcadas não está mais na equipe deste projeto.',
            ]);
        }

        $novos = DB::transaction(function () use ($chaves, $pessoas, $projeto, $admin, $edicao, $emTeste) {
            $criados = [];

            foreach ($chaves as $chave) {
                $pessoa = $pessoas->get($chave);

                $checkin = CerimonialCheckin::firstOrCreate(
                    [
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
            );
        }

        return $this->ficha($projeto, $admin, $teste);
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
    public function desfazer(Projeto $projeto, string $chave, string $justificativa, User $admin, bool $teste = false): array
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
        );

        $checkin->delete();

        return $this->ficha($projeto, $admin, $teste);
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
    public function visaoGeral(?User $user = null, bool $teste = false): array
    {
        $contexto = $this->contexto($user, $teste);

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
    public function detalhe(string $card, ?User $user = null, bool $teste = false): array
    {
        $participantes = $this->participantes($user, $teste);
        $presentes = $this->checkinsPorChave($user, $teste);
        $premiados = $this->projetosPremiados();

        $daPessoa = fn (array $p) => [
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
    public function premiados(?User $user = null, bool $teste = false): array
    {
        $presentes = $this->checkinsPorChave($user, $teste);
        $premiados = $this->projetosPremiados();

        return $this->participantes($user, $teste)
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
    private function contexto(?User $user, bool $teste): array
    {
        $participantes = $this->participantes($user, $teste);
        $presentes = $this->checkinsPorChave($user, $teste);
        $premiados = $this->projetosPremiados();

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
     * Os participantes de todos os projetos finalistas, uma linha por pessoa.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function participantes(?User $user, bool $teste): Collection
    {
        $lista = $this->listaFinal($user, $teste);

        if ($lista === null) {
            return collect();
        }

        return Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
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
     * As pessoas de um projeto, na ordem em que a ficha as mostra.
     *
     * @return list<array<string, mixed>>
     */
    private function participantesDo(Projeto $projeto): array
    {
        $projeto->loadMissing(['alunos', 'coorientador', 'user.orientadorProfile']);
        $pessoas = [];

        foreach ($projeto->alunos as $aluno) {
            $pessoas[] = $this->pessoa(CodigoParticipante::PAPEL_ALUNO, $aluno->id, $aluno->nome, $aluno->cpf);
        }

        if ($projeto->user !== null) {
            $pessoas[] = $this->pessoa(
                CodigoParticipante::PAPEL_ORIENTADOR,
                $projeto->user->id,
                $projeto->user->name,
                $projeto->user->orientadorProfile?->cpf,
            );
        }

        if ($projeto->coorientador !== null) {
            $pessoas[] = $this->pessoa(
                CodigoParticipante::PAPEL_COORIENTADOR,
                $projeto->coorientador->id,
                $projeto->coorientador->nome,
                $projeto->coorientador->cpf,
            );
        }

        return $pessoas;
    }

    /** @return array<string, mixed> */
    private function pessoa(string $papel, int $id, string $nome, ?string $cpf): array
    {
        return [
            'chave' => $papel.$id,
            'papel' => $papel,
            'papel_label' => CodigoParticipante::papelLabel($papel),
            'participante_id' => $id,
            'nome' => $nome,
            'cpf' => $cpf,
        ];
    }

    /**
     * Os check-ins já registrados, indexados por "projeto:papelId" — a chave
     * com que toda a tela pergunta "esta pessoa chegou?".
     *
     * @return array<string, string> valor = a hora do check-in, para a lista nominal
     */
    private function checkinsPorChave(?User $user, bool $teste): array
    {
        $lista = $this->listaFinal($user, $teste);

        if ($lista === null) {
            return [];
        }

        return CerimonialCheckin::whereIn('projeto_id', $lista->projetos()->select('projetos.id'))
            ->where('demo', $this->emTeste($user, $teste))
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
