<?php

namespace App\Services;

use App\Enums\SituacaoEstande;
use App\Enums\SituacaoTermoImagem;
use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Support\JanelaTurnos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mapa do Evento → **a situação de cada estande**, ao vivo.
 *
 * O mapa deixou de ser só o desenho de onde o projeto fica: conforme a feira
 * acontece, o ginásio **muda de cor**. O projeto passa pelo balcão e o estande
 * muda; o estande é conferido e ele muda de novo, dizendo que está pronto para
 * ser avaliado; as avaliações presenciais chegam e a cor escurece a cada uma.
 * De longe, a organização vê o que ainda não andou sem abrir nada.
 *
 * **Nada disso é marcado à mão.** Os três estágios saem de fatos que o portal
 * já registrava com hora — `credenciamentos.finalizado_em`,
 * `checagens_estande.verificado_em` e `avaliacoes_presenciais.concluida_em` —,
 * e é por isso que o mapa nunca discorda das outras abas.
 *
 * O **termo de uso de imagem** (Sprint 176) segue o mesmo princípio: sai do
 * envio online do orientador (com a hora do envio) e do que o balcão marcou no
 * credenciamento (com a hora da conclusão), e não pinta o estande — é outro
 * eixo, que o painel do estande, a lista e as exportações mostram.
 *
 * É esse carimbo de hora que também dá o **seletor de dia** de graça: escolher
 * um dia anterior é ler os mesmos fatos com um corte mais cedo, e o mapa volta
 * a ser o que era no fim daquele dia. Nenhum instantâneo precisa ser gravado, e
 * uma correção retroativa aparece no histórico como deveria ter aparecido desde
 * o começo. O **turno** é o outro eixo: o estande recebe um projeto de manhã e
 * outro à tarde, então o mapa mostra um turno por vez.
 */
class MapaSituacaoService
{
    /** Os critérios que o filtro da lista aceita. */
    public const CRITERIOS = [
        'credenciamento' => 'Credenciamento',
        'checagem' => 'Checagem do estande',
        'avaliacoes_realizadas' => 'Avaliações realizadas',
        'avaliacoes_faltantes' => 'Avaliações faltantes',
        'termo_imagem' => 'Termo de uso de imagem',
    ];

    /**
     * Valores do filtro do termo de imagem (Sprint 176). "Incompleto" junta
     * parcial e pendente — é a pergunta da véspera: quem ainda falta?
     */
    private const VALORES_TERMO_IMAGEM = [
        'incompleto' => 'incompleto (parcial ou pendente)',
        'completo' => 'completo',
        'parcial' => 'parcial',
        'nenhum' => 'pendente',
    ];

    /**
     * Os dias que o seletor oferece: os da janela do evento.
     *
     * Sem janela definida, só **hoje** — é o que impede a tela de abrir com um
     * seletor vazio antes de a organização marcar as datas.
     *
     * @return list<array{value:string, label:string, hoje:bool}>
     */
    public function dias(): array
    {
        $edicao = Edicao::atual();
        $hoje = CarbonImmutable::now();

        $de = $edicao?->evento_de ? CarbonImmutable::parse($edicao->evento_de)->startOfDay() : null;
        $ate = $edicao?->evento_ate ? CarbonImmutable::parse($edicao->evento_ate)->startOfDay() : null;

        if ($de === null || $ate === null || $ate->lessThan($de)) {
            return [['value' => $hoje->toDateString(), 'label' => 'Hoje', 'hoje' => true]];
        }

        $dias = [];

        for ($d = $de; $d->lessThanOrEqualTo($ate); $d = $d->addDay()) {
            $ehHoje = $d->isSameDay($hoje);

            $dias[] = [
                'value' => $d->toDateString(),
                'label' => $d->format('d/m').($ehHoje ? ' (hoje)' : ''),
                'hoje' => $ehHoje,
            ];
        }

        return $dias;
    }

