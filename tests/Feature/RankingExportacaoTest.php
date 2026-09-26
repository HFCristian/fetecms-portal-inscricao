<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Rubrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Ranking dos projetos → **baixar** (Sprint 153).
 *
 * O ranking já existia na tela; o que se testa aqui é o arquivo: que os quatro
 * formatos saem, que todos respeitam o **recorte dos filtros** (baixar o
 * ranking inteiro quando a tela mostra uma área só seria entregar outra coisa
 * do que se está vendo) e que o XLSX grava a média como **número**, que é a
 * razão de ele existir ao lado do CSV.
 */
class RankingExportacaoTest extends TestCase
{
    use RefreshDatabase;

    private Area $exatas;

    private Area $bio;

    private User $orientador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exatas = Area::create(['nome' => 'Exatas']);
        $this->bio = Area::create(['nome' => 'Biológicas']);
        $this->orientador = User::factory()->create();
    }

    private function projetoAvaliado(string $titulo, Area $area, int $ponto, string $categoria = 'fetecms'): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $this->orientador->id,
            'titulo' => $titulo,
            'area_id' => $area->id,
            'categoria' => $categoria,
        ]);

        $avaliador = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'area_id' => $area->id]);

        $respostas = [];
        foreach (Rubrica::perguntas() as $pergunta) {
            $respostas[$pergunta['chave']] = $pergunta['tipo'] === Rubrica::TIPO_SIM_NAO ? true : $ponto;
        }

        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id, 'status' => 'concluida',
            'respostas' => $respostas, 'nota' => Rubrica::nota($respostas), 'concluida_em' => now(),
        ]);

        return $projeto;
    }

    private function comoAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_csv_traz_o_ranking_com_bom_e_ponto_e_virgula(): void
    {
        $this->projetoAvaliado('Purificação de água', $this->exatas, 10);
        $this->projetoAvaliado('Abelhas nativas', $this->bio, 6);
        $this->comoAdmin();

        $resposta = $this->get('/api/v1/admin/avaliacao/ranking/exportar/csv')->assertOk();
        $csv = $resposta->getContent();

        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('Posição;Projeto;Área;Categoria;Média;Avaliações;Situação', $csv);
        $this->assertStringContainsString('Purificação de água', $csv);
        $this->assertStringContainsString('Abelhas nativas', $csv);
        // A média sai em pt-BR no CSV — é texto, e quem lê é gente.
        $this->assertMatchesRegularExpression('/;10,00;/', $csv);
    }

    public function test_txt_e_pdf_tambem_saem(): void
    {
        $this->projetoAvaliado('Purificação de água', $this->exatas, 10);
        $this->comoAdmin();

        $txt = $this->get('/api/v1/admin/avaliacao/ranking/exportar/txt')->assertOk();
        $this->assertStringContainsString('RANKING DOS PROJETOS', $txt->getContent());
        $this->assertStringContainsString('Purificação de água', $txt->getContent());

        $pdf = $this->get('/api/v1/admin/avaliacao/ranking/exportar/pdf')->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_xlsx_e_um_zip_valido_com_a_media_como_numero(): void
    {
        $this->projetoAvaliado('Purificação de água', $this->exatas, 10);
        $this->comoAdmin();

        $resposta = $this->get('/api/v1/admin/avaliacao/ranking/exportar/xlsx')->assertOk();
        $resposta->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $caminho = tempnam(sys_get_temp_dir(), 'teste-xlsx');
        file_put_contents($caminho, $resposta->getContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho) === true, 'O .xlsx precisa ser um zip válido.');

        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $parte) {
            $this->assertNotFalse($zip->locateName($parte), "Faltou a parte {$parte} no pacote.");
        }

        $planilha = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($caminho);

        $this->assertStringContainsString('Purificação de água', $planilha);
        // É isto que o CSV não consegue garantir: a média chega como número
        // (`<v>10</v>`), somável e ordenável, e não como o texto "10,00".
        $this->assertStringContainsString('<v>10</v>', $planilha);
        $this->assertStringNotContainsString('10,00', $planilha);
    }

    public function test_o_arquivo_respeita_o_filtro_da_tela(): void
    {
        $this->projetoAvaliado('Purificação de água', $this->exatas, 10);
        $this->projetoAvaliado('Abelhas nativas', $this->bio, 6);
        $this->comoAdmin();

        $csv = $this->get('/api/v1/admin/avaliacao/ranking/exportar/csv?area_id='.$this->bio->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('Abelhas nativas', $csv);
        $this->assertStringNotContainsString('Purificação de água', $csv);
    }

    public function test_projeto_ainda_sem_o_minimo_de_avaliacoes_sai_marcado_como_parcial(): void
    {
        $this->projetoAvaliado('Purificação de água', $this->exatas, 10);
        $this->comoAdmin();

        // Uma avaliação só: abaixo do mínimo da categoria, a média é parcial e
        // a posição pode mudar — o arquivo precisa dizer isso.
        $this->assertStringContainsString(
            'Parcial',
            $this->get('/api/v1/admin/avaliacao/ranking/exportar/csv')->assertOk()->getContent(),
        );
    }

    public function test_formato_desconhecido_nao_tem_rota(): void
    {
        $this->comoAdmin();

        $this->get('/api/v1/admin/avaliacao/ranking/exportar/docx')->assertNotFound();
    }

    public function test_orientador_nao_baixa_o_ranking(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->get('/api/v1/admin/avaliacao/ranking/exportar/csv')->assertForbidden();
    }
}
