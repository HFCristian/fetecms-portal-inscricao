<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Escritor mínimo de apresentação .pptx (Office Open XML) — Sprint 254.
 *
 * Mesmo argumento do {@see PlanilhaXlsx}: o que o portal precisa é uma
 * sequência de slides com texto e uma foto, e um .pptx é um zip com meia dúzia
 * de XMLs. A biblioteca (PhpPresentation) resolveria tabela, gráfico, animação
 * e leitura, nada disso usado aqui.
 *
 * Três tipos de slide, os da cerimônia de premiação:
 *
 * - {@see titulo()}: o nome do prêmio, da credencial ou da colocação, em
 *   branco sobre o roxo da identidade;
 * - {@see foto()}: a fotografia do estande, inteira e centralizada (sem
 *   cortar), com a legenda embaixo — ou o aviso de que ela falta;
 * - {@see projeto()}: o projeto como está na lista — código e título, escola
 *   e equipe.
 *
 * As fontes são as da identidade (Space Grotesk nos títulos, Inter no texto);
 * onde não estiverem instaladas, o PowerPoint troca por uma parecida. O texto
 * comprido diminui de tamanho em degraus (o .pptx não mede texto sozinho ao
 * abrir).
 *
 * A foto entra **sem recompressão** e no tamanho original — o servidor pode não
 * ter GD para reduzi-la —, e a orientação gravada pela câmera (EXIF) vira
 * rotação do quadro, porque o PowerPoint mostra os pixels como estão.
 */
class ApresentacaoPptx
{
    /** 16:9 em EMU (1 polegada = 914 400). */
    public const LARGURA = 12192000;

    public const ALTURA = 6858000;

    private const POL = 914400;

    private const ROXO = '43157A';

    private const ROXO_ESCURO = '2A0058';

    private const VERDE = '007B24';

    private const LAVANDA = 'E9DDFF';

    private const CINZA = '49454F';

    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    /** @var list<array{xml: string, imagem: ?array{caminho: string, extensao: string}}> */
    private array $slides = [];

    public function __construct(private readonly string $tituloDocumento = 'Premiação') {}

    public function total(): int
    {
        return count($this->slides);
    }

    /**
     * O slide do prêmio: um rótulo pequeno em cima (o tipo), o nome grande e
     * um detalhe embaixo (órgão, área e categoria).
     */
    public function titulo(string $rotulo, string $nome, ?string $detalhe = null, ?string $rodape = null): void
    {
        $formas = [
            $this->texto(2, 'Rótulo', self::pol(0.8), self::pol(1.9), self::LARGURA - self::pol(1.6), self::pol(0.7),
                [mb_strtoupper($rotulo)], 2400, self::LAVANDA, negrito: true, fonte: 'Inter'),
            $this->texto(3, 'Título', self::pol(0.8), self::pol(2.6), self::LARGURA - self::pol(1.6), self::pol(2.4),
                [$nome], $this->tamanho($nome, [30 => 6000, 60 => 4800, 100 => 4000, 160 => 3200], 2800), 'FFFFFF', negrito: true),
        ];

        if ($detalhe !== null && $detalhe !== '') {
            $formas[] = $this->texto(4, 'Detalhe', self::pol(0.8), self::pol(5.05), self::LARGURA - self::pol(1.6), self::pol(0.8),
                [$detalhe], 2600, self::LAVANDA, fonte: 'Inter');
        }

        if ($rodape !== null && $rodape !== '') {
            $formas[] = $this->texto(5, 'Evento', self::pol(0.8), self::pol(0.45), self::LARGURA - self::pol(1.6), self::pol(0.5),
                [$rodape], 1600, self::LAVANDA, fonte: 'Inter');
        }

        $formas[] = $this->retangulo(6, 'Faixa', 0, self::ALTURA - self::pol(0.14), self::LARGURA, self::pol(0.14), self::VERDE);

        $this->slides[] = ['xml' => $this->slide(self::ROXO, $formas), 'imagem' => null];
    }

