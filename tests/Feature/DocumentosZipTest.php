<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\TipoDocumento;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Sprint 167 — o orientador baixa de uma vez todos os documentos que enviou.
 */
class DocumentosZipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function projetoSubmetido(User $dono): Projeto
    {
        return Projeto::factory()->create([
            'user_id' => $dono->id,
            'categoria' => Categoria::Fetecms,
            'status' => ProjetoStatus::Submetido,
            'titulo' => 'Água limpa no sertão',
        ]);
    }

    private function anexar(Projeto $projeto, TipoDocumento $tipo, string $nome, string $conteudo): ProjetoDocumento
    {
        $path = "projetos/{$projeto->id}/".uniqid().'.pdf';
        Storage::disk('local')->put($path, $conteudo);

        return $projeto->documentos()->create([
            'tipo' => $tipo,
            'disk' => 'local',
            'path' => $path,
            'nome_original' => $nome,
            'mime' => 'application/pdf',
            'tamanho_bytes' => strlen($conteudo),
        ]);
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

    public function test_orientador_baixa_todos_os_documentos_num_zip(): void
    {
        $dono = User::factory()->create();
        $projeto = $this->projetoSubmetido($dono);
        $this->anexar($projeto, TipoDocumento::PlanoPesquisa, 'plano.pdf', 'PLANO');
        $this->anexar($projeto, TipoDocumento::TermoEtica, 'etica.pdf', 'ETICA');
        // Dois anexos com o mesmo nome não se sobrescrevem.
        $this->anexar($projeto, TipoDocumento::Anexo, 'foto.pdf', 'A1');
        $this->anexar($projeto, TipoDocumento::Anexo, 'foto.pdf', 'A2');
        Sanctum::actingAs($dono);

        $resposta = $this->get("/api/v1/projetos/{$projeto->id}/documentos/zip")->assertOk();

        $this->assertStringContainsString('documentos-'.$projeto->id.'-agua-limpa-no-sertao.zip',
            $resposta->headers->get('content-disposition'));

        $arquivos = $this->lerZip(file_get_contents($resposta->getFile()->getPathname()));

        $this->assertSame('PLANO', $arquivos['Projeto de Pesquisa - plano.pdf']);
        $this->assertSame('ETICA', $arquivos['Termo do Comitê de Ética - etica.pdf']);
        $this->assertSame('A1', $arquivos['Anexo - foto.pdf']);
        $this->assertSame('A2', $arquivos['Anexo - foto (2).pdf']);
    }

    public function test_arquivo_sumido_do_storage_fica_de_fora(): void
    {
        $dono = User::factory()->create();
        $projeto = $this->projetoSubmetido($dono);
        $this->anexar($projeto, TipoDocumento::PlanoPesquisa, 'plano.pdf', 'PLANO');
        $sumido = $this->anexar($projeto, TipoDocumento::Anexo, 'sumido.pdf', 'X');
        Storage::disk('local')->delete($sumido->path);
        Sanctum::actingAs($dono);

        $resposta = $this->get("/api/v1/projetos/{$projeto->id}/documentos/zip")->assertOk();
        $arquivos = $this->lerZip(file_get_contents($resposta->getFile()->getPathname()));

        $this->assertSame(['Projeto de Pesquisa - plano.pdf'], array_keys($arquivos));
    }

    public function test_sem_documento_responde_422(): void
    {
        $dono = User::factory()->create();
        $projeto = $this->projetoSubmetido($dono);
        Sanctum::actingAs($dono);

        $this->getJson("/api/v1/projetos/{$projeto->id}/documentos/zip")
            ->assertStatus(422)
            ->assertJsonValidationErrors('documentos');
    }

    public function test_nao_baixa_zip_de_projeto_alheio(): void
    {
        $projeto = $this->projetoSubmetido(User::factory()->create());
        $this->anexar($projeto, TipoDocumento::PlanoPesquisa, 'plano.pdf', 'PLANO');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/projetos/{$projeto->id}/documentos/zip")->assertForbidden();
    }
}
