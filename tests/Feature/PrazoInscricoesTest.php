<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\TipoDocumento;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use App\Services\InscricoesService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prazo de submissão das inscrições: passada a data-limite, a área de projetos
 * do orientador fica só de leitura. O admin passa por cima.
 */
class PrazoInscricoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function definirPrazo(?string $quando): void
    {
        Edicao::atual()->update(['submissoes_ate' => $quando]);
    }

    private function orientador(): User
    {
        $user = User::factory()->create(['role' => Role::Orientador]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Projeto que satisfaz todo o checklist de submissão. */
    private function projetoCompleto(User $user, array $over = []): Projeto
    {
        $area = Area::first();
        $estado = Estado::where('uf', 'MS')->first();

        $projeto = Projeto::factory()->create(array_merge([
            'user_id' => $user->id,
            'titulo' => 'Bioplástico de Mandioca',
            'categoria' => Categoria::Fetecms,
            'instituicao_id' => Instituicao::first()->id,
            'area_id' => $area->id,
            'subarea_id' => $area->subareas()->first()->id,
            'palavras_chave' => ['Biotecnologia', 'Sustentabilidade', 'Mandioca'],
            'pais' => 'BR',
            'estado_id' => $estado->id,
            'cidade_id' => $estado->cidades()->first()->id,
            'link_video' => 'https://youtu.be/abcdefghijk',
            'resumo' => implode(' ', array_fill(0, 160, 'palavra')),
            'email_comunicacao' => 'contato@escola.ms.gov.br',
            'declaracao_email' => true,
        ], $over));

        Aluno::factory()->create(['projeto_id' => $projeto->id]);
        ProjetoDocumento::factory()->create([
            'projeto_id' => $projeto->id,
            'tipo' => TipoDocumento::PlanoPesquisa,
        ]);

        return $projeto;
    }

    // ---------------------------------------------------------------- serviço

    public function test_sem_prazo_definido_as_inscricoes_ficam_abertas(): void
    {
        $this->definirPrazo(null);

        $this->assertFalse(app(InscricoesService::class)->encerradas());
    }

    public function test_prazo_no_futuro_mantem_as_inscricoes_abertas(): void
    {
        $this->definirPrazo(now()->addDay()->toDateTimeString());

        $this->assertFalse(app(InscricoesService::class)->encerradas());
    }

    public function test_prazo_vencido_encerra_as_inscricoes(): void
    {
        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        $this->assertTrue(app(InscricoesService::class)->encerradas());
    }

    // ------------------------------------------------------------- submissão

    public function test_submete_dentro_do_prazo(): void
    {
        $user = $this->orientador();
        $projeto = $this->projetoCompleto($user);
        $this->definirPrazo(now()->addDay()->toDateTimeString());

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")
            ->assertOk()
            ->assertJsonPath('data.status', ProjetoStatus::Submetido->value);
    }

    public function test_nao_submete_depois_do_prazo(): void
    {
        $user = $this->orientador();
        $projeto = $this->projetoCompleto($user);
        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);

        $this->assertSame(ProjetoStatus::Rascunho, $projeto->fresh()->status);
    }

    /**
     * O caso do edital: o orientador cancelou o envio para editar e o prazo
     * venceu no meio do caminho. Não pode reenviar.
     */
    public function test_quem_cancelou_o_envio_nao_reenvia_depois_do_prazo(): void
    {
        $user = $this->orientador();
        $projeto = $this->projetoCompleto($user, ['status' => ProjetoStatus::Submetido, 'submitted_at' => now()]);
        $this->definirPrazo(now()->addHour()->toDateTimeString());

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")->assertOk();
        $this->assertSame(ProjetoStatus::Rascunho, $projeto->fresh()->status);

        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);
    }

    // ------------------------------------------------------- escrita em geral

    public function test_nao_cria_projeto_depois_do_prazo(): void
    {
        $this->orientador();
        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        $this->postJson('/api/v1/projetos', ['titulo' => 'Novo projeto'])
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);
    }

    public function test_nao_edita_rascunho_depois_do_prazo(): void
    {
        $user = $this->orientador();
        $projeto = $this->projetoCompleto($user);
        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        $this->putJson("/api/v1/projetos/{$projeto->id}", ['titulo' => 'Outro título'])
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);

        $this->assertSame('Bioplástico de Mandioca', $projeto->fresh()->titulo);
    }

    public function test_nao_mexe_nos_alunos_nem_nos_documentos_depois_do_prazo(): void
    {
        $user = $this->orientador();
        $projeto = $this->projetoCompleto($user);
        $aluno = $projeto->alunos()->first();
        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        $this->postJson("/api/v1/projetos/{$projeto->id}/alunos", ['nome' => 'Fulano'])
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);

        $this->deleteJson("/api/v1/alunos/{$aluno->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);

        $this->putJson("/api/v1/projetos/{$projeto->id}/coorientador", ['nome' => 'Ciclano'])
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);
    }

    public function test_nao_cancela_nem_exclui_depois_do_prazo(): void
    {
        $user = $this->orientador();
        $projeto = $this->projetoCompleto($user, ['status' => ProjetoStatus::Submetido, 'submitted_at' => now()]);
        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);

        $this->deleteJson("/api/v1/projetos/{$projeto->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', InscricoesService::CODE);

        $this->assertSame(ProjetoStatus::Submetido, $projeto->fresh()->status);
    }

    public function test_leitura_continua_liberada_depois_do_prazo(): void
    {
        $user = $this->orientador();
        $projeto = $this->projetoCompleto($user);
        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        $this->getJson('/api/v1/projetos')->assertOk();
        $this->getJson("/api/v1/projetos/{$projeto->id}")->assertOk();
        $this->getJson("/api/v1/projetos/{$projeto->id}/resumo")
            ->assertOk()
            ->assertJsonPath('data.pode_submeter', false)
            ->assertJsonPath('data.inscricoes.encerradas', true);
    }

    public function test_admin_passa_por_cima_do_prazo(): void
    {
        $orientador = User::factory()->create(['role' => Role::Orientador]);
        $projeto = $this->projetoCompleto($orientador, ['status' => ProjetoStatus::Submetido, 'submitted_at' => now()]);
        $this->definirPrazo(now()->subMinute()->toDateTimeString());

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")
            ->assertOk()
            ->assertJsonPath('data.status', ProjetoStatus::Rascunho->value);
    }

    // ------------------------------------------------------------ tela do admin

    public function test_admin_define_e_remove_o_prazo(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/inscricoes/prazo', ['prazo' => '2026-09-30T23:59'])
            ->assertOk()
            ->assertJsonPath('data.prazo_label', '30/09/2026 23:59')
            ->assertJsonPath('data.prazo_input', '2026-09-30T23:59');

        $this->getJson('/api/v1/admin/inscricoes')
            ->assertOk()
            ->assertJsonPath('data.prazo_label', '30/09/2026 23:59');

        $this->patchJson('/api/v1/admin/inscricoes/prazo', ['prazo' => null])
            ->assertOk()
            ->assertJsonPath('data.prazo_label', null)
            ->assertJsonPath('data.encerradas', false);
    }

    public function test_prazo_e_interpretado_no_fuso_do_app(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/inscricoes/prazo', ['prazo' => '2026-09-30T23:59'])->assertOk();

        // 23:59 é 23:59 em Campo Grande — sem shift de UTC no caminho.
        $this->assertSame('30/09/2026 23:59', Edicao::atual()->submissoes_ate->format('d/m/Y H:i'));
    }

    public function test_prazo_recusa_data_invalida(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/inscricoes/prazo', ['prazo' => 'ontem'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prazo');
    }

    public function test_so_admin_mexe_no_prazo(): void
    {
        $this->orientador();

        $this->getJson('/api/v1/admin/inscricoes')->assertForbidden();
        $this->patchJson('/api/v1/admin/inscricoes/prazo', ['prazo' => null])->assertForbidden();
    }

    public function test_orientador_consulta_o_estado_das_inscricoes(): void
    {
        $this->orientador();
        $this->definirPrazo(now()->addHour()->toDateTimeString());

        $this->getJson('/api/v1/inscricoes')
            ->assertOk()
            ->assertJsonPath('data.encerradas', false)
            ->assertJsonStructure(['data' => ['encerradas', 'prazo_input', 'prazo_label', 'minutos_restantes']]);
    }
}
