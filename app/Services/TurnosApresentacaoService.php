<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\Turno;
use App\Models\Cidade;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\RegrasTurnos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mapa do Evento → **Turnos de Apresentação**.
 *
 * A feira tem mais finalistas do que estandes: o mesmo estande recebe um projeto
 * de manhã (turno A) e outro à tarde (turno B). Dividir quem apresenta em cada
 * turno era trabalho de planilha, e a planilha não sabia das justificativas —
 * aluno que presta vestibular no sábado de manhã, equipe que chega de ônibus ao
 * meio-dia.
 *
 * A divisão nasce da **lista final vigente** (é ela que define finalista) e
 * segue as regras que o admin ligar, **na ordem de prioridade** do
 * {@see RegrasTurnos}: a primeira regra que alcança um projeto decide o turno
 * dele. Quem não é alcançado por regra nenhuma entra no **equilíbrio**: os dois
 * turnos terminam com ocupação parecida.
 *
 * Duas decisões que valem explicar:
 *
 * - **capacidade é teto, não sugestão.** Se o turno preferido pela regra está
 *   cheio, o projeto vai para o outro e a resposta diz quais foram — melhor um
 *   aviso do que uma lista que não cabe no ginásio.
 * - **não cabendo nos dois turnos, a geração é recusada.** Gerar uma lista com
 *   projetos sem lugar é entregar o problema disfarçado de solução; o admin
 *   precisa resolver a capacidade antes.
 *
 * Gerar de novo **substitui** a lista inteira (só a última vale), inclusive as
 * trocas manuais — a tela avisa quantas serão perdidas antes de refazer.
 */