    /**
     * A foto do estande, ajustada na área útil sem cortar. Sem `$caminho` (ou
     * com um arquivo que não é JPG nem PNG), o slide diz que ela falta.
     */
    public function foto(?string $caminho, string $legenda, string $semFoto = 'Sem fotografia registrada'): void
    {
        $formas = [];
        $imagem = $caminho === null ? null : self::imagem($caminho);
        $caixa = ['x' => self::pol(0.5), 'y' => self::pol(0.35), 'l' => self::LARGURA - self::pol(1.0), 'a' => self::pol(6.0)];

        if ($imagem === null) {
            $formas[] = $this->texto(2, 'Sem foto', $caixa['x'], $caixa['y'], $caixa['l'], $caixa['a'],
                [$semFoto], 3600, self::LAVANDA, fonte: 'Inter');
        } else {
            // Com rotação de 90° o quadro gira em volta do centro: a caixa
            // visível é a da imagem já de pé, e o quadro guarda a de antes.
            [$largura, $altura] = $imagem['quarto'] ? [$imagem['altura'], $imagem['largura']] : [$imagem['largura'], $imagem['altura']];
            $escala = min($caixa['l'] / $largura, $caixa['a'] / $altura);
            $vl = (int) round($largura * $escala);
            $va = (int) round($altura * $escala);
            [$ql, $qa] = $imagem['quarto'] ? [$va, $vl] : [$vl, $va];
            $cx = $caixa['x'] + intdiv($caixa['l'], 2);
            $cy = $caixa['y'] + intdiv($caixa['a'], 2);

            $formas[] = $this->figura(2, $cx - intdiv($ql, 2), $cy - intdiv($qa, 2), $ql, $qa, $imagem['rotacao']);
        }

        $formas[] = $this->texto(3, 'Legenda', self::pol(0.5), self::pol(6.5), self::LARGURA - self::pol(1.0), self::pol(0.7),
            [$legenda], 2000, 'FFFFFF', fonte: 'Inter');

        $this->slides[] = [
            'xml' => $this->slide(self::ROXO_ESCURO, $formas, comImagem: $imagem !== null),
            'imagem' => $imagem === null ? null : ['caminho' => $caminho, 'extensao' => $imagem['extensao']],
        ];
    }

    /**
     * O projeto como na lista: código e título, a escola e a equipe (uma
     * pessoa por linha).
     *
     * @param  list<string>  $equipe
     */
    public function projeto(?string $codigo, string $titulo, string $escola, array $equipe): void
    {
        $formas = [$this->retangulo(2, 'Faixa lateral', 0, 0, self::pol(0.22), self::ALTURA, self::ROXO)];

        if ($codigo !== null && $codigo !== '') {
            $formas[] = $this->texto(3, 'Código', self::pol(0.8), self::pol(0.4), self::LARGURA - self::pol(1.4), self::pol(0.7),
                [$codigo], 3200, self::ROXO, negrito: true, alinhamento: 'l');
        }

        $formas[] = $this->texto(4, 'Projeto', self::pol(0.8), self::pol(1.1), self::LARGURA - self::pol(1.4), self::pol(2.0),
            [$titulo], $this->tamanho($titulo, [45 => 4800, 90 => 4000, 140 => 3200], 2600), self::ROXO_ESCURO,
            negrito: true, alinhamento: 'l');
        $formas[] = $this->texto(5, 'Escola', self::pol(0.8), self::pol(3.15), self::LARGURA - self::pol(1.4), self::pol(0.6),
            [$escola], 2400, self::CINZA, fonte: 'Inter', alinhamento: 'l');
        $formas[] = $this->retangulo(6, 'Divisória', self::pol(0.8), self::pol(3.88), self::LARGURA - self::pol(1.4), self::pol(0.02), 'D9D2E0');

        $linhas = count($equipe);
        $formas[] = $this->texto(7, 'Equipe', self::pol(0.8), self::pol(4.05), self::LARGURA - self::pol(1.4), self::pol(3.1),
            $equipe === [] ? ['—'] : $equipe, $linhas <= 4 ? 3000 : ($linhas <= 6 ? 2600 : 2000), '1C1B1F',
            fonte: 'Inter', alinhamento: 'l', ancora: 't');
        $formas[] = $this->retangulo(8, 'Faixa', 0, self::ALTURA - self::pol(0.14), self::LARGURA, self::pol(0.14), self::VERDE);

        $this->slides[] = ['xml' => $this->slide('FFFFFF', $formas), 'imagem' => null];
    }

    /** Grava o .pptx em `$destino`. */
    public function salvar(string $destino): void
    {
        if ($this->slides === []) {
            throw new RuntimeException('Uma apresentação precisa de ao menos um slide.');
        }

        $zip = new ZipArchive;
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível montar a apresentação.');
        }

        foreach ($this->partesFixas() as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
        }

        foreach ($this->slides as $i => $slide) {
            $n = $i + 1;
            $zip->addFromString("ppt/slides/slide{$n}.xml", $slide['xml']);

            $rels = ['<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'];

            if ($slide['imagem'] !== null) {
                $midia = "ppt/media/image{$n}.{$slide['imagem']['extensao']}";
                // JPG e PNG já vêm comprimidos: guardar sem recomprimir poupa
                // tempo e memória com fotos grandes.
                $zip->addFile($slide['imagem']['caminho'], $midia);
                $zip->setCompressionName($midia, ZipArchive::CM_STORE);
                $rels[] = '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image'.$n.'.'.$slide['imagem']['extensao'].'"/>';
            }

            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", $this->relacoes($rels));
        }

