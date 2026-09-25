<?php

namespace App\Services;

use App\Enums\SituacaoEstande;
use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
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
                    // Sim/Não nos dois primeiros; número nos dois de contagem.
                    'tipo' => str_starts_with($chave, 'avaliacoes_') ? 'numero' : 'booleano',
                ],
                array_keys(self::CRITERIOS),
                array_values(self::CRITERIOS),
            ),
            'max_avaliacoes' => AvaliacaoPresencial::MAX_POR_PROJETO,
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
            $linhas[] = '';
        }

        return implode("\n", $linhas);
    }

    /** @param  array<string, mixed>  $filtros */
    public function exportarCsv(array $filtros): string
    {
        $lista = $this->lista($filtros);

        // UTF-8 com BOM e separador `;`: é o que o Excel em pt_BR abre certo.
        $saida = "\u{FEFF}".implode(';', [
            'Estande', 'Turno', 'Projeto', 'Categoria', 'Área', 'Escola', 'Orientador',
            'Situação', 'Credenciado em', 'Checado em', 'Avaliações realizadas', 'Avaliações faltantes',
        ])."\n";

        foreach ($lista['linhas'] as $l) {
            $saida .= implode(';', array_map(
                fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
                [
                    $l['estande'], $lista['turno_label'], $l['titulo'], $l['categoria'],
                    $l['area'] ?? '', $l['escola'], $l['orientador'] ?? '',
                    $l['situacao_label'], $l['credenciado_em'] ?? '', $l['checado_em'] ?? '',
                    $l['avaliacoes'], $l['avaliacoes_faltantes'],
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
            ->with(['projeto.area:id,nome', 'projeto.instituicao:id,nome', 'projeto.user:id,name'])
            ->orderBy('numero')
            ->get()
            ->filter(fn (EstandeProjeto $e) => $e->projeto !== null);

        $ids = $alocacoes->pluck('projeto_id')->all();

        $credenciados = $this->carimbos('credenciamentos', 'finalizado_em', $ids, $corte);
        $checados = $this->carimbos('checagens_estande', 'verificado_em', $ids, $corte);
        $avaliacoes = $this->avaliacoes($ids, $corte);
        $maximo = AvaliacaoPresencial::MAX_POR_PROJETO;

        return $alocacoes->map(function (EstandeProjeto $e) use ($credenciados, $checados, $avaliacoes, $maximo) {
            $projeto = $e->projeto;
            $feitas = (int) ($avaliacoes[$projeto->id] ?? 0);
            $credenciado = $credenciados[$projeto->id] ?? null;
            $checado = $checados[$projeto->id] ?? null;

            // Cumulativo e na ordem do evento: a última coisa que aconteceu é a
            // que manda na cor.
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
    private function avaliacoes(array $ids, CarbonImmutable $corte): array
    {
        if ($ids === []) {
            return [];
        }

        return AvaliacaoPresencial::whereIn('projeto_id', $ids)
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
            default => '',
        };
    }
}
