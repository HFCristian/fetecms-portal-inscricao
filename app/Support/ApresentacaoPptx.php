<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Escritor mínimo de apresentação .pptx (Office Open XML) — Sprints 254 e 257.
 *
 * Mesmo argumento do {@see PlanilhaXlsx}: o que o portal precisa é uma
 * sequência de slides com texto e uma foto, e um .pptx é um zip com meia dúzia
 * de XMLs. A biblioteca (PhpPresentation) resolveria tabela, gráfico, animação
 * e leitura, nada disso usado aqui.
 *
 * O visual é o do **modelo da organização** (Canva, "FETEC FUNDECT", Sprint
 * 257), medido no PDF dele (1440 × 810 pt) e convertido para o slide 16:9:
 *
 * - {@see abertura()}: o fundo pastel e uma frase no alto — "3° LUGAR - FETEC
 *   FUNDECT" —, que abre e fecha cada bloco;
 * - {@see premiado()}: o fundo, a logo à esquerda, o enfeite e o cabeçalho
 *   "FETECMS / ano" (os três no layout, iguais em todo slide), a colocação ou o
 *   tipo à esquerda e a área ou a credencial ao lado, o projeto embaixo com a
 *   escola e a equipe, e a foto do estande à direita.
 *
 * As imagens fixas (fundo, logo e enfeite) foram extraídas do PDF do modelo e
 * moram em `resources/slides`. As fontes são as do modelo — HandelGothic BT e
 * Globotipo Texto — pelo nome: onde estiverem instaladas o slide sai igual ao
 * modelo; onde não, o PowerPoint troca por outra.
 *
 * A foto entra **sem recompressão** e no tamanho original — o servidor pode não
 * ter GD para reduzi-la —, e a orientação gravada pela câmera (EXIF) vira
 * rotação do quadro, porque o PowerPoint mostra os pixels como estão.
 */
class ApresentacaoPptx
{
    /** 16:9 em EMU. */
    public const LARGURA = 12192000;

    public const ALTURA = 6858000;

    /** As medidas vêm do PDF do modelo, que tem 1440 pt de largura. */
    private const MODELO = 1440;

    private const ROXO = '8608A6';

    private const VINHO = '400046';

    private const LAVANDA = 'F3EDF7';

    public const FONTE_TITULO = 'HandelGothic BT';

    public const FONTE_TEXTO = 'Globotipo Texto';

    public const FONTE_DESTAQUE = 'Globotipo Texto Black';

    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        .'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var list<array{xml: string, layout: int, imagem: ?array{caminho: string, extensao: string}}> */
    private array $slides = [];

    public function __construct(
        private readonly string $tituloDocumento = 'Premiação',
        private readonly string $ano = '',
    ) {}

    public function total(): int
    {
        return count($this->slides);
    }

    /** Onde ficam o fundo, a logo e o enfeite do modelo. */
    public static function pastaDoModelo(): string
    {
        return dirname(__DIR__, 2).'/resources/slides';
    }

    /**
     * O slide que abre (e fecha) um bloco: só o fundo e a frase no alto, em
     * HandelGothic roxa — "3° LUGAR - FETEC FUNDECT", "MOSTRATEC 2027 -
     * CREDENCIAL". Frase comprida diminui e quebra em duas linhas.
     */
    public function abertura(string $texto): void
    {
        $tamanho = $this->caber($texto, 1280, 53, 30, 0.6);

        $this->slides[] = [
            'xml' => $this->slide([
                $this->texto(2, 'Abertura', 80, 60, 1280, 140, [$texto], $tamanho, self::ROXO, self::FONTE_TITULO),
            ]),
            'layout' => 1,
            'imagem' => null,
        ];
    }

