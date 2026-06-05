<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function projetoDe(User $user): Projeto
    {
        return Projeto::factory()->create(['user_id' => $user->id, 'categoria' => Categoria::Fetecms]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf');
    }

    public function test_orientador_faz_upload_de_pdf(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user);
        Sanctum::actingAs($user);

        $this->post("/api/v1/projetos/{$projeto->id}/documentos",
            ['file' => $this->pdf(), 'tipo' => 'plano_pesquisa'],
            ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'plano_pesquisa')
            ->assertJsonMissingPath('data.path'); // nunca expõe path interno

        $doc = ProjetoDocumento::first();
        Storage::disk('local')->assertExists($doc->path);
    }

    public function test_rejeita_arquivo_que_nao_e_pdf_ou_docx(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user);
        Sanctum::actingAs($user);

        $this->post("/api/v1/projetos/{$projeto->id}/documentos",
            ['file' => UploadedFile::fake()->create('arquivo.txt', 10), 'tipo' => 'plano_pesquisa'],
            ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_rejeita_arquivo_maior_que_10mb(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user);
        Sanctum::actingAs($user);

        $this->post("/api/v1/projetos/{$projeto->id}/documentos",
            ['file' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf'), 'tipo' => 'plano_pesquisa'],
            ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_nao_faz_upload_em_projeto_alheio(): void
    {
        $alheio = $this->projetoDe(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->post("/api/v1/projetos/{$alheio->id}/documentos",
            ['file' => $this->pdf(), 'tipo' => 'plano_pesquisa'],
            ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_download_e_remocao_do_proprio_documento(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user);
        Sanctum::actingAs($user);

        $id = $this->post("/api/v1/projetos/{$projeto->id}/documentos",
            ['file' => $this->pdf(), 'tipo' => 'plano_pesquisa'],
            ['Accept' => 'application/json'])->json('data.id');
        $path = ProjetoDocumento::find($id)->path;

        $this->get("/api/v1/documentos/{$id}/download")->assertOk();

        $this->deleteJson("/api/v1/documentos/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_nao_baixa_documento_de_projeto_alheio(): void
    {
        $alheio = ProjetoDocumento::factory()->create([
            'projeto_id' => $this->projetoDe(User::factory()->create())->id,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/documentos/{$alheio->id}/download")->assertForbidden();
    }
}
