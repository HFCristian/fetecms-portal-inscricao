<?php

namespace App\Support;

use App\Services\MapaAvaliacoesService;
use Dompdf\Cpdf;
use Illuminate\Support\Str;

/**
 * O mapa das **avaliações por projeto** em PDF (Sprint 248), desenhado direto
 * na página.
 *
 * O Dompdf não pinta SVG escrito no HTML (é o que levou as etiquetas para o
 * `Cpdf`, {@see EtiquetasPdf}), e a planta é só retângulo e texto: cada estande
 * vira um quadrado na cor da categoria, com o número no meio, na mesma posição
 * da tela. Uma página A4 deitada: cabeçalho com turno, corte e filtro; a
 * planta, ajustada ao espaço; e a legenda com quantos projetos há em cada
 * categoria.
 *
 * O filtro da tela vale aqui do mesmo jeito: o estande fora dele fica
 * **apagado** (a cor clareada), e não some — a planta continua sendo lida pelo
 * lugar de cada coisa. As marcas também são as da tela: ponto no canto para a
 * avaliação em andamento, borda tracejada para quem ainda não está pronto e um
 * traço para quem saiu antes do horário — a cor nunca é a única pista.
 */
final class MapaAvaliacoesPdf
{
    private const PAGINA_L = 841.89;

    private const PAGINA_A = 595.28;

    private const MARGEM = 28.0;

    private const ROXO = [0.26, 0.08, 0.48];

    private const CINZA = [0.32, 0.32, 0.31];

    private const VAZIO = ['fill' => '#f3f1f6', 'stroke' => '#e6e0ee', 'texto' => '#b9b2c4'];

    private const COR_ANDAMENTO = '#2a0058';

    private const COR_SAIU = '#2e2b33';

    private Cpdf $pdf;

    private string $fontes;

    private ?string $fonteAtual = null;

    /**
     * @param  array<string, mixed>  $painel  {@see MapaAvaliacoesService::painel()}
     * @param  list<int>  $visiveis  os projetos que o filtro deixa à mostra
     */
    public static function gerar(array $painel, array $visiveis, string $filtro): string
    {
        return (new self)->desenhar($painel, array_fill_keys($visiveis, true), $filtro);
    }

    private function __construct()
    {
        $this->fontes = base_path('vendor/dompdf/dompdf/lib/fonts');
        $this->pdf = new Cpdf([0, 0, self::PAGINA_L, self::PAGINA_A], false, $this->fontes, sys_get_temp_dir());
    }

    /** @param  array<int, true>  $visiveis */
    private function desenhar(array $painel, array $visiveis, string $filtro): string
    {
        $topo = $this->cabecalho($painel, $filtro);
        $legendaAltura = $this->alturaDaLegenda($painel);
        $porNumero = [];

        foreach ($painel['linhas'] as $l) {
            $porNumero[$l['numero']] = $l;
        }

        $this->planta($painel, $porNumero, $visiveis, self::MARGEM + $legendaAltura + 10, $topo - 8);
        $this->legenda($painel, self::MARGEM + $legendaAltura);

        return (string) $this->pdf->output();
    }

    /** Título, edição, turno, corte e o filtro. Devolve onde o cabeçalho termina. */
    private function cabecalho(array $painel, string $filtro): float
    {
        $y = self::PAGINA_A - self::MARGEM - 12;
        $this->texto('Helvetica-Bold', 14, self::MARGEM, $y, 'Mapa do evento — avaliações por projeto', self::ROXO);

        $sub = implode(' · ', array_filter([
            $painel['edicao'],
            $painel['turno_label'],
            'situação em '.$painel['corte_label'],
            $painel['vagas']['comuns'].' comum(ns) + '.$painel['vagas']['comite'].' do comitê por projeto',
        ]));
        $this->texto('Helvetica', 9, self::MARGEM, $y - 14, $sub, self::CINZA);
        $this->texto('Helvetica', 9, self::MARGEM, $y - 26, 'Filtro: '.$filtro, self::CINZA);

        $this->pdf->setStrokeColor(self::ROXO);
        $this->pdf->setLineStyle(1.2, '', '', []);
        $this->pdf->line(self::MARGEM, $y - 33, self::PAGINA_L - self::MARGEM, $y - 33);

        return $y - 33;
    }

