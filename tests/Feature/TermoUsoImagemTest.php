<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoDocumento;
use App\Models\Aluno;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use App\Services\AvaliacaoFluxoService;
use App\Services\TermosImagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 174 — o termo de uso de imagem dos finalistas: um ou mais arquivos
 * por projeto, cada um valendo para os estudantes marcados.
 */
class TermoUsoImagemTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private User $orientador;

    private Projeto $projeto;

    private Aluno $ana;

    private Aluno $beto;

    private Aluno $caio;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subDay(), 'evento_ate' => now()->addDays(3),
        ]);

        $this->orientador = User::factory()->create(['role' => Role::Orientador->value]);
        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => $this->orientador->id, 'titulo' => 'Bioplástico', 'edicao_id' => $this->edicao->id,
        ]);
        $this->ana = Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Ana']);
        $this->beto = Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Beto']);
        $this->caio = Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Caio']);

        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'vigente' => true, 'versao' => 1,
        ]);
        $lista->projetos()->attach($this->projeto->id, ['manual' => false]);

        Sanctum::actingAs($this->orientador);
    }

    private function enviar(array $alunos, ?UploadedFile $arquivo = null, ?Projeto $projeto = null)
    {
        return $this->post('/api/v1/documentos-presenciais/projetos/'.($projeto ?? $this->projeto)->id.'/termo-imagem', [
            'file' => $arquivo ?? UploadedFile::fake()->create('autorizacao.jpg', 200, 'image/jpeg'),
            'alunos' => $alunos,
        ], ['Accept' => 'application/json']);
    }

    public function test_foto_do_termo_cobre_os_estudantes_marcados(): void
    {
        $this->enviar([$this->ana->id, $this->beto->id])
            ->assertCreated()
            ->assertJsonPath('meta.message', 'Termo de uso de imagem enviado.')
            ->assertJsonPath('data.situacao', 'parcial')
            ->assertJsonPath('data.cobertos', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.faltando', ['Caio'])
            ->assertJsonPath('data.arquivos.0.pdf', false);

        $documento = ProjetoDocumento::firstOrFail();
        $this->assertSame(TipoDocumento::TermoUsoImagem, $documento->tipo);
        // Foto não tem assinatura digital a conferir.
        $this->assertNull($documento->assinatura);
        Storage::disk('local')->assertExists($documento->path);
    }

    public function test_varios_arquivos_somam_a_cobertura_sem_substituir(): void
    {
        $this->enviar([$this->ana->id])->assertCreated();
        $this->enviar([$this->beto->id, $this->caio->id], UploadedFile::fake()->createWithContent('turma.pdf', "%PDF-1.7\n%%EOF"))
            ->assertCreated()
            ->assertJsonPath('data.situacao', 'completo')
            ->assertJsonPath('data.cobertos', 3)
            ->assertJsonCount(2, 'data.arquivos')
            // O PDF passa pela conferência da assinatura, só como informação.
            ->assertJsonPath('data.arquivos.1.pdf', true)
            ->assertJsonPath('data.arquivos.1.assinatura.assinado', false);

        $this->assertSame(2, ProjetoDocumento::where('tipo', TipoDocumento::TermoUsoImagem->value)->count());
    }

    public function test_estudante_em_dois_arquivos_conta_uma_vez(): void
    {
        $this->enviar([$this->ana->id])->assertCreated();
        $this->enviar([$this->ana->id, $this->beto->id])
            ->assertJsonPath('data.cobertos', 2)
            ->assertJsonPath('data.situacao', 'parcial');
    }

    public function test_precisa_marcar_ao_menos_um_estudante_do_proprio_projeto(): void
    {
        $this->enviar([])->assertStatus(422)->assertJsonValidationErrors('alunos');

        $outro = Aluno::factory()->create();
        $this->enviar([$outro->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alunos' => 'Marque só estudantes deste projeto.']);

        $this->assertSame(0, ProjetoDocumento::count());
    }

    public function test_aceita_pdf_jpg_e_png_e_recusa_outros_formatos(): void
    {
        $this->enviar([$this->ana->id], UploadedFile::fake()->create('foto.png', 100, 'image/png'))->assertCreated();
        $this->enviar([$this->ana->id], UploadedFile::fake()->create('termo.docx', 100))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'Envie o termo em PDF, JPG ou PNG.']);
        $this->enviar([$this->ana->id], UploadedFile::fake()->create('grande.jpg', 11000, 'image/jpeg'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_projeto_que_nao_e_finalista_nao_envia(): void
    {
        $fora = Projeto::factory()->submetido()->create(['user_id' => $this->orientador->id, 'edicao_id' => $this->edicao->id]);
        $aluno = Aluno::factory()->create(['projeto_id' => $fora->id]);

        $this->enviar([$aluno->id], null, $fora)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['projeto' => 'Só projetos finalistas enviam o termo de uso de imagem.']);
    }

    public function test_fora_da_janela_nao_envia(): void
    {
        $this->edicao->update(['evento_de' => now()->subDays(5), 'evento_ate' => now()->subDay()]);

        $this->enviar([$this->ana->id])->assertStatus(422)->assertJsonValidationErrors('periodo');
    }

    public function test_troca_os_estudantes_de_um_arquivo_sem_reenviar(): void
    {
        $this->enviar([$this->ana->id])->assertCreated();
        $documento = ProjetoDocumento::firstOrFail();

        $this->putJson("/api/v1/documentos-presenciais/termo-imagem/{$documento->id}", [
            'alunos' => [$this->ana->id, $this->beto->id, $this->caio->id],
        ])->assertOk()
            ->assertJsonPath('data.situacao', 'completo')
            ->assertJsonPath('data.arquivos.0.alunos.2.nome', 'Caio');

        $this->putJson("/api/v1/documentos-presenciais/termo-imagem/{$documento->id}", ['alunos' => []])
            ->assertStatus(422)->assertJsonValidationErrors('alunos');
    }

    public function test_remover_arquivo_devolve_os_estudantes_para_a_pendencia(): void
    {
        $this->enviar([$this->ana->id])->assertCreated();
        $documento = ProjetoDocumento::firstOrFail();

        $this->deleteJson("/api/v1/documentos-presenciais/termo-imagem/{$documento->id}")
            ->assertOk()
            ->assertJsonPath('data.situacao', 'nenhum')
            ->assertJsonCount(0, 'data.arquivos');

        $this->assertDatabaseCount('projeto_documentos', 0);
        $this->assertDatabaseCount('projeto_documento_alunos', 0);
        Storage::disk('local')->assertMissing($documento->path);
    }

    public function test_outro_orientador_nao_mexe_no_termo_alheio(): void
    {
        $this->enviar([$this->ana->id])->assertCreated();
        $documento = ProjetoDocumento::firstOrFail();

        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador->value]));

        $this->putJson("/api/v1/documentos-presenciais/termo-imagem/{$documento->id}", ['alunos' => [$this->ana->id]])
            ->assertForbidden();
        $this->deleteJson("/api/v1/documentos-presenciais/termo-imagem/{$documento->id}")->assertForbidden();
        $this->enviar([$this->ana->id])->assertForbidden();
    }

    public function test_rota_do_termo_de_imagem_nao_mexe_em_anexo_de_inscricao(): void
    {
        $plano = ProjetoDocumento::factory()->create([
            'projeto_id' => $this->projeto->id, 'tipo' => TipoDocumento::PlanoPesquisa->value,
        ]);

        $this->deleteJson("/api/v1/documentos-presenciais/termo-imagem/{$plano->id}")->assertNotFound();
        $this->assertDatabaseHas('projeto_documentos', ['id' => $plano->id]);
    }

    public function test_aba_lista_os_estudantes_e_a_cobertura_de_cada_projeto(): void
    {
        $this->enviar([$this->beto->id])->assertCreated();

        $projeto = $this->getJson('/api/v1/documentos-presenciais')->assertOk()->json('data.projetos.0');

        $this->assertSame(['Ana', 'Beto', 'Caio'], array_column($projeto['alunos'], 'nome'));
        $this->assertSame('parcial', $projeto['termo_imagem']['situacao']);
        $this->assertSame(['Ana', 'Caio'], $projeto['termo_imagem']['faltando']);
        $this->assertSame(['Beto'], array_column($projeto['termo_imagem']['arquivos'][0]['alunos'], 'nome'));
    }

    public function test_estudante_que_sai_da_equipe_deixa_de_contar(): void
    {
        $this->enviar([$this->ana->id, $this->beto->id])->assertCreated();
        $this->beto->delete();

        $cobertura = app(TermosImagemService::class)->doProjeto($this->projeto->id);

        $this->assertSame(1, $cobertura['cobertos']);
        $this->assertSame(2, $cobertura['total']);
        $this->assertSame(['Caio'], $cobertura['faltando']);
    }

    public function test_corte_ignora_arquivo_enviado_depois(): void
    {
        $this->enviar([$this->ana->id])->assertCreated();
        ProjetoDocumento::query()->update(['created_at' => now()->addDay()]);

        $cobertura = app(TermosImagemService::class)->cobertura([$this->projeto->id], now())[$this->projeto->id];

        $this->assertSame('nenhum', $cobertura['situacao']);
    }

    public function test_projeto_sem_estudantes_nao_tem_o_que_cobrir(): void
    {
        $vazio = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);

        $this->assertSame('sem_estudantes', app(TermosImagemService::class)->doProjeto($vazio->id)['situacao']);
    }

    public function test_termos_nao_entram_como_anexo_de_inscricao(): void
    {
        $rascunho = Projeto::factory()->create(['user_id' => $this->orientador->id, 'edicao_id' => $this->edicao->id]);

        foreach (['termo_uso_imagem', 'termo_responsabilidade'] as $tipo) {
            $this->post("/api/v1/projetos/{$rascunho->id}/documentos", [
                'file' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'),
                'tipo' => $tipo,
            ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('tipo');
        }
    }

    public function test_avaliador_nao_le_os_termos_do_finalista(): void
    {
        $this->enviar([$this->ana->id])->assertCreated();
        ProjetoDocumento::factory()->create([
            'projeto_id' => $this->projeto->id, 'tipo' => TipoDocumento::TermoResponsabilidade->value,
        ]);
        ProjetoDocumento::factory()->create([
            'projeto_id' => $this->projeto->id, 'tipo' => TipoDocumento::PlanoPesquisa->value,
        ]);

        $leitura = app(AvaliacaoFluxoService::class)->detalhesProjeto($this->projeto->fresh());

        $this->assertSame(['plano_pesquisa'], array_column($leitura['documentos'], 'tipo'));
    }
}
