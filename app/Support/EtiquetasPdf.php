<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Dompdf\Cpdf;
use Picqer\Barcode\Types\TypeCode128;

/**
 * As etiquetas de identificação em PDF, desenhadas direto na página.
 *
 * A primeira versão passava por HTML no Dompdf e quebrava de dois jeitos. O
 * Dompdf **não pinta SVG escrito dentro do HTML**, então as etiquetas saíam sem
 * QR e sem barras; e o motor de layout dele custa ~0,2 MB e 20 ms por etiqueta,
 * o que estourava a memória do servidor bem antes dos ~1.800 participantes de
 * uma lista final. Etiqueta é uma grade fixa — não precisa de motor de layout.
 *
 * Aqui cada etiqueta é desenhada no `Cpdf` (a camada de baixo do próprio
 * Dompdf, já instalada): texto nas fontes padrão do PDF, que não precisam ser
 * embutidas, e os dois códigos como **retângulos pretos** — o QR módulo a
 * módulo (emendando os vizinhos da mesma linha) e o Code 128 barra a barra.
 * Vetor puro: imprime nítido em qualquer impressora, sem depender da extensão
 * GD, e 2.000 etiquetas saem em poucos segundos.
 *
 * Layout: A4 retrato, **2 colunas × 5 linhas** (10 por folha), com moldura
 * tracejada para o recorte.
 */
final class EtiquetasPdf
{
    private const PAGINA_L = 595.28;

    private const PAGINA_A = 841.89;

    private const MARGEM = 28.0;

    private const COLUNAS = 2;

    private const LINHAS = 5;

    private const ESPACO = 8.0;

    private Cpdf $pdf;

    private string $fontes;

    private ?string $fonteAtual = null;

    /**
     * @param  list<array{papel_label: string, nome: string, projeto: ?string, escola: ?string, codigo: string}>  $etiquetas
     */
    public static function gerar(string $titulo, string $subtitulo, array $etiquetas): string
    {
        return (new self)->desenhar($titulo, $subtitulo, $etiquetas);
    }

    private function __construct()
    {
        $this->fontes = base_path('vendor/dompdf/dompdf/lib/fonts');
        $this->pdf = new Cpdf([0, 0, self::PAGINA_L, self::PAGINA_A], false, $this->fontes, sys_get_temp_dir());
    }

    /** @param  list<array<string, mixed>>  $etiquetas */
    private function desenhar(string $titulo, string $subtitulo, array $etiquetas): string
    {
        $largura = (self::PAGINA_L - 2 * self::MARGEM - (self::COLUNAS - 1) * self::ESPACO) / self::COLUNAS;
        $topo = self::PAGINA_A - self::MARGEM - 34;
        $altura = ($topo - self::MARGEM - (self::LINHAS - 1) * self::ESPACO) / self::LINHAS;
        $porPagina = self::COLUNAS * self::LINHAS;

        $paginas = max(1, (int) ceil(count($etiquetas) / $porPagina));

        for ($p = 0; $p < $paginas; $p++) {
            if ($p > 0) {
                $this->pdf->newPage();
            }

            $this->cabecalho($titulo, $subtitulo, $p + 1, $paginas);

            if ($etiquetas === []) {
                $this->texto('Helvetica', 10, self::MARGEM, $topo - 20, 'A lista final não tem participantes.', [0.29, 0.27, 0.31]);
            }

            foreach (array_slice($etiquetas, $p * $porPagina, $porPagina) as $i => $etiqueta) {
                $x = self::MARGEM + ($i % self::COLUNAS) * ($largura + self::ESPACO);
                $y = $topo - intdiv($i, self::COLUNAS) * ($altura + self::ESPACO) - $altura;

                $this->etiqueta($etiqueta, $x, $y, $largura, $altura);
            }
        }

        return (string) $this->pdf->output();
    }

    private function cabecalho(string $titulo, string $subtitulo, int $pagina, int $total): void
    {
        $y = self::PAGINA_A - self::MARGEM - 14;
        $this->texto('Helvetica-Bold', 14, self::MARGEM, $y, $titulo, [0.26, 0.08, 0.48]);
        $this->texto('Helvetica', 8, self::MARGEM, $y - 13, $subtitulo." · página {$pagina} de {$total}", [0.29, 0.27, 0.31]);

        $this->pdf->setStrokeColor([0.26, 0.08, 0.48]);
        $this->pdf->setLineStyle(1.5);
        $this->pdf->line(self::MARGEM, $y - 19, self::PAGINA_L - self::MARGEM, $y - 19);
    }