    /**
     * A planta ajustada ao retângulo entre `$base` e `$topo`, centrada na
     * largura.
     *
     * @param  array<int, array<string, mixed>>  $porNumero
     * @param  array<int, true>  $visiveis
     */
    private function planta(array $painel, array $porNumero, array $visiveis, float $base, float $topo): void
    {
        $estandes = $painel['planta']['estandes'];
        $marcacoes = $painel['planta']['marcacoes'];

        if ($estandes === []) {
            $this->texto('Helvetica', 10, self::MARGEM, $topo - 14, 'A planta do evento não tem estandes.', self::CINZA);

            return;
        }

        $xs = array_merge(array_column($estandes, 'x'), array_column($marcacoes, 'x'));
        $ys = array_merge(array_column($estandes, 'y'), array_column($marcacoes, 'y'));
        $maxX = max(array_merge(
            array_map(fn ($e) => $e['x'] + 1, $estandes),
            array_map(fn ($m) => $m['x'] + $m['largura'], $marcacoes),
        ));
        $maxY = max(array_merge(
            array_map(fn ($e) => $e['y'] + 1, $estandes),
            array_map(fn ($m) => $m['y'] + $m['altura'], $marcacoes),
        ));
        $minX = min($xs);
        $minY = min($ys);

        $largura = self::PAGINA_L - 2 * self::MARGEM;
        $celula = min($largura / max(1, $maxX - $minX), ($topo - $base) / max(1, $maxY - $minY));
        $esquerda = self::MARGEM + ($largura - $celula * ($maxX - $minX)) / 2;
        // O y do PDF cresce para cima; o da planta, para baixo.
        $px = fn (float $x) => $esquerda + ($x - $minX) * $celula;
        $py = fn (float $y) => $topo - ($y - $minY) * $celula;

        foreach ($marcacoes as $m) {
            $this->pdf->setColor($this->rgb('#e1efe4'));
            $this->pdf->filledRectangle($px($m['x']), $py($m['y'] + $m['altura']), $m['largura'] * $celula, $m['altura'] * $celula);
            $this->centrado('Helvetica', max(5, $celula * 0.3), $px($m['x'] + $m['largura'] / 2), $py($m['y'] + $m['altura'] / 2) - $celula * 0.1, (string) $m['rotulo'], $this->rgb('#006e1f'));
        }

        $cores = [];
        foreach ($painel['categorias'] as $c) {
            $cores[$c['chave']] = ['fill' => $c['cor'], 'stroke' => $c['borda'], 'texto' => $c['texto']];
        }

        $lado = $celula * 0.9;

        foreach ($estandes as $e) {
            $linha = $porNumero[$e['numero']] ?? null;
            $cor = $linha === null ? self::VAZIO : $cores[$linha['situacao']];
            $apagado = $linha !== null && ! isset($visiveis[$linha['projeto_id']]);
            $x = $px($e['x']);
            $y = $py($e['y']) - $lado;

            $this->pdf->setColor($this->rgb($cor['fill'], $apagado));
            $this->pdf->filledRectangle($x, $y, $lado, $lado);

            $this->pdf->setStrokeColor($this->rgb($cor['stroke'], $apagado));
            $tracejada = $linha !== null && ! $linha['pronto'];
            $this->pdf->setLineStyle(max(0.5, $celula * ($tracejada ? 0.05 : 0.03)), '', '', $tracejada ? [$celula * 0.12, $celula * 0.08] : []);
            $this->pdf->rectangle($x, $y, $lado, $lado);
            $this->pdf->setLineStyle(1, '', '', []);

            // O traço de quem saiu vai por baixo do número, que continua legível.
            if ($linha !== null && $linha['saida'] !== null) {
                $this->pdf->setStrokeColor($this->rgb(self::COR_SAIU, $apagado));
                $this->pdf->setLineStyle(max(0.6, $celula * 0.06), '', '', []);
                $this->pdf->line($x + $lado * 0.1, $y + $lado * 0.1, $x + $lado * 0.9, $y + $lado * 0.9);
                $this->pdf->setLineStyle(1, '', '', []);
            }

            $this->centrado(
                'Helvetica-Bold',
                max(4, $celula * 0.3),
                $x + $lado / 2,
                $y + $lado / 2 - $celula * 0.1,
                str_pad((string) $e['numero'], 3, '0', STR_PAD_LEFT),
                $this->rgb($cor['texto'], $apagado),
            );

            if ($linha !== null && $linha['em_andamento'] > 0) {
                $raio = $celula * 0.13;
                $this->pdf->setColor([1, 1, 1]);
                $this->pdf->filledEllipse($x + $lado - $raio * 1.1, $y + $lado - $raio * 1.1, $raio * 1.35);
                $this->pdf->setColor($this->rgb(self::COR_ANDAMENTO, $apagado));
                $this->pdf->filledEllipse($x + $lado - $raio * 1.1, $y + $lado - $raio * 1.1, $raio);
            }
        }

        $fora = array_values(array_filter($painel['linhas'], fn ($l) => ! $l['na_planta']));
        if ($fora !== []) {
            $this->texto(
                'Helvetica',
                8,
                self::MARGEM,
                $base - 6,
                'Fora da planta: '.implode(', ', array_map(fn ($l) => $l['estande'].' ('.$l['situacao_label'].')', $fora)),
                self::CINZA,
            );
        }
    }

    /** Três colunas: as categorias e as três marcas. */
    private function alturaDaLegenda(array $painel): float
    {
        $itens = count($painel['categorias']) + 3;

        return (int) ceil($itens / 3) * 14 + 6;
    }