    /**
     * A situação de cada estande num turno, **como estava no fim do dia
     * escolhido**.
     *
     * @return array<string, mixed>
     */
    public function porEstande(?string $dia = null, ?string $turno = null): array
    {
        $turno = Turno::tryFrom((string) $turno) ?? Turno::A;
        $corte = $this->corte($dia);
        $linhas = $this->linhas($turno, $corte);

        $mapa = [];
        $resumo = array_fill_keys(array_column(SituacaoEstande::cases(), 'value'), 0);

        foreach ($linhas as $linha) {
            $mapa[$linha['numero']] = $linha;
            $resumo[$linha['situacao']]++;
        }

        return [
            'dia' => $corte->toDateString(),
            'turno' => $turno->value,
            'turno_label' => $turno->label(),
            'corte_label' => $corte->format('d/m/Y H:i'),
            'estandes' => $mapa,
            'resumo' => $resumo,
            'total' => count($linhas),
        ];
    }

    /**
     * A lista filtrada, para ver na tela e exportar.
     *
     * @param  array<string, mixed>  $filtros  criterio, valor, dia, turno
     * @return array<string, mixed>
     */
    public function lista(array $filtros = []): array
    {
        $turno = Turno::tryFrom((string) ($filtros['turno'] ?? '')) ?? Turno::A;
        $corte = $this->corte($filtros['dia'] ?? null);
        $criterio = (string) ($filtros['criterio'] ?? 'credenciamento');
        $valor = $filtros['valor'] ?? null;

        $linhas = array_values(array_filter(
            $this->linhas($turno, $corte),
            fn (array $l) => $l['projeto_id'] !== null && $this->passa($l, $criterio, $valor),
        ));

        return [
            'criterio' => $criterio,
            'criterio_label' => self::CRITERIOS[$criterio] ?? $criterio,
            'valor' => $valor,
            'valor_label' => $this->rotuloDoValor($criterio, $valor),
            'dia' => $corte->toDateString(),
            'corte_label' => $corte->format('d/m/Y H:i'),
            'turno' => $turno->value,
            'turno_label' => $turno->label(),
            'total' => count($linhas),
            'linhas' => $linhas,
        ];
    }

    /** As opções que a tela desenha no filtro. */
    public function opcoes(): array
    {
        return [
            'dias' => $this->dias(),
            'turnos' => Turno::opcoes(),
            'legenda' => SituacaoEstande::legenda(),
            'criterios' => array_map(
                fn (string $chave, string $label) => [
                    'value' => $chave,
                    'label' => $label,
                    // Sim/Não nos dois primeiros, número nos de contagem e uma
                    // lista própria no termo de imagem.
                    'tipo' => match (true) {
                        $chave === 'termo_imagem' => 'opcoes',
                        str_starts_with($chave, 'avaliacoes_') => 'numero',
                        default => 'booleano',
                    },
                ] + ($chave === 'termo_imagem' ? [
                    'valores' => array_map(
                        fn (string $valor, string $rotulo) => ['value' => $valor, 'label' => ucfirst($rotulo)],
                        array_keys(self::VALORES_TERMO_IMAGEM),
                        array_values(self::VALORES_TERMO_IMAGEM),
                    ),
                    'padrao' => 'incompleto',
                ] : []),
                array_keys(self::CRITERIOS),
                array_values(self::CRITERIOS),
            ),
            'max_avaliacoes' => JanelaTurnos::daEdicao()->porProjeto(),
            // A legenda das faixas de área da tela cheia (Sprint 198).
            'areas' => Area::orderBy('nome')->get(['id', 'nome', 'sigla'])
                ->map(fn (Area $a) => ['id' => $a->id, 'nome' => $a->nome, 'sigla' => $a->siglaDaLista()])
                ->all(),
        ];
    }

    // --- Exportação ------------------------------------------------------