    /**
     * O slide de um premiado, no desenho da página com foto do modelo.
     *
     * @param  string  $rotulo  à esquerda, em Globotipo Black: "3° LUGAR", "CREDENCIAL"
     * @param  string  $destaque  ao lado: a área ou o nome da credencial
     * @param  string  $projeto  "CÓDIGO - Título", como na lista
     * @param  list<string>  $detalhes  escola e equipe, uma informação por linha
     */
    public function premiado(
        string $rotulo,
        string $destaque,
        string $projeto,
        array $detalhes,
        ?string $foto,
        string $semFoto = 'Sem fotografia registrada',
    ): void {
        $formas = [
            $this->texto(2, 'Colocação', 83, 370, 262, 100, [$rotulo], $this->caber($rotulo, 257, 53, 24, 0.58),
                self::ROXO, self::FONTE_DESTAQUE, alinhamento: 'l'),
            $this->texto(3, 'Área', 350, 360, 585, 120, [$destaque], $this->caber($destaque, 580, 53, 30, 0.56),
                self::ROXO, self::FONTE_TEXTO, negrito: true),
            $this->texto(4, 'Projeto', 83, 478, 852, 128, [$projeto],
                $this->degrau($projeto, [45 => 41, 90 => 34, 140 => 28], 24), self::VINHO, self::FONTE_TEXTO, negrito: true),
        ];

        $longos = array_sum(array_map(fn (string $l) => (int) ceil(mb_strlen($l) / 70), $detalhes));
        $formas[] = $this->texto(5, 'Escola e equipe', 83, 612, 852, 180, $detalhes === [] ? ['—'] : $detalhes,
            $longos <= 4 ? 24 : ($longos <= 6 ? 21 : 18), self::VINHO, self::FONTE_TEXTO, ancora: 't');

        // A foto ocupa a coluna da direita, inteira e sem cortar.
        $caixa = ['x' => 945, 'y' => 125, 'l' => 455, 'a' => 590];
        $imagem = $foto === null ? null : self::imagem($foto);

        if ($imagem === null) {
            $formas[] = $this->retangulo(6, 'Sem foto', $caixa['x'], $caixa['y'], $caixa['l'], $caixa['a'], self::LAVANDA, self::ROXO);
            $formas[] = $this->texto(7, 'Aviso', $caixa['x'] + 30, $caixa['y'], $caixa['l'] - 60, $caixa['a'],
                [$semFoto], 28, self::VINHO, self::FONTE_TEXTO);
        } else {
            // Com rotação de 90° o quadro gira em volta do centro: a caixa
            // visível é a da imagem já de pé, e o quadro guarda a de antes.
            [$largura, $altura] = $imagem['quarto'] ? [$imagem['altura'], $imagem['largura']] : [$imagem['largura'], $imagem['altura']];
            $escala = min($caixa['l'] / $largura, $caixa['a'] / $altura);
            $vl = $largura * $escala;
            $va = $altura * $escala;
            [$ql, $qa] = $imagem['quarto'] ? [$va, $vl] : [$vl, $va];
            $cx = $caixa['x'] + $caixa['l'] / 2;
            $cy = $caixa['y'] + $caixa['a'] / 2;

            $formas[] = $this->figura(6, 'Fotografia do estande', 'rId2', $cx - $ql / 2, $cy - $qa / 2, $ql, $qa, $imagem['rotacao']);
        }

        $this->slides[] = [
            'xml' => $this->slide($formas),
            'layout' => 2,
            'imagem' => $imagem === null ? null : ['caminho' => $foto, 'extensao' => $imagem['extensao']],
        ];
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

        // As imagens do modelo entram uma vez e servem a todos os slides pelo layout.
        $pasta = self::pastaDoModelo();
        foreach (['fundo.jpg' => 'ppt/media/modelo-fundo.jpeg', 'logo.png' => 'ppt/media/modelo-logo.png', 'enfeite.png' => 'ppt/media/modelo-enfeite.png'] as $origem => $midia) {
            $zip->addFile($pasta.'/'.$origem, $midia);
            $zip->setCompressionName($midia, ZipArchive::CM_STORE);
        }

        foreach ($this->slides as $i => $slide) {
            $n = $i + 1;
            $zip->addFromString("ppt/slides/slide{$n}.xml", $slide['xml']);

            $rels = ['<Relationship Id="rId1" Type="'.self::REL.'/slideLayout" Target="../slideLayouts/slideLayout'.$slide['layout'].'.xml"/>'];

            if ($slide['imagem'] !== null) {
                $midia = "ppt/media/image{$n}.{$slide['imagem']['extensao']}";
                // JPG e PNG já vêm comprimidos: guardar sem recomprimir poupa
                // tempo e memória com fotos grandes.
                $zip->addFile($slide['imagem']['caminho'], $midia);
                $zip->setCompressionName($midia, ZipArchive::CM_STORE);
                $rels[] = '<Relationship Id="rId2" Type="'.self::REL.'/image" Target="../media/image'.$n.'.'.$slide['imagem']['extensao'].'"/>';
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
    private function slide(array $formas): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sld '.self::NS.'><p:cSld>'.$this->arvore($formas).'</p:cSld>'
            .'<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    /** @param  list<string>  $formas */
    private function arvore(array $formas): string
    {
        return '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            .implode('', $formas).'</p:spTree>';
    }

    /**
     * Uma caixa de texto, um parágrafo por linha. Posição em pontos do modelo
     * e tamanho da fonte em pontos do modelo (convertidos aqui).
     *
     * @param  list<string>  $linhas
     */
    private function texto(
        int $id,
        string $nome,
        float $x,
        float $y,
        float $largura,
        float $altura,
        array $linhas,
        float $tamanho,
        string $cor,
        string $fonte,
        bool $negrito = false,
        string $alinhamento = 'ctr',
        string $ancora = 'ctr',
    ): string {
        $sz = (int) round($tamanho * 960 / self::MODELO) * 100;
        $paragrafos = array_map(fn (string $linha) => '<a:p><a:pPr algn="'.$alinhamento.'"/>'
            .'<a:r><a:rPr lang="pt-BR" sz="'.$sz.'"'.($negrito ? ' b="1"' : '').' dirty="0">'
            .'<a:solidFill><a:srgbClr val="'.$cor.'"/></a:solidFill>'
            .'<a:latin typeface="'.self::esc($fonte).'"/><a:cs typeface="'.self::esc($fonte).'"/></a:rPr>'
            .'<a:t>'.self::esc($linha).'</a:t></a:r></a:p>', $linhas);

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.self::esc($nome).'"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr>'.$this->posicao($x, $y, $largura, $altura).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            .'<p:txBody><a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" anchor="'.$ancora.'"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
            .implode('', $paragrafos)
            .'</p:txBody></p:sp>';
    }

    private function retangulo(int $id, string $nome, float $x, float $y, float $largura, float $altura, string $cor, ?string $borda = null): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.self::esc($nome).'"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            .'<p:spPr>'.$this->posicao($x, $y, $largura, $altura).'<a:prstGeom prst="roundRect"><a:avLst/></a:prstGeom>'
            .'<a:solidFill><a:srgbClr val="'.$cor.'"/></a:solidFill>'
            .($borda === null ? '<a:ln><a:noFill/></a:ln>' : '<a:ln w="19050"><a:solidFill><a:srgbClr val="'.$borda.'"/></a:solidFill><a:prstDash val="dash"/></a:ln>')
            .'</p:spPr></p:sp>';
    }

    private function figura(int $id, string $nome, string $relacao, float $x, float $y, float $largura, float $altura, int $rotacao = 0): string
    {
        return '<p:pic><p:nvPicPr><p:cNvPr id="'.$id.'" name="'.self::esc($nome).'"/>'
            .'<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            .'<p:blipFill><a:blip r:embed="'.$relacao.'"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            .'<p:spPr>'.$this->posicao($x, $y, $largura, $altura, $rotacao).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    /** O quadro em EMU, a partir de pontos do modelo. */
    private function posicao(float $x, float $y, float $largura, float $altura, int $rotacao = 0): string
    {
        return '<a:xfrm'.($rotacao !== 0 ? ' rot="'.($rotacao * 60000).'"' : '').'>'
            .'<a:off x="'.self::emu($x).'" y="'.self::emu($y).'"/>'
            .'<a:ext cx="'.max(1, self::emu($largura)).'" cy="'.max(1, self::emu($altura)).'"/></a:xfrm>';
    }

    /**
     * O maior tamanho (até `$maximo`) em que o texto cabe numa linha de
     * `$largura`, pela largura média da letra na fonte (`$fator` × tamanho);
     * abaixo de `$minimo` ele quebra em mais linhas em vez de encolher.
     */
    private function caber(string $texto, float $largura, float $maximo, float $minimo, float $fator): float
    {
        $comprimento = max(1, mb_strlen($texto));

        return max($minimo, min($maximo, floor($largura / ($fator * $comprimento))));
    }

    /**
     * O tamanho pelo comprimento do texto: o primeiro degrau em que ele cabe,
     * ou o menor.
     *
     * @param  array<int, float>  $degraus  limite de caracteres => tamanho
     */
    private function degrau(string $texto, array $degraus, float $menor): float
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
     * O fundo mora no mestre, e a logo, o enfeite e o cabeçalho no layout
     * "Premiado": no PowerPoint eles ficam fixos, fora do caminho de quem
     * ajusta um slide.
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
            $slidesRels .= '<Relationship Id="rId'.(10 + $n).'" Type="'.self::REL.'/slide" Target="slides/slide'.$n.'.xml"/>';
        }

        $agora = gmdate('Y-m-d\TH:i:s\Z');
        $nivel = fn (string $fonte, int $tamanho) => '<a:lvl1pPr><a:defRPr sz="'.$tamanho.'"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill>'
            .'<a:latin typeface="'.$fonte.'"/></a:defRPr></a:lvl1pPr>';
        $tres = fn (string $item) => str_repeat($item, 3);
        $layout = fn (string $nome, array $formas) => $xml.'<p:sldLayout '.self::NS.' type="blank" preserve="1">'
            .'<p:cSld name="'.$nome.'">'.$this->arvore($formas).'</p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
        $relLayout = fn (array $extras = []) => $this->relacoes([
            '<Relationship Id="rId1" Type="'.self::REL.'/slideMaster" Target="../slideMasters/slideMaster1.xml"/>',
            ...$extras,
        ]);

        return [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Default Extension="jpeg" ContentType="image/jpeg"/>'
                .'<Default Extension="png" ContentType="image/png"/>'
                .'<Override PartName="/ppt/presentation.xml" ContentType="'.$tipo.'.presentation.main+xml"/>'
                .'<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="'.$tipo.'.slideMaster+xml"/>'
                .'<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="'.$tipo.'.slideLayout+xml"/>'
                .'<Override PartName="/ppt/slideLayouts/slideLayout2.xml" ContentType="'.$tipo.'.slideLayout+xml"/>'
                .'<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
                .'<Override PartName="/ppt/presProps.xml" ContentType="'.$tipo.'.presProps+xml"/>'
                .'<Override PartName="/ppt/viewProps.xml" ContentType="'.$tipo.'.viewProps+xml"/>'
                .'<Override PartName="/ppt/tableStyles.xml" ContentType="'.$tipo.'.tableStyles+xml"/>'
                .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
                .$slidesTipos.'</Types>',

            '_rels/.rels' => $this->relacoes([
                '<Relationship Id="rId1" Type="'.self::REL.'/officeDocument" Target="ppt/presentation.xml"/>',
                '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>',
                '<Relationship Id="rId3" Type="'.self::REL.'/extended-properties" Target="docProps/app.xml"/>',
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
                '<Relationship Id="rId1" Type="'.self::REL.'/slideMaster" Target="slideMasters/slideMaster1.xml"/>',
                '<Relationship Id="rId2" Type="'.self::REL.'/theme" Target="theme/theme1.xml"/>',
                '<Relationship Id="rId3" Type="'.self::REL.'/presProps" Target="presProps.xml"/>',
                '<Relationship Id="rId4" Type="'.self::REL.'/viewProps" Target="viewProps.xml"/>',
                '<Relationship Id="rId5" Type="'.self::REL.'/tableStyles" Target="tableStyles.xml"/>',
                $slidesRels,
            ]),

            'ppt/presProps.xml' => $xml.'<p:presentationPr '.self::NS.'/>',
            'ppt/viewProps.xml' => $xml.'<p:viewPr '.self::NS.'><p:gridSpacing cx="76200" cy="76200"/></p:viewPr>',
            'ppt/tableStyles.xml' => $xml.'<a:tblStyleLst xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>',

            // O fundo pastel do modelo, esticado no slide inteiro.
            'ppt/slideMasters/slideMaster1.xml' => $xml.'<p:sldMaster '.self::NS.'>'
                .'<p:cSld><p:bg><p:bgPr><a:blipFill dpi="0" rotWithShape="1"><a:blip r:embed="rId3"/><a:srcRect/><a:stretch><a:fillRect/></a:stretch></a:blipFill>'
                .'<a:effectLst/></p:bgPr></p:bg>'.$this->arvore([]).'</p:cSld>'
                .'<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" '
                .'accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
                .'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/><p:sldLayoutId id="2147483650" r:id="rId4"/></p:sldLayoutIdLst>'
                .'<p:txStyles><p:titleStyle>'.$nivel('+mj-lt', 4400).'</p:titleStyle>'
                .'<p:bodyStyle>'.$nivel('+mn-lt', 2400).'</p:bodyStyle>'
                .'<p:otherStyle>'.$nivel('+mn-lt', 1800).'</p:otherStyle></p:txStyles></p:sldMaster>',

            'ppt/slideMasters/_rels/slideMaster1.xml.rels' => $this->relacoes([
                '<Relationship Id="rId1" Type="'.self::REL.'/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>',
                '<Relationship Id="rId2" Type="'.self::REL.'/theme" Target="../theme/theme1.xml"/>',
                '<Relationship Id="rId3" Type="'.self::REL.'/image" Target="../media/modelo-fundo.jpeg"/>',
                '<Relationship Id="rId4" Type="'.self::REL.'/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>',
            ]),

            'ppt/slideLayouts/slideLayout1.xml' => $layout('Abertura', []),
            'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => $relLayout(),

            // A página com foto do modelo: logo à esquerda, enfeite ao centro e
            // o cabeçalho FETECMS / ano entre eles.
            'ppt/slideLayouts/slideLayout2.xml' => $layout('Premiado', [
                $this->figura(2, 'Logo', 'rId2', 45, 87, 261, 296),
                $this->figura(3, 'Enfeite', 'rId3', 715, 81, 243, 237),
                $this->texto(4, 'FETECMS', 320, 118, 420, 90, ['FETECMS'], 69, self::VINHO, self::FONTE_TITULO),
                $this->texto(5, 'Ano', 320, 178, 420, 72, [$this->ano], 54, self::VINHO, self::FONTE_TITULO),
            ]),
            'ppt/slideLayouts/_rels/slideLayout2.xml.rels' => $relLayout([
                '<Relationship Id="rId2" Type="'.self::REL.'/image" Target="../media/modelo-logo.png"/>',
                '<Relationship Id="rId3" Type="'.self::REL.'/image" Target="../media/modelo-enfeite.png"/>',
            ]),

            'ppt/theme/theme1.xml' => $xml.'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="FETECMS">'
                .'<a:themeElements><a:clrScheme name="FETECMS">'
                .'<a:dk1><a:srgbClr val="'.self::VINHO.'"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
                .'<a:dk2><a:srgbClr val="2A0058"/></a:dk2><a:lt2><a:srgbClr val="'.self::LAVANDA.'"/></a:lt2>'
                .'<a:accent1><a:srgbClr val="'.self::ROXO.'"/></a:accent1><a:accent2><a:srgbClr val="43157A"/></a:accent2>'
                .'<a:accent3><a:srgbClr val="007B24"/></a:accent3><a:accent4><a:srgbClr val="BA1A1A"/></a:accent4>'
                .'<a:accent5><a:srgbClr val="7D5260"/></a:accent5><a:accent6><a:srgbClr val="625B71"/></a:accent6>'
                .'<a:hlink><a:srgbClr val="'.self::ROXO.'"/></a:hlink><a:folHlink><a:srgbClr val="'.self::VINHO.'"/></a:folHlink>'
                .'</a:clrScheme>'
                .'<a:fontScheme name="FETECMS"><a:majorFont><a:latin typeface="'.self::FONTE_TITULO.'"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
                .'<a:minorFont><a:latin typeface="'.self::FONTE_TEXTO.'"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
                .'<a:fmtScheme name="FETECMS">'
                .'<a:fillStyleLst>'.$tres('<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>').'</a:fillStyleLst>'
                .'<a:lnStyleLst>'.$tres('<a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>').'</a:lnStyleLst>'
                .'<a:effectStyleLst>'.$tres('<a:effectStyle><a:effectLst/></a:effectStyle>').'</a:effectStyleLst>'
                .'<a:bgFillStyleLst>'.$tres('<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>').'</a:bgFillStyleLst>'
                .'</a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>',
        ];
    }

    /** Pontos do modelo (1440 de largura) para EMU do slide. */
    private static function emu(float $pontos): int
    {
        return (int) round($pontos * self::LARGURA / self::MODELO);
    }

    /** Texto seguro para XML: escapado e sem caracteres de controle. */
    private static function esc(string $texto): string
    {
        $limpo = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto);

        return htmlspecialchars($limpo, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