    /** @param  array<string, mixed>  $e */
    private function etiqueta(array $e, float $x, float $y, float $l, float $a): void
    {
        // Moldura tracejada: é por ela que a tesoura passa.
        $this->pdf->setStrokeColor([0.81, 0.78, 0.86]);
        $this->pdf->setLineStyle(0.8, '', '', [3, 2]);
        $this->pdf->rectangle($x, $y, $l, $a);
        $this->pdf->setLineStyle(1, '', '', []);

        $pad = 9.0;
        $util = $l - 2 * $pad;
        $cursor = $y + $a - $pad;

        $cursor -= 8;
        $this->texto('Helvetica', 7, $x + $pad, $cursor, mb_strtoupper((string) $e['papel_label']), [0.26, 0.08, 0.48]);

        foreach ($this->quebrar('Helvetica-Bold', 11, (string) $e['nome'], $util, 2) as $linha) {
            $cursor -= 13;
            $this->texto('Helvetica-Bold', 11, $x + $pad, $cursor, $linha, [0.16, 0, 0.35]);
        }

        $detalhe = trim((string) ($e['projeto'] ?? ''));
        foreach ($this->quebrar('Helvetica', 7, $detalhe, $util, 2) as $linha) {
            $cursor -= 9;
            $this->texto('Helvetica', 7, $x + $pad, $cursor, $linha, [0.29, 0.27, 0.31]);
        }

        if (! empty($e['escola'])) {
            $cursor -= 9;
            $escola = $this->quebrar('Helvetica', 7, (string) $e['escola'], $util, 1)[0] ?? '';
            $this->texto('Helvetica', 7, $x + $pad, $cursor, $escola, [0.29, 0.27, 0.31]);
        }

        // Os códigos ocupam a base da etiqueta, com o texto do código embaixo.
        $baseCodigos = $y + $pad + 12;
        $ladoQr = min(66.0, $cursor - $baseCodigos - 4);

        if ($ladoQr > 20) {
            $this->qr((string) $e['codigo'], $x + $pad, $baseCodigos, $ladoQr);
            $this->barras((string) $e['codigo'], $x + $pad + $ladoQr + 8, $baseCodigos + 4, $util - $ladoQr - 8, min(44.0, $ladoQr - 8));
        }

        $this->texto('Courier', 8.5, $x + $pad, $y + $pad, (string) $e['codigo'], [0.11, 0.11, 0.12]);
    }

    /** O QR módulo a módulo, emendando os pretos vizinhos numa barra só. */
    private function qr(string $codigo, float $x, float $y, float $lado): void
    {
        $matriz = Encoder::encode($codigo, ErrorCorrectionLevel::M())->getMatrix();
        $n = $matriz->getWidth();
        $modulo = $lado / $n;

        $this->pdf->setColor([0, 0, 0]);

        for ($linha = 0; $linha < $n; $linha++) {
            $inicio = null;

            for ($col = 0; $col <= $n; $col++) {
                $preto = $col < $n && $matriz->get($col, $linha) === 1;

                if ($preto && $inicio === null) {
                    $inicio = $col;
                } elseif (! $preto && $inicio !== null) {
                    $this->pdf->filledRectangle(
                        $x + $inicio * $modulo,
                        $y + $lado - ($linha + 1) * $modulo,
                        ($col - $inicio) * $modulo,
                        $modulo,
                    );
                    $inicio = null;
                }
            }
        }
    }

    /** O Code 128 barra a barra, esticado na largura que sobrou ao lado do QR. */
    private function barras(string $codigo, float $x, float $y, float $largura, float $altura): void
    {
        $codigoBarras = (new TypeCode128)->getBarcode($codigo);
        $unidade = $largura / max(1, $codigoBarras->getWidth());
        $posicao = $x;

        $this->pdf->setColor([0, 0, 0]);

        foreach ($codigoBarras->getBars() as $barra) {
            $l = $barra->getWidth() * $unidade;

            if ($barra->isBar()) {
                $this->pdf->filledRectangle($posicao, $y, $l, $altura);
            }

            $posicao += $l;
        }
    }

    /** @param  array{0: float, 1: float, 2: float}  $cor */
    private function texto(string $fonte, float $tamanho, float $x, float $y, string $texto, array $cor): void
    {
        $this->fonte($fonte);
        $this->pdf->setColor($cor);
        $this->pdf->addText($x, $y, $tamanho, $texto);
    }

    /** Troca de fonte só quando muda: o `selectFont` relê a métrica a cada chamada. */
    private function fonte(string $fonte): void
    {
        if ($this->fonteAtual !== $fonte) {
            $this->pdf->selectFont($this->fontes.'/'.$fonte);
            $this->fonteAtual = $fonte;
        }
    }

    /**
     * Quebra o texto em até `$max` linhas que caibam na largura; a última leva
     * reticências se ainda sobrar texto.
     *
     * @return list<string>
     */
    private function quebrar(string $fonte, float $tamanho, string $texto, float $largura, int $max): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        if ($texto === '') {
            return [];
        }

        $this->fonte($fonte);
        $cabe = fn (string $s) => $this->pdf->getTextWidth($tamanho, $s) <= $largura;

        $linhas = [];
        $atual = '';

        foreach (explode(' ', $texto) as $palavra) {
            $tentativa = $atual === '' ? $palavra : $atual.' '.$palavra;

            if ($cabe($tentativa)) {
                $atual = $tentativa;

                continue;
            }

            if ($atual !== '') {
                $linhas[] = $atual;
            }
            $atual = $palavra;
        }

        if ($atual !== '') {
            $linhas[] = $atual;
        }

        if (count($linhas) <= $max) {
            return array_map(fn ($l) => $this->cortar($l, $largura, $tamanho), $linhas);
        }

        $visiveis = array_slice($linhas, 0, $max);
        $visiveis[$max - 1] = $this->cortar($visiveis[$max - 1].' …', $largura, $tamanho, forcar: true);

        return $visiveis;
    }

    /** Corta uma linha que nem quebrando coube (uma palavra enorme). */
    private function cortar(string $linha, float $largura, float $tamanho, bool $forcar = false): string
    {
        if (! $forcar && $this->pdf->getTextWidth($tamanho, $linha) <= $largura) {
            return $linha;
        }

        $base = rtrim(preg_replace('/\s*…$/u', '', $linha) ?? $linha);

        while ($base !== '' && $this->pdf->getTextWidth($tamanho, $base.'…') > $largura) {
            $base = mb_substr($base, 0, -1);
        }

        return rtrim($base).'…';
    }
}
