<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\TipoDocumento;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Services\ExportacaoProjetosPesquisaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Sprint 187 — os arquivos do projeto de pesquisa dos finalistas num ZIP, de
 * todos ou só dos escolhidos, em pastas categoria → área e com o código do
 * projeto no nome.
 */
class ProjetosPesquisaZipTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Area $agrarias;

    private Area $biologicas;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);
        $this->agrarias = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->biologicas = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function projeto(string $titulo, Categoria $categoria, Area $area, ?string $plano = 'PDF'): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'categoria' => $categoria,
            'area_id' => $area->id,
            'edicao_id' => $this->edicao->id,
        ]);

        if ($plano !== null) {
            $path = "projetos/{$projeto->id}/".uniqid().'.pdf';
            Storage::disk('local')->put($path, $plano);
            $projeto->documentos()->create([
                'tipo' => TipoDocumento::PlanoPesquisa,
                'disk' => 'local',
                'path' => $path,
                'nome_original' => 'meu plano.pdf',
                'mime' => 'application/pdf',
                'tamanho_bytes' => strlen($plano),
            ]);
        }

        // Outro documento do mesmo projeto: não pode entrar no ZIP.
        $path = "projetos/{$projeto->id}/".uniqid().'.pdf';
        Storage::disk('local')->put($path, 'ETICA');
        $projeto->documentos()->create([
            'tipo' => TipoDocumento::TermoEtica, 'disk' => 'local', 'path' => $path,
            'nome_original' => 'etica.pdf', 'mime' => 'application/pdf', 'tamanho_bytes' => 5,
        ]);

        return $projeto;
    }

    private function lista(Projeto ...$projetos): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id,
            'nome' => 'Lista final oficial',
            'tipo' => ListaFinal::TIPO_FINAL,
            'vigente' => true,
            'demo' => false,
            'versao' => 1,
        ]);
        $lista->projetos()->attach(collect($projetos)->pluck('id'));

        return $lista;
    }

    /** @return array<string, string> nome no zip => conteúdo */
    private function lerZip(string $binario): array
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'teste-zip-');
        file_put_contents($arquivo, $binario);
        $zip = new ZipArchive;
        $zip->open($arquivo);

        $conteudo = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            $conteudo[$nome] = $zip->getFromName($nome);
        }

        $zip->close();
        unlink($arquivo);

        return $conteudo;
    }

    public function test_baixa_todos_em_pastas_categoria_e_area_com_o_codigo_no_nome(): void
    {
        $this->admin();
        $milho = $this->projeto('Milho resistente', Categoria::Fetecms, $this->agrarias, 'MILHO');
        $abelhas = $this->projeto('Abelhas nativas', Categoria::FetecJr, $this->biologicas, 'ABELHAS');
        $solo = $this->projeto('Solo vivo', Categoria::FetecmsFundect, $this->agrarias, 'SOLO');
        $lista = $this->lista($milho, $abelhas, $solo);

        $resposta = $this->post("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos-pesquisa")->assertOk();

        $this->assertStringContainsString('projetos-de-pesquisa-lista-final-oficial.zip',
            $resposta->headers->get('content-disposition'));

        $arquivos = $this->lerZip($resposta->streamedContent());

        $this->assertSame([
            'FETECMS/Ciências Agrárias/FET.AGR-001 - Milho resistente.pdf' => 'MILHO',
            'FETEC Jr/Ciências Biológicas/JR.BIO-001 - Abelhas nativas.pdf' => 'ABELHAS',
            'FETECMS FUNDECT/Ciências Agrárias/PIC.AGR-001 - Solo vivo.pdf' => 'SOLO',
        ], $arquivos);
    }

    public function test_baixa_so_os_selecionados(): void
    {
        $this->admin();
        $milho = $this->projeto('Milho resistente', Categoria::Fetecms, $this->agrarias);
        $abelhas = $this->projeto('Abelhas nativas', Categoria::FetecJr, $this->biologicas);
        $fora = $this->projeto('Fora da lista', Categoria::FetecJr, $this->biologicas);
        $lista = $this->lista($milho, $abelhas);

        // O projeto de fora da lista é ignorado, mesmo pedido.
        $resposta = $this->post("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos-pesquisa", [
            'projeto_ids' => [$abelhas->id, $fora->id],
        ])->assertOk();

        $this->assertSame(
            ['FETEC Jr/Ciências Biológicas/JR.BIO-001 - Abelhas nativas.pdf'],
            array_keys($this->lerZip($resposta->streamedContent())),
        );
    }

    public function test_quem_esta_sem_o_arquivo_aparece_num_aviso_dentro_do_zip(): void
    {
        $this->admin();
        $milho = $this->projeto('Milho resistente', Categoria::Fetecms, $this->agrarias);
        $semPlano = $this->projeto('Sem plano', Categoria::Fetecms, $this->agrarias, plano: null);
        $sumiu = $this->projeto('Arquivo sumido', Categoria::Fetecms, $this->biologicas);
        Storage::disk('local')->delete($sumiu->documentos()->where('tipo', TipoDocumento::PlanoPesquisa)->value('path'));
        $lista = $this->lista($milho, $semPlano, $sumiu);

        $arquivos = $this->lerZip(
            $this->post("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos-pesquisa")->assertOk()->streamedContent()
        );

        $this->assertArrayHasKey('FETECMS/Ciências Agrárias/FET.AGR-001 - Milho resistente.pdf', $arquivos);
        $aviso = $arquivos[ExportacaoProjetosPesquisaService::ARQUIVO_FALTANTES];
        $this->assertStringContainsString('FET.AGR-002 - Sem plano', $aviso);
        $this->assertStringContainsString('FET.BIO-001 - Arquivo sumido', $aviso);
        $this->assertStringNotContainsString('Milho', $aviso);
    }

    public function test_dois_arquivos_do_mesmo_projeto_nao_se_sobrescrevem(): void
    {
        $this->admin();
        $milho = $this->projeto('Milho resistente', Categoria::Fetecms, $this->agrarias, 'V1');
        $path = "projetos/{$milho->id}/segundo.pdf";
        Storage::disk('local')->put($path, 'V2');
        $milho->documentos()->create([
            'tipo' => TipoDocumento::PlanoPesquisa, 'disk' => 'local', 'path' => $path,
            'nome_original' => 'plano-v2.pdf', 'mime' => 'application/pdf', 'tamanho_bytes' => 2,
        ]);
        $lista = $this->lista($milho);

        $arquivos = $this->lerZip(
            $this->post("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos-pesquisa")->assertOk()->streamedContent()
        );

        $this->assertSame([
            'FETECMS/Ciências Agrárias/FET.AGR-001 - Milho resistente.pdf' => 'V1',
            'FETECMS/Ciências Agrárias/FET.AGR-001 - Milho resistente (2).pdf' => 'V2',
        ], $arquivos);
    }

    public function test_sem_nenhum_arquivo_disponivel_e_recusa_e_nao_zip_vazio(): void
    {
        $this->admin();
        $lista = $this->lista($this->projeto('Sem plano', Categoria::Fetecms, $this->agrarias, plano: null));

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos-pesquisa")
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto_ids');
    }

    public function test_selecao_vazia_ou_toda_de_fora_da_lista_e_recusada(): void
    {
        $this->admin();
        $lista = $this->lista($this->projeto('Milho resistente', Categoria::Fetecms, $this->agrarias));
        $fora = $this->projeto('Fora da lista', Categoria::FetecJr, $this->biologicas);

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos-pesquisa", ['projeto_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto_ids');

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos-pesquisa", ['projeto_ids' => [$fora->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto_ids');
    }

    public function test_so_admin_baixa(): void
    {
        $lista = $this->lista($this->projeto('Milho resistente', Categoria::Fetecms, $this->agrarias));
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos-pesquisa")->assertForbidden();
    }
}
