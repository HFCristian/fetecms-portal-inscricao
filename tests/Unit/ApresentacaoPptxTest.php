<?php

namespace Tests\Unit;

use App\Support\ApresentacaoPptx;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Imagens;
use ZipArchive;

/**
 * Sprint 254 — o escritor de .pptx: o pacote tem as partes que o PowerPoint
 * exige, cada slide entra na apresentação, a foto vai embutida (só JPG e PNG)
 * e a orientação da câmera vira rotação do quadro.
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

    public function test_pacote_com_as_partes_obrigatorias_e_um_slide_por_chamada(): void
    {
        $a = new ApresentacaoPptx('Premiação');
        $a->titulo('Credencial', 'MOSTRATEC & <Cia>', 'Fundação Liberato', 'XVI FETECMS');
        $a->foto($this->imagem(Imagens::JPEG), 'Estande 042 · FET.AGR-001');
        $a->projeto('FET.AGR-001', 'Abelhas', 'EE Alfa / Campo Grande - MS', ['Ana', 'Paula - Orientador(a)']);

        $partes = $this->gerar($a);

        foreach ([
            '[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml',
            'ppt/presentation.xml', 'ppt/_rels/presentation.xml.rels', 'ppt/presProps.xml', 'ppt/viewProps.xml',
            'ppt/tableStyles.xml', 'ppt/slideMasters/slideMaster1.xml', 'ppt/slideLayouts/slideLayout1.xml',
            'ppt/theme/theme1.xml', 'ppt/slides/slide1.xml', 'ppt/slides/slide2.xml', 'ppt/slides/slide3.xml',
            'ppt/media/image2.jpeg',
        ] as $parte) {
            $this->assertArrayHasKey($parte, $partes, "Faltou {$parte}.");
        }

        $this->assertSame(array_key_first($partes), '[Content_Types].xml');
        $this->assertSame(3, substr_count($partes['ppt/presentation.xml'], '<p:sldId '));
        $this->assertStringContainsString('<p:sldSz cx="12192000" cy="6858000"/>', $partes['ppt/presentation.xml']);
        $this->assertStringContainsString('/ppt/slides/slide3.xml', $partes['[Content_Types].xml']);
        // Texto escapado: o & e o < do nome não quebram o XML.
        $this->assertStringContainsString('MOSTRATEC &amp; &lt;Cia&gt;', $partes['ppt/slides/slide1.xml']);
        $this->assertStringContainsString('r:embed="rId2"', $partes['ppt/slides/slide2.xml']);
        $this->assertStringContainsString('../media/image2.jpeg', $partes['ppt/slides/_rels/slide2.xml.rels']);
        $this->assertSame(base64_decode(Imagens::JPEG), $partes['ppt/media/image2.jpeg']);
        $this->assertStringContainsString('Paula - Orientador(a)', $partes['ppt/slides/slide3.xml']);

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
        $a->foto($this->imagem(Imagens::JPEG_EXIF_6), 'De pé');
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
        $a->foto(null, 'Estande 003');
        $a->foto($this->imagem(Imagens::WEBP), 'Estande 009', 'Formato não suportado');
        $partes = $this->gerar($a);

        $this->assertStringContainsString('Sem fotografia registrada', $partes['ppt/slides/slide1.xml']);
        $this->assertStringContainsString('Formato não suportado', $partes['ppt/slides/slide2.xml']);
        $this->assertStringNotContainsString('<p:pic>', $partes['ppt/slides/slide2.xml']);
        $this->assertEmpty(array_filter(array_keys($partes), fn ($n) => str_starts_with($n, 'ppt/media/')));
    }
}
