<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Enums\VagaPresencial;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\MapaLayout;
use App\Support\JanelaTurnos;
use App\Support\MapaAvaliacoesPdf;
use App\Support\PlantaEvento;
use Carbon\CarbonImmutable;

/**
 * Mapa do Evento → **avaliações por projeto** (Sprint 248).
 *
 * O painel da tela cheia conta quantas avaliações cada projeto recebeu; esta
 * tela diz **quais faltam**. Cada projeto tem vagas comuns e vagas da comissão
 * especial (padrão 2 + 1, {@see JanelaTurnos}), e "faltam 2" não basta para
 * quem vai mandar avaliador ao estande: faltam duas comuns, ou uma comum e a do
 * comitê? Por isso a categoria do projeto é o par **(comuns que faltam, comitê
 * que falta)** — no padrão, seis: faltam 3, faltam 2 (1 comitê), faltam 2
 * (comum), falta somente comitê, falta somente comum e avaliação fechada.
 *
 * Três decisões:
 *
 * - A conta é pela **vaga gravada** na avaliação, e não por quem avaliou: o
 *   avaliador da comissão que encontra a vaga dela tomada entra numa comum, e
 *   é essa vaga que ele preenche. Comum a mais (a designação manual passa do
 *   teto) não cobre a vaga do comitê.
 * - A categoria conta só as **enviadas**. A avaliação **em andamento** aparece
 *   à parte, por vaga — ela ainda pode não ser enviada.
 * - Entra **todo projeto com estande no turno**. Quem ainda não está pronto
 *   (sem credenciamento ou checagem) ou saiu antes do horário continua na sua
 *   categoria, com uma marca.
 *
 * O corte de dia e o turno são os do mapa ({@see MapaSituacaoService}): um dia
 * anterior mostra o que estava enviado e o que estava aberto no fim dele.
 */
class MapaAvaliacoesService
{
    /**
     * As cores das categorias intermediárias, na ordem da lista (validadas
     * para daltonismo com todos os pares em jogo, como pede um mapa). A sem
     * nenhuma enviada é branca e a fechada é verde, como nas outras telas do
     * mapa. Uma configuração de vagas com mais categorias que isso cai no
     * cinza — a lista e a legenda continuam dizendo qual é qual.
     */
    private const CORES = [
        ['fill' => '#eda100', 'stroke' => '#8a5d00', 'texto' => '#0b0b0b'],
        ['fill' => '#d55181', 'stroke' => '#8e2a52', 'texto' => '#0b0b0b'],
        ['fill' => '#5b3fd1', 'stroke' => '#33218a', 'texto' => '#ffffff'],
        ['fill' => '#3987e5', 'stroke' => '#1c5cab', 'texto' => '#0b0b0b'],
    ];

    private const COR_NENHUMA = ['fill' => '#ffffff', 'stroke' => '#8f889b', 'texto' => '#52514e'];

    private const COR_FECHADA = ['fill' => '#0b6b2b', 'stroke' => '#063d1b', 'texto' => '#ffffff'];

    private const COR_OUTRAS = ['fill' => '#898781', 'stroke' => '#5c5866', 'texto' => '#ffffff'];

    public function __construct(
        private readonly MapaSituacaoService $situacao,
        private readonly LocaisFinalistasService $locais,
    ) {}

