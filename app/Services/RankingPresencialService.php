<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\VagaPresencial;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Support\JanelaTurnos;
use App\Support\PlanilhaXlsx;
use App\Support\RubricaPresencial;

/**
 * Avaliação presencial → **Ranking presencial** (Sprint 222).
 *
 * A nota do estande é separada da online e serve à premiação. Cada projeto
 * recebe avaliações em dois tipos de vaga — comuns e da comissão especial —, e
 * a da comissão **pesa mais** na média (padrão 2, {@see JanelaTurnos}):
 *
 *     média = Σ (nota × peso) / Σ peso
 *
 * O peso sai da vaga gravada em cada avaliação, e não de quem avaliou hoje: o
 * avaliador da comissão que entrou numa vaga comum (a da comissão já estava
 * tomada) pesa 1.
 *
 * Entram os finalistas da lista que vale (a demo no modo de teste) com ao
 * menos uma avaliação presencial enviada. "Parcial" é quem ainda não tem todas
 * as vagas preenchidas: a média existe, mas pode mudar. Empate na média divide
 * a posição, como no ranking online.
 *
 * O ranking também sai em arquivo (Sprint 250), nos quatro formatos do ranking
 * online e no mesmo recorte de filtros da tela.
 */
class RankingPresencialService
{
    public function __construct(private readonly ListaFinalService $listas) {}

    /**
     * @param  array{area_id?: int|null, categoria?: string|null}  $filtros
     * @return array<string, mixed>
     */
    public function ranking(array $filtros = [], bool $demo = false): array
    {
        $edicao = Edicao::atual();
        $janela = JanelaTurnos::daEdicao($edicao);
        $lista = ListaFinal::vigente($edicao, $demo);

        return [
            'lista' => $lista === null ? null : ['id' => $lista->id, 'nome' => $lista->nome, 'demo' => (bool) $lista->demo],
            'vagas' => [
                'comuns' => $janela->comunsPorProjeto(),
                'comite' => $janela->comitePorProjeto(),
                'peso_comite' => $janela->pesoComite(),
            ],
            'projetos' => $lista === null ? [] : $this->linhas($lista, $janela, $filtros),
            'opcoes' => [
                'areas' => Area::orderBy('nome')->get(['id', 'nome'])->map(fn (Area $a) => ['id' => $a->id, 'nome' => $a->nome])->all(),
                'categorias' => Categoria::opcoes(),
            ],
        ];
    }

    /**
     * As linhas do ranking, sem o resto da tela — para quem monta a lista
     * presencial e para a exportação.
     *
     * @param  array{area_id?: int|null, categoria?: string|null}  $filtros
     * @return list<array<string, mixed>>
     */
    public function classificacao(array $filtros = [], bool $demo = false): array
    {
        $edicao = Edicao::atual();
        $lista = ListaFinal::vigente($edicao, $demo);

        return $lista === null ? [] : $this->linhas($lista, JanelaTurnos::daEdicao($edicao), $filtros);
    }

    /**
     * Ranking presencial → **baixar** (Sprint 250): CSV e Excel para conferir,
     * PDF para a mesa da comissão, TXT para colar num e-mail — os mesmos quatro
     * usos do ranking online. Sempre no recorte dos filtros da tela.
     *
     * As notas uma a uma ficam de fora: quem avaliou cada uma é assunto do
     * *Ver avaliações*, que registra quem abriu. O arquivo leva o que a linha
     * da tela mostra — média, quantas comuns e quantas da comissão.
     *
     * @param  array{area_id?: int|null, categoria?: string|null}  $filtros
     * @return array{conteudo: string, tipo: string, nome: string}
     */
    public function exportar(string $formato, array $filtros = [], bool $demo = false): array
    {
        $linhas = $this->classificacao($filtros, $demo);
        $nome = 'ranking-presencial-'.now()->format('Y-m-d-His');

        return match ($formato) {
            'csv' => ['conteudo' => $this->csv($linhas), 'tipo' => 'text/csv; charset=UTF-8', 'nome' => $nome.'.csv'],
            'xlsx' => [
                'conteudo' => PlanilhaXlsx::gerar(
                    'Ranking presencial',
                    self::COLUNAS,
                    array_map(fn (array $l) => $this->linhaArquivo($l, numerico: true), $linhas),
                ),
                'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'nome' => $nome.'.xlsx',
            ],
            'txt' => ['conteudo' => $this->txt($linhas), 'tipo' => 'text/plain; charset=UTF-8', 'nome' => $nome.'.txt'],
            default => ['conteudo' => $this->pdf($linhas, $filtros), 'tipo' => 'application/pdf', 'nome' => $nome.'.pdf'],
        };
    }

