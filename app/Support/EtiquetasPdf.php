<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Dompdf\Cpdf;
use Illuminate\Support\Str;
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
     * @param  list<array{papel_label: string, nome: string, codigo: string, turno?: ?string, estande?: ?int}>  $etiquetas
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

    /**
     * Uma etiqueta: o QR à esquerda, na altura toda; à direita o nome com o
     * papel ao lado ("Ana Paula - aluno(a)"), o turno e o estande, e o código
     * de barras com o texto do código embaixo.
     *
     * Projeto e escola saíram (Sprint 188): o crachá é de uma pessoa, e o que
     * ela procura depois do balcão é onde e quando apresenta — o título do
     * projeto ocupava duas linhas para dizer o que ela já sabe.
     *
     * @param  array<string, mixed>  $e
     */
    private function etiqueta(array $e, float $x, float $y, float $l, float $a): void
    {
        // Moldura tracejada: é por ela que a tesoura passa.
        $this->pdf->setStrokeColor([0.81, 0.78, 0.86]);
        $this->pdf->setLineStyle(0.8, '', '', [3, 2]);
        $this->pdf->rectangle($x, $y, $l, $a);
        $this->pdf->setLineStyle(1, '', '', []);

        $pad = 7.0;
        $codigo = (string) $e['codigo'];

        // O QR na altura toda: é o que a câmera do balcão lê, e quanto maior,
        // de mais longe ela lê.
        $ladoQr = min(84.0, $a - 2 * $pad, ($l - 2 * $pad) * 0.32);
        $this->qr($codigo, $x + $pad, $y + ($a - $ladoQr) / 2, $ladoQr);

        $tx = $x + $pad + $ladoQr + 8;
        $tl = $x + $l - $pad - $tx;
        $cursor = $y + $a - $pad - 11;

        $papel = mb_strtolower(trim((string) ($e['papel_label'] ?? '')));
        $nome = $this->nomeComPapel((string) $e['nome'], $papel, $tl);
        $tamanhoSufixo = max(7.5, $nome['tamanho'] - 2);

        foreach ($nome['linhas'] as $i => [$parte, $sufixo]) {
            if ($i > 0) {
                $cursor -= $nome['tamanho'] + 1.5;
            }

            if ($parte !== '') {
                $this->texto('Helvetica-Bold', $nome['tamanho'], $tx, $cursor, $parte, [0.16, 0, 0.35]);
            }

            if ($sufixo !== '') {
                $this->fonte('Helvetica-Bold');
                $deslocamento = $parte === '' ? 0.0 : $this->largura($nome['tamanho'], $parte);
                $this->texto('Helvetica', $tamanhoSufixo, $tx + $deslocamento, $cursor, $parte === '' ? ltrim($sufixo) : $sufixo, [0.26, 0.08, 0.48]);
            }
        }

        $local = $this->turnoEEstande($e);

        if ($local !== '') {
            $cursor -= 12;
            $this->fonte('Helvetica-Bold');
            $this->texto('Helvetica-Bold', 8, $tx, $cursor, $this->cortar($local, $tl, 8), [0.26, 0.08, 0.48]);
        }

        // O código de barras na base, com o texto do código por último.
        $baseBarras = $y + $pad + 11;
        $alturaBarras = min(44.0, $cursor - 6 - $baseBarras);

        if ($alturaBarras > 10) {
            $this->barras($codigo, $tx, $baseBarras, $tl, $alturaBarras);
        }

        $this->texto('Courier', 8.5, $tx, $y + $pad + 1, $codigo, [0.11, 0.11, 0.12]);
    }

    /** "Turno A (matutino) · Estande 42" — o que houver dos dois. */
    private function turnoEEstande(array $e): string
    {
        $partes = array_filter([
            trim((string) ($e['turno'] ?? '')),
            ($e['estande'] ?? null) === null ? '' : 'Estande '.$e['estande'],
        ], fn (string $p) => $p !== '');

        return implode(' · ', $partes);
    }

    /**
     * O nome em até duas linhas, com o papel colado na última: cada linha é
     * [parte em negrito, sufixo " - aluno(a)"]. Quando o sufixo não cabe na
     * última linha do nome, a última palavra desce com ele — um papel sozinho
     * na linha parece outro dado, não o papel daquela pessoa.
     *
     * Nome comprido diminui a fonte (11 → 10 → 9) antes de ser cortado: o nome
     * inteiro é o que o balcão confere contra o documento.
     *
     * @return array{tamanho: float, linhas: list<array{0: string, 1: string}>}
     */
    private function nomeComPapel(string $nome, string $papel, float $largura): array
    {
        $sufixo = $papel === '' ? '' : ' - '.$papel;

        foreach ([11.0, 10.0, 9.0] as $tamanho) {
            $this->fonte('Helvetica');
            $larguraSufixo = $sufixo === '' ? 0.0 : $this->largura(max(7.5, $tamanho - 2), $sufixo);

            // Todas as linhas do nome; daqui em diante a fonte selecionada é a negrita.
            $linhas = $this->quebrar('Helvetica-Bold', $tamanho, $nome, $largura, PHP_INT_MAX);

            if ($linhas === []) {
                return ['tamanho' => $tamanho, 'linhas' => $sufixo === '' ? [] : [['', $sufixo]]];
            }

            $ultima = array_pop($linhas);

            if ($sufixo !== '' && $this->largura($tamanho, $ultima) + $larguraSufixo > $largura) {
                $espaco = mb_strrpos($ultima, ' ');
                $linhas[] = $espaco === false ? $ultima : mb_substr($ultima, 0, $espaco);
                $ultima = $espaco === false ? '' : mb_substr($ultima, $espaco + 1);
            }

            $linhas[] = $ultima;
            $cabe = count($linhas) <= 2 && $this->largura($tamanho, $ultima) + $larguraSufixo <= $largura;

            if ($cabe || $tamanho === 9.0) {
                break;
            }
        }

        if (count($linhas) > 2) {
            $linhas = [$linhas[0], $this->cortar(implode(' ', array_slice($linhas, 1)), $largura - $larguraSufixo, $tamanho, forcar: true)];
        } else {
            $linhas[count($linhas) - 1] = $this->cortar($linhas[count($linhas) - 1], $largura - $larguraSufixo, $tamanho);
        }

        $ultimo = count($linhas) - 1;

        return [
            'tamanho' => $tamanho,
            'linhas' => array_map(fn (string $linha, int $i) => [$linha, $i === $ultimo ? $sufixo : ''], $linhas, array_keys($linhas)),
        ];
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

    /**
     * A largura do texto na fonte selecionada.
     *
     * O `Cpdf` está fora do modo Unicode e mede **byte a byte**: em UTF-8 cada
     * acento conta como dois caracteres (o nome parecia mais largo do que é), e
     * convertido para Windows-1252 ele descarta a string inteira (o filtro de
     * controle dele usa `/u`). Nas fontes padrão do PDF a letra acentuada tem a
     * largura da letra base (é = e, Ç = C), então mede-se o texto sem acento.
     */
    private function largura(float $tamanho, string $texto): float
    {
        return $this->pdf->getTextWidth($tamanho, Str::ascii(strtr($texto, ['·' => '.', '…' => '...'])));
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
        $cabe = fn (string $s) => $this->largura($tamanho, $s) <= $largura;

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
        if (! $forcar && $this->largura($tamanho, $linha) <= $largura) {
            return $linha;
        }

        $base = rtrim(preg_replace('/\s*…$/u', '', $linha) ?? $linha);

        while ($base !== '' && $this->largura($tamanho, $base.'…') > $largura) {
            $base = mb_substr($base, 0, -1);
        }

        return rtrim($base).'…';
    }
}