    /** @param  array<string, mixed>  $filtros */
    public function exportarTxt(array $filtros): string
    {
        $lista = $this->lista($filtros);
        $linhas = [
            mb_strtoupper($lista['criterio_label']).' · '.$lista['valor_label'],
            $lista['turno_label'].' · situação em '.$lista['corte_label'],
            str_repeat('-', 60),
            $lista['total'].' projeto(s)',
            '',
        ];

        foreach ($lista['linhas'] as $l) {
            $linhas[] = sprintf('%s - %s', $l['estande'], $l['titulo']);
            $linhas[] = '      '.$l['categoria'].' / '.($l['area'] ?? 'sem área');
            $linhas[] = '      '.$l['escola'];
            $linhas[] = '      '.$l['situacao_label']
                .' · '.$l['avaliacoes'].' de '.$l['avaliacoes_maximo'].' avaliação(ões)';
            $linhas[] = '      Termo de imagem: '.$this->termoImagem($l);
            $linhas[] = '';
        }

        return implode("\n", $linhas);
    }

    /** "Parcial · 2 de 3 (falta: Caio)", "Completo · 3 de 3" ou "Sem estudantes". */
    private function termoImagem(array $linha): string
    {
        if ($linha['termo_imagem_total'] === 0) {
            return $linha['termo_imagem_label'];
        }

        $texto = $linha['termo_imagem_label'].' · '.$linha['termo_imagem_cobertos'].' de '.$linha['termo_imagem_total'];

        return $linha['termo_imagem_faltando'] === []
            ? $texto
            : $texto.' (falta: '.implode(', ', $linha['termo_imagem_faltando']).')';
    }

    /** "previsto 05/10/2026 14:00", "sim" ou vazio. */
    private function foraPrazo(array $linha): string
    {
        $fp = $linha['sinalizacoes']['fora_prazo'] ?? null;

        return $fp === null ? '' : ($fp['previsto_label'] ? 'previsto '.$fp['previsto_label'] : 'sim');
    }

    /** @param  array<string, mixed>  $filtros */
    public function exportarCsv(array $filtros): string
    {
        $lista = $this->lista($filtros);

        // UTF-8 com BOM e separador `;`: é o que o Excel em pt_BR abre certo.
        $saida = "\u{FEFF}".implode(';', [
            'Estande', 'Turno', 'Projeto', 'Categoria', 'Área', 'Escola', 'Orientador',
            'Situação', 'Credenciado em', 'Checado em', 'Avaliações realizadas', 'Avaliações faltantes',
            'Credenciamento fora do prazo', 'Termo de uso de imagem', 'Estudantes cobertos',
            'Estudantes sem termo de imagem',
        ])."\n";

        foreach ($lista['linhas'] as $l) {
            $saida .= implode(';', array_map(
                fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
                [
                    $l['estande'], $lista['turno_label'], $l['titulo'], $l['categoria'],
                    $l['area'] ?? '', $l['escola'], $l['orientador'] ?? '',
                    $l['situacao_label'], $l['credenciado_em'] ?? '', $l['checado_em'] ?? '',
                    $l['avaliacoes'], $l['avaliacoes_faltantes'],
                    $this->foraPrazo($l),
                    $l['termo_imagem_label'],
                    $l['termo_imagem_total'] === 0 ? '' : $l['termo_imagem_cobertos'].' de '.$l['termo_imagem_total'],
                    implode(', ', $l['termo_imagem_faltando']),
                ],
            ))."\n";
        }

        return $saida;
    }

    /** @param  array<string, mixed>  $filtros */
    public function exportarPdf(array $filtros): string
    {
        return app(PdfService::class)->render('pdf.mapa-situacao', [
            'lista' => $this->lista($filtros),
            'edicao' => Edicao::atual()?->nome,
        ], 'a4', 'landscape');
    }

    // --- Interno ---------------------------------------------------------