    /**
     * A planta, as categorias com a contagem de cada uma e uma linha por
     * projeto do turno, em ordem de estande. Os filtros não entram aqui: a
     * tela filtra na hora, e a exportação passa por {@see filtrar()}.
     *
     * @return array<string, mixed>
     */
    public function painel(?string $dia = null, ?string $turno = null): array
    {
        $base = $this->situacao->doTurno($dia, $turno);
        $janela = JanelaTurnos::daEdicao();
        $comuns = $janela->comunsPorProjeto();
        $comite = $janela->comitePorProjeto();

        $ids = array_column($base['linhas'], 'projeto_id');
        $contagens = $this->contagens($ids, $base['corte'], $janela);
        $locais = $ids === [] ? [] : $this->locais->todos();
        $categorias = $this->categorias($comuns, $comite);

        $planta = $this->planta();
        $naPlanta = array_fill_keys(array_column($planta['estandes'], 'numero'), true);

        $linhas = [];
        $marcas = ['em_andamento' => 0, 'nao_pronto' => 0, 'saiu' => 0];

        foreach ($base['linhas'] as $l) {
            $c = $contagens[$l['projeto_id']] ?? $this->vazio();
            $faltamComum = max(0, $comuns - $c['comum']['enviadas']);
            $faltamComite = max(0, $comite - $c['comite']['enviadas']);
            $chave = $this->chave($faltamComum, $faltamComite);
            $categorias[$chave]['total']++;

            $emAndamento = $c['comum']['em_andamento'] + $c['comite']['em_andamento'];
            $pronto = $l['credenciado'] && $l['checado'];
            $marcas['em_andamento'] += $emAndamento > 0 ? 1 : 0;
            $marcas['nao_pronto'] += $pronto ? 0 : 1;
            $marcas['saiu'] += $l['saida'] !== null ? 1 : 0;

            $linhas[] = [
                'numero' => $l['numero'],
                'estande' => $l['estande'],
                'projeto_id' => $l['projeto_id'],
                'titulo' => $l['titulo'],
                'codigo' => $locais[$l['projeto_id']]['codigo'] ?? null,
                'categoria' => $l['categoria'],
                'area' => $l['area'],
                'area_sigla' => $l['area_sigla'],
                'comum' => $c['comum'] + ['vagas' => $comuns, 'faltam' => $faltamComum],
                'comite' => $c['comite'] + ['vagas' => $comite, 'faltam' => $faltamComite],
                'enviadas' => $c['comum']['enviadas'] + $c['comite']['enviadas'],
                'em_andamento' => $emAndamento,
                'situacao' => $chave,
                'situacao_label' => $categorias[$chave]['label'],
                'credenciado' => $l['credenciado'],
                'checado' => $l['checado'],
                'pronto' => $pronto,
                'saida' => $l['saida']['hora'] ?? null,
                'na_planta' => isset($naPlanta[$l['numero']]),
            ];
        }

        return [
            'edicao' => Edicao::atual()?->nome,
            'dia' => $base['corte']->toDateString(),
            'turno' => $base['turno']->value,
            'turno_label' => $base['turno']->label(),
            'corte_label' => $base['corte']->format('d/m/Y H:i'),
            'vagas' => ['comuns' => $comuns, 'comite' => $comite, 'total' => $comuns + $comite],
            'categorias' => array_values($categorias),
            'marcas' => $marcas,
            'planta' => $planta,
            'linhas' => $linhas,
            'total' => count($linhas),
        ];
    }

    /**
     * O recorte dos filtros da tela: as categorias marcadas (nenhuma = todas)
     * e, se pedido, só quem tem avaliação em andamento. As duas condições se
     * somam, como os botões da tela.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  array{categorias?: list<string>|null, em_andamento?: bool|null}  $filtros
     * @return list<array<string, mixed>>
     */
    public function filtrar(array $linhas, array $filtros): array
    {
        $categorias = array_values(array_filter($filtros['categorias'] ?? []));
        $emAndamento = (bool) ($filtros['em_andamento'] ?? false);

        return array_values(array_filter($linhas, fn (array $l) => ($categorias === [] || in_array($l['situacao'], $categorias, true))
            && (! $emAndamento || $l['em_andamento'] > 0)));
    }

    /** "Todas as categorias" ou os rótulos marcados, e o "em andamento". */
    public function rotuloDoFiltro(array $painel, array $filtros): string
    {
        $marcadas = array_values(array_filter($filtros['categorias'] ?? []));
        $rotulos = array_column(array_filter(
            $painel['categorias'],
            fn (array $c) => in_array($c['chave'], $marcadas, true),
        ), 'label');

        $texto = $rotulos === [] ? 'Todas as categorias' : implode(', ', $rotulos);

        return ($filtros['em_andamento'] ?? false) ? $texto.' · com avaliação em andamento' : $texto;
    }

    /** @param  array<string, mixed>  $filtros  dia, turno, categorias, em_andamento */
    public function exportarLista(array $filtros): string
    {
        $painel = $this->painel($filtros['dia'] ?? null, $filtros['turno'] ?? null);

        return app(PdfService::class)->render('pdf.mapa-avaliacoes', [
            'painel' => $painel,
            'linhas' => $this->filtrar($painel['linhas'], $filtros),
            'filtro' => $this->rotuloDoFiltro($painel, $filtros),
        ], 'a4', 'landscape');
    }

    /** @param  array<string, mixed>  $filtros  dia, turno, categorias, em_andamento */
    public function exportarMapa(array $filtros): string
    {
        $painel = $this->painel($filtros['dia'] ?? null, $filtros['turno'] ?? null);
        $visiveis = array_column($this->filtrar($painel['linhas'], $filtros), 'projeto_id');

        return MapaAvaliacoesPdf::gerar($painel, $visiveis, $this->rotuloDoFiltro($painel, $filtros));
    }

    // --- Interno ---------------------------------------------------------