class TurnosApresentacaoService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /**
     * O que a tela precisa para desenhar: as regras salvas, a lista final
     * vigente, o catálogo de regras e a lista gerada (se houver).
     *
     * @return array<string, mixed>
     */
    public function painel(): array
    {
        $edicao = Edicao::atual();
        $regras = RegrasTurnos::deArray($edicao?->turnos_config);
        $lista = $this->listaVigente($edicao);
        $finalistas = $this->finalistas($lista);

        return [
            'edicao' => $edicao ? ['id' => $edicao->id, 'nome' => $edicao->nome] : null,
            'lista_final' => $lista === null ? null : [
                'id' => $lista->id,
                'nome' => $lista->nome,
                'versao' => (int) $lista->versao,
                'projetos' => $finalistas->count(),
            ],
            'config' => $regras->paraArray(),
            'catalogo' => RegrasTurnos::catalogo(),
            'origens' => RegrasTurnos::origens(),
            'turnos' => Turno::opcoes(),
            'gerado_em' => $edicao?->turnos_gerados_em?->toIso8601String(),
            'gerado_por' => $edicao?->turnos_gerados_por
                ? User::find($edicao->turnos_gerados_por)?->name
                : null,
            'lista' => $this->listar(),
        ];
    }

    /**
     * Os finalistas para a busca das listas de regra (vestibular e
     * justificativa): projeto, escola e as pessoas, para achar por participante.
     *
     * @return list<array<string, mixed>>
     */
    public function opcoesDeBusca(string $termo = '', int $limite = 20): array
    {
        $termo = $this->chave($termo);
        $finalistas = $this->finalistas($this->listaVigente(Edicao::atual()));

        return $finalistas
            ->map(function (Projeto $p) {
                $pessoas = $p->alunos->pluck('nome')
                    ->push($p->user?->name)
                    ->push($p->coorientador?->nome)
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $p->id,
                    'titulo' => $p->titulo,
                    'categoria' => $p->categoria?->label(),
                    'area' => $p->area?->nome,
                    'escola' => $p->instituicao?->nome,
                    'cidade' => $this->cidadeDe($p)?->nome,
                    'uf' => $this->ufDe($p),
                    'pessoas' => $pessoas,
                ];
            })
            // A busca é por projeto OU por participante: no balcão da
            // organização o que chega é "o aluno Fulano presta vestibular",
            // e ninguém sabe o título do trabalho dele de cabeça.
            ->filter(function (array $p) use ($termo) {
                if ($termo === '') {
                    return true;
                }

                $alvo = $this->chave($p['titulo'].' '.implode(' ', $p['pessoas']).' '.($p['escola'] ?? ''));

                return str_contains($alvo, $termo);
            })
            ->take($limite)
            ->values()
            ->all();
    }

    /**
     * Guarda as capacidades e as regras sem gerar nada — é o rascunho da
     * configuração, que sobrevive entre uma geração e outra.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function salvarConfig(array $config): array
    {
        $edicao = $this->edicaoOuFalha();
        $regras = new RegrasTurnos($config);

        $edicao->update(['turnos_config' => $regras->paraArray()]);

        return $regras->paraArray();
    }

    /**
     * Gera (ou regera) a divisão dos finalistas entre os turnos.
     *
     * @param  array<string, mixed>  $config  capacidades + regras vindas da tela
     * @return array<string, mixed>
     */
    public function gerar(array $config, User $admin): array
    {
        $edicao = $this->edicaoOuFalha();
        $regras = new RegrasTurnos($config);

        $lista = $this->listaVigente($edicao);

        if ($lista === null) {
            throw ValidationException::withMessages([
                'lista_final' => 'Não há lista final oficial vigente. Publique a lista antes de dividir os turnos.',
            ]);
        }

        $finalistas = $this->finalistas($lista);

        if ($finalistas->isEmpty()) {
            throw ValidationException::withMessages([
                'lista_final' => 'A lista final vigente está vazia.',
            ]);
        }

        if ($finalistas->count() > $regras->capacidadeTotal()) {
            throw ValidationException::withMessages([
                'capacidade' => sprintf(
                    'Os dois turnos somam %d estandes e a lista final tem %d projetos. Faltam %d lugares.',
                    $regras->capacidadeTotal(),
                    $finalistas->count(),
                    $finalistas->count() - $regras->capacidadeTotal(),
                ),
            ]);
        }

        $resultado = $this->distribuir($finalistas, $regras);

        DB::transaction(function () use ($edicao, $regras, $resultado, $admin) {
            // Só a última lista vale: a anterior sai inteira, trocas manuais
            // incluídas — a tela avisa disso antes de chamar aqui.
            TurnoApresentacao::where('edicao_id', $edicao->id)->delete();

            foreach ($resultado['alocacoes'] as $alocacao) {
                TurnoApresentacao::create([
                    'edicao_id' => $edicao->id,
                    'projeto_id' => $alocacao['projeto']->id,
                    'turno' => $alocacao['turno']->value,
                    'regra' => $alocacao['regra'],
                    'origem' => $alocacao['origem'],
                    'manual' => false,
                ]);
            }

            $edicao->update([
                'turnos_config' => $regras->paraArray(),
                'turnos_gerados_em' => now(),
                'turnos_gerados_por' => $admin->id,
            ]);

            $this->registros->turnosGerados($admin, [
                'turno_a' => $resultado['totais'][Turno::A->value],
                'turno_b' => $resultado['totais'][Turno::B->value],
                'capacidade' => $regras->capacidade(),
                'regras' => $this->regrasLigadas($regras),
                'realocados' => count($resultado['realocados']),
            ]);
        });

        return [
            'lista' => $this->listar(),
            'realocados' => $resultado['realocados'],
            'totais' => $resultado['totais'],
        ];
    }

    /**
     * A lista em vigor, agrupada por turno e na ordem da lista final
     * (categoria → área → título).
     *
     * @return array<string, mixed>
     */
    public function listar(): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return $this->listaVazia();
        }

        $alocacoes = TurnoApresentacao::where('edicao_id', $edicao->id)
            ->with(['projeto.area:id,nome', 'projeto.instituicao.cidade.estado', 'projeto.user:id,name'])
            ->get()
            ->filter(fn (TurnoApresentacao $t) => $t->projeto !== null);

        if ($alocacoes->isEmpty()) {
            return $this->listaVazia();
        }

        $ordenadas = $this->ordenar($alocacoes);

        $porTurno = [];

        foreach (Turno::cases() as $turno) {
            $doTurno = $ordenadas->filter(fn (TurnoApresentacao $t) => $t->turno === $turno);

            $porTurno[$turno->value] = [
                'turno' => $turno->value,
                'label' => $turno->label(),
                'total' => $doTurno->count(),
                'projetos' => $doTurno->map(fn (TurnoApresentacao $t) => $this->linha($t))->values()->all(),
            ];
        }

        return [
            'gerada' => true,
            'total' => $ordenadas->count(),
            'turnos' => $porTurno,
        ];
    }

    /**
     * O admin move um projeto de turno à mão. Sem justificativa (é rearranjo de
     * logística), mas com registro.
     *
     * @return array<string, mixed>
     */
    public function mover(int $projetoId, Turno $destino, User $admin): array
    {
        $edicao = $this->edicaoOuFalha();

        $alocacao = TurnoApresentacao::where('edicao_id', $edicao->id)
            ->where('projeto_id', $projetoId)
            ->first();

        if ($alocacao === null) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto não está na lista de turnos em vigor.',
            ]);
        }

        if ($alocacao->turno === $destino) {
            return $this->listar();
        }

        $regras = RegrasTurnos::deArray($edicao->turnos_config);
        $ocupacao = TurnoApresentacao::where('edicao_id', $edicao->id)
            ->where('turno', $destino->value)
            ->count();

        if ($ocupacao >= $regras->capacidadeDe($destino)) {
            throw ValidationException::withMessages([
                'turno' => sprintf(
                    'O %s já está com os %d estandes ocupados. Tire outro projeto de lá antes.',
                    $destino->label(),
                    $regras->capacidadeDe($destino),
                ),
            ]);
        }

        $origem = $alocacao->turno;

        DB::transaction(function () use ($alocacao, $destino, $origem, $admin) {
            $alocacao->update(['turno' => $destino->value, 'manual' => true]);

            $this->registros->turnoProjetoMovido(
                $alocacao->projeto,
                $admin,
                $origem->label(),
                $destino->label(),
            );
        });

        return $this->listar();
    }

    // --- Exportação ------------------------------------------------------

    /** O TXT: um bloco por turno, na mesma ordem da tela. */
    public function exportarTxt(): string
    {
        $lista = $this->listar();
        $linhas = [];

        foreach ($lista['turnos'] as $turno) {
            $linhas[] = mb_strtoupper($turno['label']).' — '.$turno['total'].' projeto(s)';
            $linhas[] = str_repeat('-', 60);

            foreach ($turno['projetos'] as $i => $p) {
                $linhas[] = sprintf('%03d - %s', $i + 1, $p['titulo']);
                $linhas[] = '      '.$p['categoria'].' / '.($p['area'] ?? 'sem área');
                $linhas[] = '      '.$p['escola'];
            }

            $linhas[] = '';
        }

        return implode("\n", $linhas);
    }

    /** O CSV: uma linha por projeto, com o turno na coluna. */
    public function exportarCsv(): string
    {
        $lista = $this->listar();
        // BOM + ponto e vírgula: é o que o Excel em pt-BR abre sem perguntar.
        $saida = "\u{FEFF}Turno;Ordem;Projeto;Categoria;Área;Escola;Cidade;UF;Orientador;Regra\n";

        foreach ($lista['turnos'] as $turno) {
            foreach ($turno['projetos'] as $i => $p) {
                $saida .= implode(';', array_map(
                    fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
                    [
                        $turno['label'], $i + 1, $p['titulo'], $p['categoria'], $p['area'],
                        $p['escola'], $p['cidade'], $p['uf'], $p['orientador'], $p['regra_label'],
                    ],
                ))."\n";
            }
        }

        return $saida;
    }

    /** O PDF: o mesmo conteúdo, pronto para imprimir e levar ao ginásio. */
    public function exportarPdf(): string
    {
        $lista = $this->listar();
        $edicao = Edicao::atual();

        return app(PdfService::class)->render('pdf.turnos', [
            'lista' => $lista,
            'edicao' => $edicao?->nome ?? 'FETECMS',
            'gerado_em' => now()->format('d/m/Y H:i'),
        ]);
    }

    // --- O algoritmo -----------------------------------------------------

    /**
     * Reparte os finalistas entre os turnos.
     *
     * Duas passadas: primeiro as regras, na ordem de prioridade (a primeira que
     * alcança o projeto decide); depois o equilíbrio, que espalha o resto para
     * os dois turnos terminarem parecidos.
     *
     * @param  Collection<int, Projeto>  $finalistas
     * @return array{alocacoes: list<array{projeto: Projeto, turno: Turno, regra: ?string, origem: ?string}>, totais: array<string,int>, realocados: list<array<string,string>>}
     */
    private function distribuir(Collection $finalistas, RegrasTurnos $regras): array
    {
        $ordenados = $this->ordenarProjetos($finalistas->values()->all());

        $alocacoes = [];
        $totais = [Turno::A->value => 0, Turno::B->value => 0];
        $realocados = [];

        /** Põe o projeto no turno pedido — ou no outro, se aquele estiver cheio. */
        $alocar = function (Projeto $projeto, Turno $desejado, ?string $regra, ?string $origem) use (
            &$alocacoes, &$totais, &$realocados, $regras
        ) {
            $turno = $desejado;

            if ($totais[$turno->value] >= $regras->capacidadeDe($turno)) {
                $turno = $desejado->oposto();

                // A regra não pôde ser cumprida: o admin precisa saber quais
                // projetos ficaram fora do turno que ele pediu.
                $realocados[] = [
                    'projeto' => $projeto->titulo,
                    'de' => $desejado->label(),
                    'para' => $turno->label(),
                    'motivo' => 'O '.$desejado->label().' ficou sem estande livre.',
                ];
            }

            $alocacoes[] = ['projeto' => $projeto, 'turno' => $turno, 'regra' => $regra, 'origem' => $origem];
            $totais[$turno->value]++;
        };

        $decididos = [];

        foreach (RegrasTurnos::ORDEM as $chave) {
            if (! $regras->ativa($chave)) {
                continue;
            }

            foreach ($ordenados as $projeto) {
                if (isset($decididos[$projeto->id])) {
                    continue;
                }

                $alvo = $this->turnoDaRegra($projeto, $chave, $regras);

                if ($alvo === null) {
                    continue;
                }

                $decididos[$projeto->id] = true;
                $alocar($projeto, $alvo['turno'], $chave, $alvo['origem']);
            }
        }

        // O resto: equilíbrio. Sempre entra no turno com mais espaço livre, o
        // que também respeita capacidades diferentes entre A e B.
        foreach ($ordenados as $projeto) {
            if (isset($decididos[$projeto->id])) {
                continue;
            }

            $livreA = $regras->capacidadeDe(Turno::A) - $totais[Turno::A->value];
            $livreB = $regras->capacidadeDe(Turno::B) - $totais[Turno::B->value];

            $alocar($projeto, $livreA >= $livreB ? Turno::A : Turno::B, 'equilibrio', null);
        }

        return ['alocacoes' => $alocacoes, 'totais' => $totais, 'realocados' => $realocados];
    }

    /**
     * O turno que uma regra pede para este projeto — ou null quando a regra não
     * o alcança.
     *
     * @return array{turno: Turno, origem: ?string}|null
     */
    private function turnoDaRegra(Projeto $projeto, string $regra, RegrasTurnos $regras): ?array
    {
        if (in_array($regra, RegrasTurnos::COM_LISTAS, true)) {
            foreach ($regras->listas($regra) as $lista) {
                if (! in_array($projeto->id, $lista['projetos'], true)) {
                    continue;
                }

                // No vestibular o admin marca onde o projeto APRESENTA; na
                // justificativa ele marca o turno IMPOSSÍVEL, e o destino é o
                // oposto — guardar a indisponibilidade mantém o registro
                // verdadeiro mesmo se os horários dos turnos mudarem.
                $turno = $regra === 'vestibular'
                    ? Turno::from($lista['turno'])
                    : Turno::from($lista['turno_indisponivel'])->oposto();

                return ['turno' => $turno, 'origem' => $lista['nome']];
            }

            return null;
        }

        $alcanca = match ($regra) {
            'fora_ms' => $this->ufDe($projeto) !== null && $this->ufDe($projeto) !== 'MS',
            'fora_capital' => $this->ufDe($projeto) === 'MS' && $this->cidadeDe($projeto)?->capital === false,
            'capital' => $this->ufDe($projeto) === 'MS' && $this->cidadeDe($projeto)?->capital === true,
            default => false,
        };

        return $alcanca ? ['turno' => $regras->turnoDe($regra), 'origem' => null] : null;
    }

    // --- Apoio -----------------------------------------------------------

    /**
     * Os projetos da lista final vigente, com o que a tela e o algoritmo
     * precisam — sem consultar de novo projeto a projeto.
     *
     * @return Collection<int, Projeto>
     */
    private function finalistas(?ListaFinal $lista): Collection
    {
        if ($lista === null) {
            return collect();
        }

        return Projeto::whereIn('id', $lista->projetos()->pluck('projetos.id'))
            ->with([
                'area:id,nome', 'user:id,name', 'alunos:id,projeto_id,nome', 'coorientador',
                'instituicao.cidade.estado', 'cidade.estado', 'estado',
            ])
            ->get();
    }

    /** A lista final **oficial** vigente — nunca a de demonstração. */
    private function listaVigente(?Edicao $edicao): ?ListaFinal
    {
        return $edicao === null ? null : ListaFinal::vigente($edicao);
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

    /** A cidade que vale: a da escola; sem escola, a do projeto. */
    private function cidadeDe(Projeto $projeto): ?Cidade
    {
        return $projeto->instituicao?->cidade ?? $projeto->cidade;
    }

    private function ufDe(Projeto $projeto): ?string
    {
        return $this->cidadeDe($projeto)?->estado?->uf
            ?? $projeto->estado?->uf;
    }

    /** @return list<string> */
    private function regrasLigadas(RegrasTurnos $regras): array
    {
        $rotulos = collect(RegrasTurnos::catalogo())->keyBy('value');

        return collect(RegrasTurnos::ORDEM)
            ->filter(fn (string $r) => $regras->ativa($r))
            ->map(fn (string $r) => (string) $rotulos[$r]['label'])
            ->values()
            ->all();
    }

    /**
     * A ordem da lista final: categoria (FETECMS → FETEC Jr → FUNDECT), área
     * alfabética, título.
     *
     * @param  list<Projeto>  $projetos
     * @return list<Projeto>
     */
    private function ordenarProjetos(array $projetos): array
    {
        $ordemCategoria = array_flip(array_map(fn (Categoria $c) => $c->value, Categoria::ordemDaLista()));

        usort($projetos, function (Projeto $a, Projeto $b) use ($ordemCategoria) {
            $ca = $ordemCategoria[$a->categoria?->value] ?? PHP_INT_MAX;
            $cb = $ordemCategoria[$b->categoria?->value] ?? PHP_INT_MAX;

            return [$ca, $this->chave($a->area?->nome ?? 'zzz'), $this->chave($a->titulo)]
                <=> [$cb, $this->chave($b->area?->nome ?? 'zzz'), $this->chave($b->titulo)];
        });

        return $projetos;
    }

    /**
     * @param  Collection<int, TurnoApresentacao>  $alocacoes
     * @return Collection<int, TurnoApresentacao>
     */
    private function ordenar(Collection $alocacoes): Collection
    {
        $ordenados = $this->ordenarProjetos($alocacoes->map(fn (TurnoApresentacao $t) => $t->projeto)->all());
        $posicao = array_flip(array_map(fn (Projeto $p) => $p->id, $ordenados));

        return $alocacoes->sortBy(fn (TurnoApresentacao $t) => $posicao[$t->projeto_id] ?? PHP_INT_MAX)->values();
    }

    /** @return array<string, mixed> */
    private function linha(TurnoApresentacao $t): array
    {
        $projeto = $t->projeto;
        $rotulos = collect(RegrasTurnos::catalogo())->keyBy('value');

        return [
            'projeto_id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'categoria' => $projeto->categoria?->label(),
            'categoria_value' => $projeto->categoria?->value,
            'area' => $projeto->area?->nome,
            'escola' => $projeto->instituicao?->nome ?? '—',
            'cidade' => $this->cidadeDe($projeto)?->nome,
            'uf' => $this->ufDe($projeto),
            'orientador' => $projeto->user?->name,
            'turno' => $t->turno->value,
            'regra' => $t->regra,
            'regra_label' => $t->manual
                ? 'Movido à mão'
                : ($t->regra === 'equilibrio'
                    ? 'Equilíbrio entre os turnos'
                    : ($rotulos[$t->regra]['label'] ?? '—').($t->origem ? ' · '.$t->origem : '')),
            'manual' => (bool) $t->manual,
        ];
    }

    /** @return array<string, mixed> */
    private function listaVazia(): array
    {
        return [
            'gerada' => false,
            'total' => 0,
            'turnos' => collect(Turno::cases())
                ->mapWithKeys(fn (Turno $t) => [$t->value => [
                    'turno' => $t->value,
                    'label' => $t->label(),
                    'total' => 0,
                    'projetos' => [],
                ]])
                ->all(),
        ];
    }

    /** Chave de ordenação/busca tolerante a acento e caixa. */
    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