    /**
     * O instante até onde os fatos contam.
     *
     * Para **hoje** é agora — o mapa é ao vivo. Para um dia anterior é o fim
     * daquele dia, que é o que faz "como estava na quinta" significar alguma
     * coisa. Um dia futuro também cai em agora: nada aconteceu ainda, e mostrar
     * o futuro como se fosse passado enganaria.
     */
    private function corte(?string $dia): CarbonImmutable
    {
        $agora = CarbonImmutable::now();

        if ($dia === null || $dia === '') {
            return $agora;
        }

        try {
            $data = CarbonImmutable::parse($dia);
        } catch (\Throwable) {
            return $agora;
        }

        return $data->isSameDay($agora) || $data->greaterThan($agora)
            ? $agora
            : $data->endOfDay();
    }

    /**
     * Uma linha por estande alocado no turno, com a situação no corte.
     *
     * @return list<array<string, mixed>>
     */
    private function linhas(Turno $turno, CarbonImmutable $corte): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        $alocacoes = EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('turno', $turno->value)
            ->with(['projeto.area:id,nome,sigla', 'projeto.instituicao:id,nome', 'projeto.user:id,name'])
            ->orderBy('numero')
            ->get()
            ->filter(fn (EstandeProjeto $e) => $e->projeto !== null);

        $ids = $alocacoes->pluck('projeto_id')->all();

        $credenciados = $this->carimbos('credenciamentos', 'finalizado_em', $ids, $corte);
        $checados = $this->carimbos('checagens_estande', 'verificado_em', $ids, $corte);
        $avaliacoes = $this->avaliacoes($ids, $corte);
        $doComite = $this->avaliacoes($ids, $corte, soComite: true);
        $maximo = JanelaTurnos::daEdicao()->porProjeto();
        $sinais = app(SinalizacaoProjetoService::class)->para($ids);
        $termos = app(TermosImagemService::class)->noEvento($ids, $corte);