    /**
     * Todas as categorias possíveis com as vagas da edição — inclusive as que
     * ninguém ocupa: o filtro mostra o zero, que também informa. Em ordem de
     * quanto falta, e a que ainda deve o comitê antes da que não deve.
     *
     * @return array<string, array<string, mixed>>
     */
    private function categorias(int $comuns, int $comite): array
    {
        $pares = [];

        for ($fc = $comuns; $fc >= 0; $fc--) {
            for ($fk = $comite; $fk >= 0; $fk--) {
                $pares[] = [$fc, $fk];
            }
        }

        usort($pares, fn (array $a, array $b) => [$b[0] + $b[1], $b[1]] <=> [$a[0] + $a[1], $a[1]]);

        $categorias = [];
        $meio = 0;

        foreach ($pares as [$fc, $fk]) {
            $cor = match (true) {
                $fc === 0 && $fk === 0 => self::COR_FECHADA,
                $fc === $comuns && $fk === $comite => self::COR_NENHUMA,
                default => self::CORES[$meio++] ?? self::COR_OUTRAS,
            };

            $categorias[$this->chave($fc, $fk)] = [
                'chave' => $this->chave($fc, $fk),
                'label' => $this->rotulo($fc, $fk, $comuns, $comite),
                'faltam_comum' => $fc,
                'faltam_comite' => $fk,
                'cor' => $cor['fill'],
                'borda' => $cor['stroke'],
                'texto' => $cor['texto'],
                'total' => 0,
            ];
        }

        return $categorias;
    }

    private function chave(int $faltamComum, int $faltamComite): string
    {
        return "comum{$faltamComum}-comite{$faltamComite}";
    }

    /**
     * Os nomes que a organização usa no padrão 2 + 1, escritos de um jeito que
     * também vale para outra configuração de vagas.
     */
    private function rotulo(int $fc, int $fk, int $comuns, int $comite): string
    {
        $total = $fc + $fk;

        return match (true) {
            $total === 0 => 'Avaliação fechada',
            $fk === 0 => $total === 1 ? 'Falta somente comum' : "Faltam {$total} avaliações (comum)",
            $fc === 0 => $total === 1 ? 'Falta somente comitê' : "Faltam {$total} avaliações (comitê)",
            $fc === $comuns && $fk === $comite => "Faltam {$total} avaliações",
            default => "Faltam {$total} avaliações ({$fk} comitê)",
        };
    }

    /** @return array{comum: array{enviadas:int, em_andamento:int}, comite: array{enviadas:int, em_andamento:int}} */
    private function vazio(): array
    {
        return [
            'comum' => ['enviadas' => 0, 'em_andamento' => 0],
            'comite' => ['enviadas' => 0, 'em_andamento' => 0],
        ];
    }

    /**
     * Enviadas e em andamento de cada projeto, por vaga, no corte.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, array{enviadas:int, em_andamento:int}>>
     */
    private function contagens(array $ids, CarbonImmutable $corte, JanelaTurnos $janela): array
    {
        if ($ids === []) {
            return [];
        }

        $contagens = [];

        $avaliacoes = AvaliacaoPresencial::whereIn('projeto_id', $ids)
            ->whereIn('status', [StatusAvaliacao::EmAndamento->value, StatusAvaliacao::Concluida->value])
            ->get(['id', 'projeto_id', 'status', 'vaga', 'dia', 'turno', 'iniciada_em', 'concluida_em', 'aberta_pelo_comite']);

        foreach ($avaliacoes as $a) {
            $vaga = $a->vaga === VagaPresencial::Comite ? 'comite' : 'comum';
            $contagens[$a->projeto_id] ??= $this->vazio();

            if ($a->concluida() && $a->concluida_em !== null && $a->concluida_em->lessThanOrEqualTo($corte)) {
                $contagens[$a->projeto_id][$vaga]['enviadas']++;
            } elseif ($this->abertaNoCorte($a, $corte, $janela)) {
                $contagens[$a->projeto_id][$vaga]['em_andamento']++;
            }
        }

        return $contagens;
    }

    /**
     * Estava sendo preenchida no corte: já tinha sido aberta e ainda não fora
     * enviada — a enviada depois do corte conta, porque naquela hora estava
     * aberta — e não tinha vencido o prazo do turno (o rascunho do comitê não
     * vence).
     */
    private function abertaNoCorte(AvaliacaoPresencial $a, CarbonImmutable $corte, JanelaTurnos $janela): bool
    {
        if ($a->iniciada_em !== null && $a->iniciada_em->greaterThan($corte)) {
            return false;
        }

        if ($a->concluida()) {
            return $a->iniciada_em !== null && $a->concluida_em !== null;
        }

        if ($a->semPrazo()) {
            return true;
        }

        $prazo = $a->prazo($janela);

        return $prazo === null || $corte->lessThanOrEqualTo($prazo);
    }

    /**
     * A planta em vigor (ou a padrão): os estandes e as marcações.
     *
     * @return array{estandes: list<array{numero:int, x:float, y:float}>, marcacoes: list<array<string, mixed>>}
     */
    private function planta(): array
    {
        $edicao = Edicao::atual();
        $dados = ($edicao === null ? null : MapaLayout::vigente($edicao))?->dados ?? PlantaEvento::padrao();

        return [
            'estandes' => array_map(
                fn (array $e) => ['numero' => (int) $e['numero'], 'x' => (float) $e['x'], 'y' => (float) $e['y']],
                $dados['estandes'] ?? [],
            ),
            'marcacoes' => array_values($dados['marcacoes'] ?? []),
        ];
    }
}