    /** Cabeçalho comum aos quatro formatos. */
    private const COLUNAS = [
        'Posição', 'Código', 'Projeto', 'Área', 'Categoria', 'Estande', 'Turno',
        'Média', 'Avaliações comuns', 'Avaliações da comissão', 'Situação',
    ];

    /**
     * Uma linha do arquivo. O XLSX quer a média como número (soma e ordena);
     * os outros, o texto em pt-BR com as três casas da tela.
     *
     * @param  array<string, mixed>  $l
     * @return list<string|int|float|null>
     */
    private function linhaArquivo(array $l, bool $numerico = false): array
    {
        return [
            $l['posicao'],
            $l['codigo'] ?? '',
            $l['titulo'],
            $l['area'] ?? 'Sem área',
            $l['categoria'] ?? '',
            $l['estande'] === null ? '' : str_pad((string) $l['estande'], 3, '0', STR_PAD_LEFT),
            $l['turno_label'] ?? '',
            $numerico ? (float) $l['media'] : self::nota($l['media']),
            $l['comuns'],
            $l['comite'],
            // "Parcial" impede o arquivo de ser lido como classificação fechada.
            $l['parcial'] ? 'Parcial' : 'Completo',
        ];
    }

    /** Nota com as três casas da tela, em pt-BR. */
    public static function nota(int|float|null $valor): string
    {
        return $valor === null ? '—' : number_format((float) $valor, 3, ',', '');
    }

    /** @param  list<array<string, mixed>>  $linhas */
    private function csv(array $linhas): string
    {
        $saida = fopen('php://temp', 'r+');
        // BOM + ponto e vírgula: o Excel em pt-BR abre sem perguntar.
        fwrite($saida, "\u{FEFF}");
        fputcsv($saida, self::COLUNAS, ';');

        foreach ($linhas as $l) {
            fputcsv($saida, $this->linhaArquivo($l), ';');
        }

        rewind($saida);
        $csv = (string) stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }

    /** @param  list<array<string, mixed>>  $linhas */
    private function txt(array $linhas): string
    {
        $saida = [
            'RANKING PRESENCIAL — '.count($linhas).' projeto(s)',
            'Gerado em '.now()->format('d/m/Y H:i'),
            str_repeat('-', 70),
        ];

        foreach ($linhas as $l) {
            $saida[] = sprintf(
                '%3dº  %s  (%d comum · %d comissão%s)',
                $l['posicao'],
                self::nota($l['media']),
                $l['comuns'],
                $l['comite'],
                $l['parcial'] ? ', parcial' : '',
            );
            $saida[] = '      '.($l['codigo'] ? $l['codigo'].' - ' : '').$l['titulo'];
            $saida[] = '      '.($l['area'] ?? 'Sem área').($l['categoria'] ? ' / '.$l['categoria'] : '')
                .($l['estande'] !== null ? ' · Estande '.str_pad((string) $l['estande'], 3, '0', STR_PAD_LEFT) : '');
            $saida[] = '';
        }

        return implode("\n", $saida);
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     * @param  array{area_id?: int|null, categoria?: string|null}  $filtros
     */
    private function pdf(array $linhas, array $filtros): string
    {
        // O recorte vai impresso: uma folha de ranking que não diz de que área
        // é não serve na mesa da comissão.
        $area = ($filtros['area_id'] ?? null) ? Area::find($filtros['area_id'])?->nome : null;
        $categoria = ($filtros['categoria'] ?? null) ? Categoria::tryFrom($filtros['categoria'])?->label() : null;
        $janela = JanelaTurnos::daEdicao(Edicao::atual());

        return app(PdfService::class)->render('pdf.ranking-presencial', [
            'linhas' => $linhas,
            'edicao' => Edicao::atual()?->nome ?? 'FETECMS',
            'gerado_em' => now()->format('d/m/Y H:i'),
            'recorte' => ($area ?? 'Todas as áreas').' · '.($categoria ?? 'Todas as categorias'),
            'peso_comite' => $janela->pesoComite(),
        ]);
    }

    /**
     * A média ponderada de cada projeto com ao menos uma avaliação presencial
     * enviada, por id do projeto — a conta do ranking, sem a
     * apresentação. A lista presencial (Sprint 251) usa a mesma conta.
     *
     * @param  list<int>  $projetoIds
     * @return array<int, array{media: float, avaliacoes: int, comuns: int, comite: int, parcial: bool, notas: list<array<string, mixed>>}>
     */
    public function medias(array $projetoIds, ?JanelaTurnos $janela = null): array
    {
        $janela ??= JanelaTurnos::daEdicao(Edicao::atual());

        $avaliacoes = AvaliacaoPresencial::whereIn('projeto_id', $projetoIds)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->whereNotNull('nota')
            ->orderBy('concluida_em')
            ->get(['id', 'projeto_id', 'nota', 'vaga'])
            ->groupBy('projeto_id');

        $medias = [];

        foreach ($avaliacoes as $projetoId => $doProjeto) {
            $soma = 0.0;
            $pesos = 0.0;
            $notas = [];

            foreach ($doProjeto as $a) {
                $vaga = $a->vaga ?? VagaPresencial::Comum;
                $peso = $janela->peso($vaga);
                $soma += $a->nota * $peso;
                $pesos += $peso;
                $notas[] = ['nota' => $a->nota, 'vaga' => $vaga->value, 'peso' => $peso];
            }

            $comuns = count(array_filter($notas, fn (array $n) => $n['vaga'] === VagaPresencial::Comum->value));
            $comite = count($notas) - $comuns;

            $medias[(int) $projetoId] = [
                'media' => round($soma / $pesos, RubricaPresencial::CASAS),
                'avaliacoes' => count($notas),
                'comuns' => $comuns,
                'comite' => $comite,
                'parcial' => $comuns < $janela->comunsPorProjeto() || $comite < $janela->comitePorProjeto(),
                'notas' => $notas,
            ];
        }

        return $medias;
    }

    /**
     * @param  array{area_id?: int|null, categoria?: string|null}  $filtros
     * @return list<array<string, mixed>>
     */
    private function linhas(ListaFinal $lista, JanelaTurnos $janela, array $filtros): array
    {
        $projetos = Projeto::whereIn('id', $lista->projetos()->pluck('projetos.id'))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($filtros['categoria'] ?? null, fn ($q, $categoria) => $q->where('categoria', $categoria))
            ->with('area:id,nome')
            ->get(['id', 'titulo', 'area_id', 'categoria'])
            ->keyBy('id');

        $medias = $this->medias($projetos->keys()->all(), $janela);

        $codigos = $this->listas->codigosDaLista($lista);
        $estandes = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)
            ->whereIn('projeto_id', array_keys($medias))
            ->get(['projeto_id', 'numero', 'turno'])
            ->keyBy('projeto_id');

        $linhas = [];

        foreach ($medias as $projetoId => $m) {
            $projeto = $projetos[$projetoId];
            $estande = $estandes[$projetoId] ?? null;

            $linhas[] = [
                'projeto_id' => $projeto->id,
                'codigo' => $codigos[$projeto->id] ?? null,
                'titulo' => $projeto->titulo,
                'area' => $projeto->area?->nome,
                'categoria' => $projeto->categoria?->label(),
                'estande' => $estande?->numero,
                'turno_label' => $estande?->turno?->label(),
                'media' => $m['media'],
                'avaliacoes' => $m['avaliacoes'],
                'comuns' => $m['comuns'],
                'comite' => $m['comite'],
                'notas' => $m['notas'],
                'parcial' => $m['parcial'],
            ];
        }

        usort($linhas, fn (array $a, array $b) => [$b['media'], $b['avaliacoes'], $a['titulo']]
            <=> [$a['media'], $a['avaliacoes'], $b['titulo']]);

        // Posição atribuída depois da ordenação; empate na média divide o lugar.
        $posicao = 0;
        $anterior = null;
        foreach ($linhas as $i => &$linha) {
            if ($linha['media'] !== $anterior) {
                $posicao = $i + 1;
                $anterior = $linha['media'];
            }
            $linha['posicao'] = $posicao;
        }
        unset($linha);

        return $linhas;
    }
}
