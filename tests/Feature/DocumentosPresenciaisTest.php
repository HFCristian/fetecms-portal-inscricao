<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoDocumento;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Aba "Documentos" do orientador: o termo de responsabilidade dos projetos
 * finalistas, com a conferência da assinatura digital.
 */
class DocumentosPresenciaisTest extends TestCase
{
    use RefreshDatabase;

    private User $orientador;

    private Projeto $projeto;

    private Edicao $edicao;

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
            'user_id' => $this->orientador->id,
            'titulo' => 'Bioplástico de mandioca',
        ]);
    }

    /** Publica uma lista final vigente com os projetos informados. */
    private function listaFinal(array $projetos, bool $demo = false): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id,
            'nome' => 'Lista final',
            'vigente' => true,
            'demo' => $demo,
            'versao' => 1,
        ]);

        $lista->projetos()->attach(collect($projetos)->mapWithKeys(fn ($p) => [$p->id => ['manual' => false]])->all());

        return $lista;
    }

    private function pdf(string $nome = 'termo.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, "%PDF-1.7\nsem assinatura\n%%EOF");
    }

    private function comoOrientador(): void
    {
        Sanctum::actingAs($this->orientador);
    }

    public function test_sem_lista_final_a_aba_nao_abre(): void
    {
        $this->comoOrientador();

        $this->getJson('/api/v1/documentos-presenciais')
            ->assertOk()
            ->assertJsonPath('data.janela.aberta', false)
            ->assertJsonPath('data.janela.tem_lista', false)
            ->assertJsonCount(0, 'data.projetos');
    }

    public function test_lista_so_os_projetos_finalistas_do_orientador(): void
    {
        $naoFinalista = Projeto::factory()->submetido()->create([
            'user_id' => $this->orientador->id, 'titulo' => 'Ficou de fora',
        ]);
        $deOutro = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
        ]);

        $this->listaFinal([$this->projeto, $deOutro]);
        $this->comoOrientador();

        $this->getJson('/api/v1/documentos-presenciais')
            ->assertOk()
            ->assertJsonPath('data.janela.aberta', true)
            ->assertJsonCount(1, 'data.projetos')
            ->assertJsonPath('data.projetos.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('data.projetos.0.termo', null);

        $this->assertNotNull($naoFinalista);
    }

    public function test_anexa_o_termo_e_ele_aparece_na_lista(): void
    {
        $this->listaFinal([$this->projeto]);
        $this->comoOrientador();

        $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo", [
            'file' => $this->pdf(),
        ])->assertCreated()->assertJsonPath('data.nome_original', 'termo.pdf');

        $this->assertDatabaseHas('projeto_documentos', [
            'projeto_id' => $this->projeto->id,
            'tipo' => TipoDocumento::TermoResponsabilidade->value,
        ]);

        $this->getJson('/api/v1/documentos-presenciais')
            ->assertOk()
            ->assertJsonPath('data.projetos.0.termo.nome_original', 'termo.pdf');
    }

    public function test_pdf_sem_assinatura_e_aceito_mas_marcado(): void
    {
        $this->listaFinal([$this->projeto]);
        $this->comoOrientador();

        // O arquivo não é recusado: um termo assinado à caneta e digitalizado
        // continua sendo um termo, e travar o envio deixaria o finalista sem saída.
        $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo", [
            'file' => $this->pdf(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.assinatura.valida', false)
            ->assertJsonPath('data.assinatura.assinado', false)
            ->assertJsonPath('data.assinatura.motivo', 'O arquivo não traz assinatura digital.');
    }

    public function test_reenviar_substitui_o_termo_anterior(): void
    {
        $this->listaFinal([$this->projeto]);
        $this->comoOrientador();

        $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo", ['file' => $this->pdf('velho.pdf')])
            ->assertCreated();
        $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo", ['file' => $this->pdf('novo.pdf')])
            ->assertCreated();

        $termos = ProjetoDocumento::where('projeto_id', $this->projeto->id)
            ->where('tipo', TipoDocumento::TermoResponsabilidade->value)
            ->get();

        $this->assertCount(1, $termos);
        $this->assertSame('novo.pdf', $termos->first()->nome_original);
    }

    public function test_remove_o_termo(): void
    {
        $this->listaFinal([$this->projeto]);
        $this->comoOrientador();

        $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo", ['file' => $this->pdf()])
            ->assertCreated();

        $this->deleteJson("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo")->assertOk();

        $this->assertDatabaseMissing('projeto_documentos', [
            'projeto_id' => $this->projeto->id,
            'tipo' => TipoDocumento::TermoResponsabilidade->value,
        ]);
    }

    public function test_projeto_nao_finalista_nao_aceita_termo(): void
    {
        $fora = Projeto::factory()->submetido()->create(['user_id' => $this->orientador->id]);
        $this->listaFinal([$this->projeto]);
        $this->comoOrientador();

        $this->post("/api/v1/documentos-presenciais/projetos/{$fora->id}/termo", ['file' => $this->pdf()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto');
    }

    public function test_projeto_de_outro_orientador_e_barrado(): void
    {
        $alheio = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
        ]);
        $this->listaFinal([$alheio]);
        $this->comoOrientador();

        $this->post("/api/v1/documentos-presenciais/projetos/{$alheio->id}/termo", ['file' => $this->pdf()])
            ->assertForbidden();
    }

    public function test_depois_do_evento_o_envio_fecha(): void
    {
        $this->listaFinal([$this->projeto]);
        $this->edicao->update(['evento_de' => now()->subDays(5), 'evento_ate' => now()->subDay()]);
        $this->comoOrientador();

        $this->getJson('/api/v1/documentos-presenciais')
            ->assertOk()
            ->assertJsonPath('data.janela.aberta', false)
            ->assertJsonPath('data.janela.encerrada', true);

        $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo", ['file' => $this->pdf()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('periodo');
    }

    public function test_so_aceita_pdf_e_respeita_o_tamanho(): void
    {
        $this->listaFinal([$this->projeto]);
        $this->comoOrientador();

        $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo", [
            'file' => UploadedFile::fake()->create('termo.docx', 100),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/termo", [
            'file' => UploadedFile::fake()->create('termo.pdf', 20480, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_conta_demo_usa_a_lista_de_demonstracao_em_modo_teste(): void
    {
        // A lista oficial não tem o projeto; a demo tem.
        $this->listaFinal([]);
        $this->listaFinal([$this->projeto], demo: true);
        $this->orientador->update(['is_demo' => true]);
        $this->comoOrientador();

        $this->getJson('/api/v1/documentos-presenciais')
            ->assertOk()
            ->assertJsonCount(0, 'data.projetos');

        $this->getJson('/api/v1/documentos-presenciais?teste=1')
            ->assertOk()
            ->assertJsonPath('data.janela.modo_teste', true)
            ->assertJsonCount(1, 'data.projetos');
    }

    public function test_avaliador_nao_acessa(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Avaliador->value]));

        $this->getJson('/api/v1/documentos-presenciais')->assertForbidden();
    }
}
