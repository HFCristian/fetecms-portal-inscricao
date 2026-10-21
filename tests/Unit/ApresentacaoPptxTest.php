<?php

namespace Tests\Unit;

use App\Services\ListaPresencialService;
use App\Support\ApresentacaoPptx;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Imagens;
use ZipArchive;

/**
 * Sprints 254 e 257 — o escritor de .pptx no modelo da organização: o pacote
 * tem as partes que o PowerPoint exige, as imagens do modelo entram uma vez
 * pelos layouts, cada slide entra na apresentação, a foto vai embutida (só JPG
 * e PNG) e a orientação da câmera vira rotação do quadro.
 */
class ApresentacaoPptxTest extends TestCase
{
    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }

        parent::tearDown();
    }

    private function imagem(string $base64): string
    {
        return $this->temporarios[] = Imagens::arquivo($base64);
    }

    /** @return array<string, string> o conteúdo de cada parte do pacote */
    private function gerar(ApresentacaoPptx $apresentacao): array
    {
        $destino = $this->temporarios[] = (string) tempnam(sys_get_temp_dir(), 'pptx');
        $apresentacao->salvar($destino);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($destino) === true, 'O .pptx precisa ser um zip válido.');
        $partes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = (string) $zip->getNameIndex($i);
            $partes[$nome] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $partes;
    }

    public function test_pacote_no_modelo_com_layouts_imagens_fixas_e_um_slide_por_chamada(): void
    {
        $a = new ApresentacaoPptx('Premiação', '2026');
        $a->abertura('3° LUGAR - FETEC FUNDECT');
        $a->premiado('3° LUGAR', 'CIÊNCIAS AGRÁRIAS', 'PIC.AGR-003 - Abelhas & <Cia>', ['EE Alfa / Campo Grande - MS', 'Ana, Bruno', 'Orientador(a): Paula'], $this->imagem(Imagens::JPEG));

        $partes = $this->gerar($a);

        foreach ([
            '[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml',
            'ppt/presentation.xml', 'ppt/_rels/presentation.xml.rels', 'ppt/presProps.xml', 'ppt/viewProps.xml',
            'ppt/tableStyles.xml', 'ppt/slideMasters/slideMaster1.xml', 'ppt/slideLayouts/slideLayout1.xml',
            'ppt/slideLayouts/slideLayout2.xml', 'ppt/theme/theme1.xml', 'ppt/slides/slide1.xml', 'ppt/slides/slide2.xml',
            'ppt/media/modelo-fundo.jpeg', 'ppt/media/modelo-logo.png', 'ppt/media/modelo-enfeite.png', 'ppt/media/image2.jpeg',
        ] as $parte) {
            $this->assertArrayHasKey($parte, $partes, "Faltou {$parte}.");
        }

        $this->assertSame(array_key_first($partes), '[Content_Types].xml');
        $this->assertSame(2, substr_count($partes['ppt/presentation.xml'], '<p:sldId '));
        $this->assertStringContainsString('<p:sldSz cx="12192000" cy="6858000"/>', $partes['ppt/presentation.xml']);

        // O fundo do modelo no mestre; logo, enfeite e "FETECMS / 2026" no layout do premiado.
        $this->assertStringContainsString('<a:blipFill', $partes['ppt/slideMasters/slideMaster1.xml']);
        $this->assertStringContainsString('modelo-fundo.jpeg', $partes['ppt/slideMasters/_rels/slideMaster1.xml.rels']);
        $this->assertStringContainsString('modelo-logo.png', $partes['ppt/slideLayouts/_rels/slideLayout2.xml.rels']);
        $this->assertStringContainsString('<a:t>2026</a:t>', $partes['ppt/slideLayouts/slideLayout2.xml']);
        $this->assertSame(file_get_contents(ApresentacaoPptx::pastaDoModelo().'/logo.png'), $partes['ppt/media/modelo-logo.png']);

        // A abertura usa o layout só com o fundo; o premiado, o outro.
        $this->assertStringContainsString('slideLayout1.xml', $partes['ppt/slides/_rels/slide1.xml.rels']);
        $this->assertStringContainsString('3° LUGAR - FETEC FUNDECT', $partes['ppt/slides/slide1.xml']);
        $this->assertStringContainsString('typeface="HandelGothic BT"', $partes['ppt/slides/slide1.xml']);
        $this->assertStringContainsString('slideLayout2.xml', $partes['ppt/slides/_rels/slide2.xml.rels']);
        $this->assertStringContainsString('typeface="Globotipo Texto Black"', $partes['ppt/slides/slide2.xml']);
        $this->assertStringContainsString('CIÊNCIAS AGRÁRIAS', $partes['ppt/slides/slide2.xml']);
        // Texto escapado: o & e o < do título não quebram o XML.
        $this->assertStringContainsString('Abelhas &amp; &lt;Cia&gt;', $partes['ppt/slides/slide2.xml']);
        $this->assertStringContainsString('Orientador(a): Paula', $partes['ppt/slides/slide2.xml']);
        $this->assertStringContainsString('../media/image2.jpeg', $partes['ppt/slides/_rels/slide2.xml.rels']);
        $this->assertSame(base64_decode(Imagens::JPEG), $partes['ppt/media/image2.jpeg']);

        foreach ($partes as $nome => $xml) {
            if (str_ends_with($nome, '.xml') || str_ends_with($nome, '.rels')) {
                $this->assertNotFalse(@simplexml_load_string($xml), "{$nome} não é XML bem formado.");
            }
        }
    }

    public function test_orientacao_da_camera_vira_rotacao_do_quadro(): void
    {
        $this->assertSame(6, ApresentacaoPptx::orientacaoExif($this->imagem(Imagens::JPEG_EXIF_6)));
        $this->assertSame(1, ApresentacaoPptx::orientacaoExif($this->imagem(Imagens::JPEG)));

        $a = new ApresentacaoPptx;
        $a->premiado('1° LUGAR', 'CIÊNCIAS HUMANAS', 'Projeto', [], $this->imagem(Imagens::JPEG_EXIF_6));
        $slide = $this->gerar($a)['ppt/slides/slide1.xml'];

        // 90° no quadro; a caixa visível (de pé) é mais alta que larga, então
        // o quadro guardado é o deitado: largura maior que a altura.
        $this->assertStringContainsString('<a:xfrm rot="5400000">', $slide);
        preg_match('/name="Fotografia do estande".*?<a:ext cx="(\d+)" cy="(\d+)"/s', $slide, $m);
        $this->assertGreaterThan((int) $m[2], (int) $m[1]);
    }

    public function test_sem_foto_ou_formato_que_o_powerpoint_nao_abre_vira_aviso(): void
    {
        $this->assertNull(ApresentacaoPptx::imagem($this->imagem(Imagens::WEBP)));
        $this->assertSame('png', ApresentacaoPptx::imagem($this->imagem(Imagens::PNG))['extensao']);

        $a = new ApresentacaoPptx;
        $a->premiado('1° LUGAR', 'CIÊNCIAS HUMANAS', 'Projeto', [], null);
        $a->premiado('1° LUGAR', 'CIÊNCIAS HUMANAS', 'Projeto', [], $this->imagem(Imagens::WEBP), 'Formato não suportado');
        $partes = $this->gerar($a);

        $this->assertStringContainsString('Sem fotografia registrada', $partes['ppt/slides/slide1.xml']);
        $this->assertStringContainsString('Formato não suportado', $partes['ppt/slides/slide2.xml']);
        $this->assertStringNotContainsString('<p:pic>', $partes['ppt/slides/slide2.xml']);
        $this->assertSame([], array_values(array_filter(
            array_keys($partes),
            fn ($n) => str_starts_with($n, 'ppt/media/image'),
        )));
    }

    public function test_ordem_das_areas_do_modelo(): void
    {
        $areas = ['Linguística, Letras e Artes', 'Ciências Sociais Aplicadas', 'Ciências da Saúde', 'Engenharias',
            'Ciências Humanas', 'Ciências Exatas e da Terra', 'Ciências Biológicas', 'Ciências Agrárias'];
        usort($areas, fn ($a, $b) => ListaPresencialService::ordemDaArea($a) <=> ListaPresencialService::ordemDaArea($b));

        $this->assertSame([
            'Ciências Agrárias', 'Ciências Biológicas', 'Ciências Exatas e da Terra', 'Ciências Humanas',
            'Ciências da Saúde', 'Ciências Sociais Aplicadas', 'Engenharias', 'Linguística, Letras e Artes',
        ], $areas);
    }
}