        if (! $zip->close()) {
            throw new RuntimeException('Não foi possível gravar a apresentação.');
        }
    }

    // --- Imagem -----------------------------------------------------------------

    /**
     * Dimensões, extensão e orientação de uma imagem que o PowerPoint abre
     * (JPG ou PNG). Null para o resto (WEBP, HEIC) ou arquivo ilegível.
     *
     * @return array{largura: int, altura: int, extensao: string, rotacao: int, quarto: bool}|null
     */
    public static function imagem(string $caminho): ?array
    {
        if (! is_file($caminho) || ! is_readable($caminho)) {
            return null;
        }

        $info = @getimagesize($caminho);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        $extensao = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            default => null,
        };

        if ($extensao === null) {
            return null;
        }

        $rotacao = match ($extensao === 'jpeg' ? self::orientacaoExif($caminho) : 1) {
            3 => 180,
            6 => 90,
            8 => 270,
            default => 0,
        };

        return [
            'largura' => (int) $info[0],
            'altura' => (int) $info[1],
            'extensao' => $extensao,
            'rotacao' => $rotacao,
            'quarto' => $rotacao === 90 || $rotacao === 270,
        ];
    }

    /**
     * A orientação EXIF de um JPEG (1 a 8; 1 quando não há), lida à mão — a
     * extensão `exif` nem sempre está instalada. Só o começo do arquivo é lido.
     */
    public static function orientacaoExif(string $caminho): int
    {
        $h = @fopen($caminho, 'rb');
        if ($h === false) {
            return 1;
        }
        $dados = (string) fread($h, 131072);
        fclose($h);

        if (! str_starts_with($dados, "\xFF\xD8")) {
            return 1;
        }

        $pos = 2;
        $tamanho = strlen($dados);

        while ($pos + 4 <= $tamanho && $dados[$pos] === "\xFF") {
            $marcador = ord($dados[$pos + 1]);
            $comprimento = unpack('n', substr($dados, $pos + 2, 2))[1];

            if ($marcador === 0xE1 && substr($dados, $pos + 4, 6) === "Exif\0\0") {
                return self::orientacaoTiff(substr($dados, $pos + 10, $comprimento - 8));
            }

            if ($marcador === 0xDA) {
                break; // começou a imagem: não há EXIF depois disso
            }

            $pos += 2 + $comprimento;
        }

        return 1;
    }

    private static function orientacaoTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $intel = substr($tiff, 0, 2) === 'II';
        $u16 = fn (int $p) => strlen($tiff) >= $p + 2 ? unpack($intel ? 'v' : 'n', substr($tiff, $p, 2))[1] : 0;
        $u32 = fn (int $p) => strlen($tiff) >= $p + 4 ? unpack($intel ? 'V' : 'N', substr($tiff, $p, 4))[1] : 0;

        $ifd = $u32(4);
        $entradas = $u16($ifd);

        for ($i = 0; $i < $entradas; $i++) {
            $e = $ifd + 2 + $i * 12;
            if ($u16($e) === 0x0112) {
                $valor = $u16($e + 8);

                return $valor >= 1 && $valor <= 8 ? $valor : 1;
            }
        }

        return 1;
    }

    // --- XML ----------------------------------------------------------------------

    /** @param  list<string>  $formas */
    private function slide(string $fundo, array $formas, bool $comImagem = false): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sld '.self::NS.'><p:cSld>'
            .'<p:bg><p:bgPr><a:solidFill><a:srgbClr val="'.$fundo.'"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'
            .'<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .implode('', $formas)
            .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    /**
     * Uma caixa de texto, um parágrafo por linha.
     *
     * @param  list<string>  $linhas
     */
    private function texto(
        int $id,
        string $nome,
        int $x,
        int $y,
        int $largura,
        int $altura,
        array $linhas,
        int $tamanho,
        string $cor,
        bool $negrito = false,
        string $fonte = 'Space Grotesk',
        string $alinhamento = 'ctr',
        string $ancora = 'ctr',
    ): string {
        $paragrafos = array_map(fn (string $linha) => '<a:p><a:pPr algn="'.$alinhamento.'"/>'
            .'<a:r><a:rPr lang="pt-BR" sz="'.$tamanho.'"'.($negrito ? ' b="1"' : '').' dirty="0">'
            .'<a:solidFill><a:srgbClr val="'.$cor.'"/></a:solidFill><a:latin typeface="'.self::esc($fonte).'"/></a:rPr>'
            .'<a:t>'.self::esc($linha).'</a:t></a:r></a:p>', $linhas);

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.self::esc($nome).'"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr>'.$this->posicao($x, $y, $largura, $altura).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            .'<p:txBody><a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" anchor="'.$ancora.'"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
            .implode('', $paragrafos)
            .'</p:txBody></p:sp>';
    }

    private function retangulo(int $id, string $nome, int $x, int $y, int $largura, int $altura, string $cor): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.self::esc($nome).'"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr>'.$this->posicao($x, $y, $largura, $altura).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
            .'<a:solidFill><a:srgbClr val="'.$cor.'"/></a:solidFill><a:ln><a:noFill/></a:ln></p:spPr></p:sp>';
    }

    private function figura(int $id, int $x, int $y, int $largura, int $altura, int $rotacao): string
    {
        return '<p:pic><p:nvPicPr><p:cNvPr id="'.$id.'" name="Fotografia do estande"/>'
            .'<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            .'<p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            .'<p:spPr>'.$this->posicao($x, $y, $largura, $altura, $rotacao).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    private function posicao(int $x, int $y, int $largura, int $altura, int $rotacao = 0): string
    {
        return '<a:xfrm'.($rotacao !== 0 ? ' rot="'.($rotacao * 60000).'"' : '').'>'
            .'<a:off x="'.$x.'" y="'.$y.'"/><a:ext cx="'.max(1, $largura).'" cy="'.max(1, $altura).'"/></a:xfrm>';
    }

    /**
     * O tamanho (em centésimos de ponto) pelo comprimento do texto: o primeiro
     * degrau em que ele cabe, ou o menor.
     *
     * @param  array<int, int>  $degraus  limite de caracteres => tamanho
     */
    private function tamanho(string $texto, array $degraus, int $menor): int
    {
        $comprimento = mb_strlen($texto);

        foreach ($degraus as $limite => $tamanho) {
            if ($comprimento <= $limite) {
                return $tamanho;
            }
        }

        return $menor;
    }

    /** @param  list<string>  $relacoes */
    private function relacoes(array $relacoes): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', $relacoes).'</Relationships>';
    }

    /**
     * Os XMLs fixos do pacote. Cada um existe porque o PowerPoint recusa o
     * arquivo sem ele (o tema, inclusive, precisa das três listas de estilo).
     *
     * @return array<string, string>
     */
    private function partesFixas(): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $total = count($this->slides);
        $tipo = 'application/vnd.openxmlformats-officedocument.presentationml';

        $slidesTipos = '';
        $slidesIds = '';
        $slidesRels = '';
        for ($n = 1; $n <= $total; $n++) {
            $slidesTipos .= '<Override PartName="/ppt/slides/slide'.$n.'.xml" ContentType="'.$tipo.'.slide+xml"/>';
            $slidesIds .= '<p:sldId id="'.(255 + $n).'" r:id="rId'.(10 + $n).'"/>';
            $slidesRels .= '<Relationship Id="rId'.(10 + $n).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$n.'.xml"/>';
        }

        $agora = gmdate('Y-m-d\TH:i:s\Z');
        $vazio = '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree>';
        $nivel = fn (string $fonte, int $tamanho) => '<a:lvl1pPr><a:defRPr sz="'.$tamanho.'"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill>'
            .'<a:latin typeface="'.$fonte.'"/></a:defRPr></a:lvl1pPr>';
        $tres = fn (string $item) => str_repeat($item, 3);

        return [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Default Extension="jpeg" ContentType="image/jpeg"/>'
                .'<Default Extension="png" ContentType="image/png"/>'
                .'<Override PartName="/ppt/presentation.xml" ContentType="'.$tipo.'.presentation.main+xml"/>'
                .'<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="'.$tipo.'.slideMaster+xml"/>'
                .'<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="'.$tipo.'.slideLayout+xml"/>'
                .'<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
                .'<Override PartName="/ppt/presProps.xml" ContentType="'.$tipo.'.presProps+xml"/>'
                .'<Override PartName="/ppt/viewProps.xml" ContentType="'.$tipo.'.viewProps+xml"/>'
                .'<Override PartName="/ppt/tableStyles.xml" ContentType="'.$tipo.'.tableStyles+xml"/>'
                .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
                .$slidesTipos.'</Types>',

            '_rels/.rels' => $this->relacoes([
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>',
                '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>',
                '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>',
            ]),

            'docProps/core.xml' => $xml.'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
                .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
                .'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                .'<dc:title>'.self::esc($this->tituloDocumento).'</dc:title><dc:creator>Portal FETECMS</dc:creator>'
                .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$agora.'</dcterms:created>'
                .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$agora.'</dcterms:modified></cp:coreProperties>',

            'docProps/app.xml' => $xml.'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
                .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
                .'<Application>Portal FETECMS</Application><Slides>'.$total.'</Slides></Properties>',

            'ppt/presentation.xml' => $xml.'<p:presentation '.self::NS.' saveSubsetFonts="1">'
                .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
                .'<p:sldIdLst>'.$slidesIds.'</p:sldIdLst>'
                .'<p:sldSz cx="'.self::LARGURA.'" cy="'.self::ALTURA.'"/><p:notesSz cx="6858000" cy="9144000"/>'
                .'</p:presentation>',

            'ppt/_rels/presentation.xml.rels' => $this->relacoes([
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>',
                '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>',
                '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>',
                '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/viewProps" Target="viewProps.xml"/>',
                '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/>',
                $slidesRels,
            ]),

            'ppt/presProps.xml' => $xml.'<p:presentationPr '.self::NS.'/>',
            'ppt/viewProps.xml' => $xml.'<p:viewPr '.self::NS.'><p:gridSpacing cx="76200" cy="76200"/></p:viewPr>',
            'ppt/tableStyles.xml' => $xml.'<a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>',

            'ppt/slideMasters/slideMaster1.xml' => $xml.'<p:sldMaster '.self::NS.'>'
                .'<p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>'.$vazio.'</p:cSld>'
                .'<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" '
                .'accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
                .'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
                .'<p:txStyles><p:titleStyle>'.$nivel('+mj-lt', 4400).'</p:titleStyle>'
                .'<p:bodyStyle>'.$nivel('+mn-lt', 2400).'</p:bodyStyle>'
                .'<p:otherStyle>'.$nivel('+mn-lt', 1800).'</p:otherStyle></p:txStyles></p:sldMaster>',

            'ppt/slideMasters/_rels/slideMaster1.xml.rels' => $this->relacoes([
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>',
                '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>',
            ]),

            'ppt/slideLayouts/slideLayout1.xml' => $xml.'<p:sldLayout '.self::NS.' type="blank" preserve="1">'
                .'<p:cSld name="Em branco">'.$vazio.'</p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>',

            'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => $this->relacoes([
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>',
            ]),

            'ppt/theme/theme1.xml' => $xml.'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="FETECMS">'
                .'<a:themeElements><a:clrScheme name="FETECMS">'
                .'<a:dk1><a:srgbClr val="1C1B1F"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
                .'<a:dk2><a:srgbClr val="'.self::ROXO_ESCURO.'"/></a:dk2><a:lt2><a:srgbClr val="F3EDF7"/></a:lt2>'
                .'<a:accent1><a:srgbClr val="'.self::ROXO.'"/></a:accent1><a:accent2><a:srgbClr val="'.self::VERDE.'"/></a:accent2>'
                .'<a:accent3><a:srgbClr val="006E1F"/></a:accent3><a:accent4><a:srgbClr val="BA1A1A"/></a:accent4>'
                .'<a:accent5><a:srgbClr val="7D5260"/></a:accent5><a:accent6><a:srgbClr val="625B71"/></a:accent6>'
                .'<a:hlink><a:srgbClr val="'.self::ROXO.'"/></a:hlink><a:folHlink><a:srgbClr val="'.self::ROXO_ESCURO.'"/></a:folHlink>'
                .'</a:clrScheme>'
                .'<a:fontScheme name="FETECMS"><a:majorFont><a:latin typeface="Space Grotesk"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
                .'<a:minorFont><a:latin typeface="Inter"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
                .'<a:fmtScheme name="FETECMS">'
                .'<a:fillStyleLst>'.$tres('<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>').'</a:fillStyleLst>'
                .'<a:lnStyleLst>'.$tres('<a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>').'</a:lnStyleLst>'
                .'<a:effectStyleLst>'.$tres('<a:effectStyle><a:effectLst/></a:effectStyle>').'</a:effectStyleLst>'
                .'<a:bgFillStyleLst>'.$tres('<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>').'</a:bgFillStyleLst>'
                .'</a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>',
        ];
    }

    private static function pol(float $polegadas): int
    {
        return (int) round($polegadas * self::POL);
    }

    /** Texto seguro para XML: escapado e sem caracteres de controle. */
    private static function esc(string $texto): string
    {
        $limpo = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto);

        return htmlspecialchars($limpo, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