        return $alocacoes->map(function (EstandeProjeto $e) use ($credenciados, $checados, $avaliacoes, $doComite, $maximo, $sinais, $termos) {
            $projeto = $e->projeto;
            $feitas = (int) ($avaliacoes[$projeto->id] ?? 0);
            $credenciado = $credenciados[$projeto->id] ?? null;
            $checado = $checados[$projeto->id] ?? null;

            // Cumulativo e na ordem do evento: a última coisa que aconteceu é a
            // que manda na cor.
            $termo = $termos[$projeto->id] ?? null;

            $situacao = match (true) {
                $feitas > 0 => SituacaoEstande::Avaliado,
                $checado !== null => SituacaoEstande::Checado,
                $credenciado !== null => SituacaoEstande::Credenciado,
                default => SituacaoEstande::Aguardando,
            };

            return [
                'numero' => $e->numero,
                'estande' => str_pad((string) $e->numero, 3, '0', STR_PAD_LEFT),
                'projeto_id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                // A faixa colorida da tela cheia (Sprint 198) segue a área, e a
                // sigla vai escrita nela: a cor sozinha não separa oito áreas.
                'area_id' => $projeto->area_id,
                'area_sigla' => $projeto->area?->siglaDaLista(),
                'escola' => $projeto->instituicao?->nome ?? '—',
                'orientador' => $projeto->user?->name,
                'situacao' => $situacao->value,
                'situacao_label' => $situacao->label(),
                'cor' => $situacao->cor(),
                'credenciado' => $credenciado !== null,
                'credenciado_em' => $credenciado?->format('d/m/Y H:i'),
                'checado' => $checado !== null,
                'checado_em' => $checado?->format('d/m/Y H:i'),
                'avaliacoes' => $feitas,
                'avaliacoes_faltantes' => max(0, $maximo - $feitas),
                'avaliacoes_maximo' => $maximo,
                // Das avaliações no estande, as feitas por avaliador da
                // comissão especial (Sprint 200, painel da tela cheia).
                'avaliacoes_comite' => (int) ($doComite[$projeto->id] ?? 0),
                // Estande vazio com explicação: fora do prazo aprovado (Sprint 161).
                'sinalizacoes' => $sinais[$projeto->id] ?? null,
                // Termo de uso de imagem (Sprint 176): online + balcão, no corte.
                'termo_imagem' => $termo['situacao'] ?? SituacaoTermoImagem::SemEstudantes->value,
                'termo_imagem_label' => $termo['situacao_label'] ?? SituacaoTermoImagem::SemEstudantes->label(),
                'termo_imagem_cobertos' => $termo['cobertos'] ?? 0,
                'termo_imagem_total' => $termo['total'] ?? 0,
                'termo_imagem_online' => $termo['online'] ?? 0,
                'termo_imagem_balcao' => $termo['balcao'] ?? 0,
                'termo_imagem_faltando' => $termo['faltando'] ?? [],
                'termo_imagem_arquivos' => array_map(
                    fn (array $a) => ['id' => $a['id'], 'nome_original' => $a['nome_original']],
                    $termo['arquivos'] ?? [],
                ),
            ];
        })->values()->all();
    }

    /**
     * O carimbo de hora de um fato por projeto, até o corte.
     *
     * @param  list<int>  $ids
     * @return array<int, CarbonImmutable>
     */
    private function carimbos(string $tabela, string $coluna, array $ids, CarbonImmutable $corte): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($tabela)
            ->whereIn('projeto_id', $ids)
            ->whereNotNull($coluna)
            ->where($coluna, '<=', $corte)
            ->orderBy($coluna)
            ->pluck($coluna, 'projeto_id')
            ->map(fn ($valor) => CarbonImmutable::parse($valor))
            ->all();
    }

    /**
     * Quantas avaliações presenciais **concluídas** cada projeto tinha no corte.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function avaliacoes(array $ids, CarbonImmutable $corte, bool $soComite = false): array
    {
        if ($ids === []) {
            return [];
        }

        return AvaliacaoPresencial::whereIn('projeto_id', $ids)
            ->when($soComite, fn ($q) => $q->whereIn(
                'avaliador_id',
                AvaliadorProfile::where('comissao_especial', true)->select('user_id'),
            ))
            ->where('status', StatusAvaliacao::Concluida->value)
            ->whereNotNull('concluida_em')
            ->where('concluida_em', '<=', $corte)
            ->groupBy('projeto_id')
            ->selectRaw('projeto_id, count(*) as total')
            ->pluck('total', 'projeto_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @param  array<string, mixed>  $linha */
    private function passa(array $linha, string $criterio, mixed $valor): bool
    {
        return match ($criterio) {
            // Sem valor, "sim": o filtro mais pedido é "quem já passou".
            'credenciamento' => $linha['credenciado'] === $this->booleano($valor),
            'checagem' => $linha['checado'] === $this->booleano($valor),
            'avaliacoes_realizadas' => $valor === null || $linha['avaliacoes'] === (int) $valor,
            'avaliacoes_faltantes' => $valor === null || $linha['avaliacoes_faltantes'] === (int) $valor,
            // Sem valor, "incompleto": quem ainda deve a autorização de alguém.
            'termo_imagem' => match ($valor ?? 'incompleto') {
                'incompleto' => in_array($linha['termo_imagem'], [SituacaoTermoImagem::Parcial->value, SituacaoTermoImagem::Nenhum->value], true),
                default => $linha['termo_imagem'] === $valor,
            },
            default => true,
        };
    }

    private function booleano(mixed $valor): bool
    {
        return ! in_array($valor, ['nao', 'não', '0', 0, false, 'false'], true);
    }

    private function rotuloDoValor(string $criterio, mixed $valor): string
    {
        return match ($criterio) {
            'credenciamento', 'checagem' => $this->booleano($valor) ? 'já passou' : 'ainda não passou',
            'avaliacoes_realizadas' => $valor === null ? 'qualquer quantidade' : $valor.' realizada(s)',
            'avaliacoes_faltantes' => $valor === null ? 'qualquer quantidade' : $valor.' faltante(s)',
            'termo_imagem' => self::VALORES_TERMO_IMAGEM[$valor ?? 'incompleto'] ?? (string) $valor,
            default => '',
        };
    }
}