    private function legenda(array $painel, float $topo): void
    {
        $coluna = (self::PAGINA_L - 2 * self::MARGEM) / 3;
        $itens = [];

        foreach ($painel['categorias'] as $c) {
            $itens[] = ['tipo' => 'cor', 'cor' => $c, 'texto' => $c['label'].' — '.$c['total']];
        }

        $itens[] = ['tipo' => 'andamento', 'texto' => 'Com avaliação em andamento — '.$painel['marcas']['em_andamento']];
        $itens[] = ['tipo' => 'nao_pronto', 'texto' => 'Sem credenciamento ou checagem (borda tracejada) — '.$painel['marcas']['nao_pronto']];
        $itens[] = ['tipo' => 'saiu', 'texto' => 'Saiu antes do horário — '.$painel['marcas']['saiu']];

        foreach ($itens as $i => $item) {
            $x = self::MARGEM + ($i % 3) * $coluna;
            $y = $topo - intdiv($i, 3) * 14 - 10;

            match ($item['tipo']) {
                'cor' => $this->amostra($x, $y, $item['cor']['cor'], $item['cor']['borda']),
                'andamento' => $this->pontoAndamento($x, $y),
                'nao_pronto' => $this->amostraTracejada($x, $y),
                'saiu' => $this->amostraSaiu($x, $y),
            };

            $this->texto('Helvetica', 8.5, $x + 14, $y + 1, $item['texto'], [0.11, 0.11, 0.12]);
        }

        $this->texto('Helvetica', 7, self::MARGEM, self::MARGEM - 14, 'Gerado em '.now()->format('d/m/Y H:i').' · estandes apagados estão fora do filtro', [0.47, 0.45, 0.49]);
    }

    private function amostra(float $x, float $y, string $fill, string $borda): void
    {
        $this->pdf->setColor($this->rgb($fill));
        $this->pdf->filledRectangle($x, $y, 9, 9);
        $this->pdf->setStrokeColor($this->rgb($borda));
        $this->pdf->setLineStyle(0.6, '', '', []);
        $this->pdf->rectangle($x, $y, 9, 9);
    }

    private function pontoAndamento(float $x, float $y): void
    {
        $this->pdf->setColor($this->rgb(self::COR_ANDAMENTO));
        $this->pdf->filledEllipse($x + 4.5, $y + 4.5, 3.2);
    }

    private function amostraTracejada(float $x, float $y): void
    {
        $this->pdf->setStrokeColor($this->rgb('#52514e'));
        $this->pdf->setLineStyle(0.9, '', '', [1.6, 1.1]);
        $this->pdf->rectangle($x, $y, 9, 9);
        $this->pdf->setLineStyle(1, '', '', []);
    }

    private function amostraSaiu(float $x, float $y): void
    {
        $this->pdf->setStrokeColor($this->rgb('#8f889b'));
        $this->pdf->setLineStyle(0.6, '', '', []);
        $this->pdf->rectangle($x, $y, 9, 9);
        $this->pdf->setStrokeColor($this->rgb(self::COR_SAIU));
        $this->pdf->setLineStyle(1.2, '', '', []);
        $this->pdf->line($x + 1, $y + 1, $x + 8, $y + 8);
        $this->pdf->setLineStyle(1, '', '', []);
    }

    /**
     * Hex em [r, g, b] de 0 a 1. Apagado = 82% do caminho até o branco: ainda
     * se vê o lugar, sem competir com o que está no filtro.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function rgb(string $hex, bool $apagado = false): array
    {
        $c = array_map(fn (int $i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);

        return $apagado ? array_map(fn (float $v) => $v + (1 - $v) * 0.82, $c) : $c;
    }

    /** @param  array{0: float, 1: float, 2: float}  $cor */
    private function texto(string $fonte, float $tamanho, float $x, float $y, string $texto, array $cor): void
    {
        $this->fonte($fonte);
        $this->pdf->setColor($cor);
        $this->pdf->addText($x, $y, $tamanho, $texto);
    }

    /** @param  array{0: float, 1: float, 2: float}  $cor */
    private function centrado(string $fonte, float $tamanho, float $cx, float $y, string $texto, array $cor): void
    {
        $this->fonte($fonte);
        // Fora do modo Unicode o Cpdf mede byte a byte: mede-se sem acento,
        // que nas fontes padrão tem a mesma largura.
        $largura = $this->pdf->getTextWidth($tamanho, Str::ascii($texto));
        $this->texto($fonte, $tamanho, $cx - $largura / 2, $y, $texto, $cor);
    }

    private function fonte(string $fonte): void
    {
        if ($this->fonteAtual !== $fonte) {
            $this->pdf->selectFont($this->fontes.'/'.$fonte);
            $this->fonteAtual = $fonte;
        }
    }
}
