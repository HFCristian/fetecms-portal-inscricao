<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\StatusPresenca;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Sprint 250 — o ranking presencial em arquivo: CSV, XLSX, PDF e TXT, no
 * recorte dos filtros da tela, como o ranking online (Sprint 153).
 */
class RankingPresencialExportacaoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    private Area $agr;

    private Area $bio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->bio = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function finalista(string $titulo, Area $area, int $estande, ?string $codigo = null): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'area_id' => $area->id, 'categoria' => 'fetecms',
        ]);
        $this->lista->projetos()->attach($projeto->id, ['codigo' => $codigo]);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => 'A', 'numero' => $estande]);

        return $projeto;
    }

    private function avaliacao(Projeto $projeto, float $nota, string $vaga = 'comum'): void
    {
        $avaliador = User::factory()->create(['role' => Role::Avaliador->value]);
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'presencial' => true, 'comissao_especial' => $vaga === 'comite']);

        AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Concluida, 'nota' => $nota, 'vaga' => $vaga, 'concluida_em' => now(),
        ]);
    }

    private function tresAvaliacoes(Projeto $projeto, float $nota): void
    {
        $this->avaliacao($projeto, $nota);
        $this->avaliacao($projeto, $nota);
        $this->avaliacao($projeto, $nota, 'comite');
    }

    public function test_csv_traz_codigo_estande_media_e_situacao(): void
    {
        $this->tresAvaliacoes($this->finalista('Abelhas nativas', $this->agr, 7, 'FET.AGR-001'), 8.5);
        $this->avaliacao($this->finalista('Bioplástico', $this->bio, 12), 6.25);

        $csv = $this->get('/api/v1/admin/presencial/ranking/exportar/csv')->assertOk()->getContent();

        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString(
            'Posição;Código;Projeto;Área;Categoria;Estande;Turno;Média;"Avaliações comuns";"Avaliações da comissão";Situação',
            $csv,
        );
        $this->assertStringContainsString('1;FET.AGR-001;"Abelhas nativas";"Ciências Agrárias";FETECMS;007;', $csv);
        $this->assertStringContainsString(';8,500;2;1;Completo', $csv);
        // Uma comum de duas, nenhuma da comissão: a posição ainda pode mudar.
        $this->assertStringContainsString(';6,250;1;0;Parcial', $csv);
    }

    public function test_xlsx_grava_a_media_como_numero(): void
    {
        $this->tresAvaliacoes($this->finalista('Abelhas nativas', $this->agr, 7), 8.5);

        $resposta = $this->get('/api/v1/admin/presencial/ranking/exportar/xlsx')->assertOk();
        $resposta->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $caminho = tempnam(sys_get_temp_dir(), 'teste-xlsx');
        file_put_contents($caminho, $resposta->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho) === true);
        $planilha = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($caminho);

        $this->assertStringContainsString('Abelhas nativas', $planilha);
        $this->assertStringContainsString('<v>8.5</v>', $planilha);
        $this->assertStringNotContainsString('8,500', $planilha);
    }

    public function test_txt_e_pdf_saem(): void
    {
        $this->tresAvaliacoes($this->finalista('Abelhas nativas', $this->agr, 7, 'FET.AGR-001'), 9);

        $txt = $this->get('/api/v1/admin/presencial/ranking/exportar/txt')->assertOk()->getContent();
        $this->assertStringContainsString('RANKING PRESENCIAL', $txt);
        $this->assertStringContainsString('FET.AGR-001 - Abelhas nativas', $txt);
        $this->assertStringContainsString('9,000', $txt);

        $pdf = $this->get('/api/v1/admin/presencial/ranking/exportar/pdf')->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_o_arquivo_respeita_o_filtro_da_tela(): void
    {
        $this->avaliacao($this->finalista('Abelhas nativas', $this->agr, 7), 9);
        $this->avaliacao($this->finalista('Bioplástico', $this->bio, 12), 6);

        $csv = $this->get('/api/v1/admin/presencial/ranking/exportar/csv?area_id='.$this->bio->id)->assertOk()->getContent();

        $this->assertStringContainsString('Bioplástico', $csv);
        $this->assertStringNotContainsString('Abelhas nativas', $csv);
    }

    public function test_formato_desconhecido_nao_tem_rota(): void
    {
        $this->get('/api/v1/admin/presencial/ranking/exportar/docx')->assertNotFound();
    }

    public function test_o_voluntario_ve_o_ranking_mas_nao_baixa(): void
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Vera Voluntária', 'email' => 'vera@fetec.test', 'password' => 'senha-do-turno',
            'cpf' => '52998224725', 'curso' => 'Biologia', 'horas' => 5,
        ], null, 'avaliacao_presencial');
        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();
        Sanctum::actingAs($conta->user);

        $this->getJson('/api/v1/admin/presencial/ranking')->assertOk();
        $this->get('/api/v1/admin/presencial/ranking/exportar/csv')->assertForbidden();
    }
}
