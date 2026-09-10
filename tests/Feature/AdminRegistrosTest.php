<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoRegistro;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\RegistroAtividadeService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Painel de registros do admin: listagem filtrável e export CSV. */
class AdminRegistrosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function registro(TipoRegistro $tipo, array $over = [], ?Carbon $quando = null): RegistroAtividade
    {
        $registro = RegistroAtividade::create(array_merge([
            'tipo' => $tipo,
            'autor_email' => 'orientador@escola.test',
            'autor_nome' => 'Ana Orientadora',
            'autor_role' => Role::Orientador->value,
            'projeto_titulo' => 'Bioplástico de Mandioca',
            'dono_email' => 'orientador@escola.test',
        ], $over));

        // created_at não é fillable: precisa de forceFill para datar o registro.
        if ($quando) {
            $registro->forceFill(['created_at' => $quando])->save();
        }

        return $registro;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_lista_registros_mais_recentes_primeiro(): void
    {
        $this->registro(TipoRegistro::Submissao, [], now()->subDays(3));
        $this->registro(TipoRegistro::Exclusao, [], now()->subDay());
        $this->admin();

        $resposta = $this->getJson('/api/v1/admin/registros')->assertOk();

        $resposta->assertJsonPath('data.0.tipo', TipoRegistro::Exclusao->value);
        $resposta->assertJsonPath('data.1.tipo', TipoRegistro::Submissao->value);
        $resposta->assertJsonPath('meta.total', 2);
        $resposta->assertJsonPath('meta.totais_por_tipo.submissao', 1);
        $resposta->assertJsonPath('meta.totais_por_tipo.cancelamento', 0);
    }

    public function test_filtra_por_tipo(): void
    {
        $this->registro(TipoRegistro::Submissao);
        $this->registro(TipoRegistro::Exclusao);
        $this->admin();

        $this->getJson('/api/v1/admin/registros?tipos=exclusao')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', TipoRegistro::Exclusao->value)
            // Os totais dos cartões ignoram o filtro de tipo, de propósito.
            ->assertJsonPath('meta.totais_por_tipo.submissao', 1);
    }

    public function test_filtra_por_periodo(): void
    {
        $this->registro(TipoRegistro::Submissao, [], now()->subMonth());
        $this->registro(TipoRegistro::Cancelamento);
        $this->admin();

        $this->getJson('/api/v1/admin/registros?de='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', TipoRegistro::Cancelamento->value);
    }

    public function test_busca_por_email_nome_ou_titulo(): void
    {
        $this->registro(TipoRegistro::Submissao, ['autor_email' => 'maria@escola.test']);
        $this->registro(TipoRegistro::Submissao, ['projeto_titulo' => 'Robô Seguidor de Linha']);
        $this->admin();

        $this->getJson('/api/v1/admin/registros?busca=maria')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.autor_email', 'maria@escola.test');

        $this->getJson('/api/v1/admin/registros?busca=seguidor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.projeto_titulo', 'Robô Seguidor de Linha');
    }

    public function test_data_final_anterior_a_inicial_e_rejeitada(): void
    {
        $this->admin();

        $this->getJson('/api/v1/admin/registros?de=2026-08-10&ate=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('ate');
    }

    public function test_export_csv_respeita_os_filtros(): void
    {
        $this->registro(TipoRegistro::Submissao, ['autor_email' => 'maria@escola.test']);
        $this->registro(TipoRegistro::Exclusao, ['autor_email' => 'joao@escola.test']);
        $this->admin();

        $resposta = $this->get('/api/v1/admin/registros/exportar?tipos=exclusao')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $resposta->getContent();
        $this->assertStringContainsString('joao@escola.test', $csv);
        $this->assertStringNotContainsString('maria@escola.test', $csv);
        $this->assertStringContainsString('Exclusão', $csv);
        // BOM para o Excel em pt_BR abrir com acentuação correta.
        $this->assertStringStartsWith("\u{FEFF}", $csv);
    }

    public function test_orientador_e_avaliador_nao_acessam_os_registros(): void
    {
        $this->registro(TipoRegistro::Submissao);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador]));
        $this->getJson('/api/v1/admin/registros')->assertForbidden();
        $this->get('/api/v1/admin/registros/exportar')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => Role::Avaliador]));
        $this->getJson('/api/v1/admin/registros')->assertForbidden();
    }

    /**
     * O histórico anterior à trilha é reconstruído pela migration: cada projeto já
     * submetido vira um registro datado do próprio submitted_at.
     */
    public function test_migration_reconstroi_o_historico_de_submissoes(): void
    {
        $orientador = User::factory()->create(['role' => Role::Orientador]);
        $antigo = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id,
            'titulo' => 'Projeto Antigo',
        ]);
        $antigo->forceFill(['submitted_at' => now()->subMonths(2)])->save();
        Projeto::factory()->create(['user_id' => $orientador->id, 'titulo' => 'Só Rascunho']);

        // Refaz a tabela para rodar o backfill com os projetos já no banco.
        Schema::dropIfExists('registros_atividade');
        (require database_path('migrations/2026_08_12_100000_create_registros_atividade_table.php'))->up();

        $registro = RegistroAtividade::sole();
        $this->assertSame(TipoRegistro::Submissao, $registro->tipo);
        $this->assertSame('Projeto Antigo', $registro->projeto_titulo);
        $this->assertSame($orientador->email, $registro->autor_email);
        $this->assertTrue($registro->created_at->isSameDay($antigo->submitted_at));
    }

    public function test_submissao_do_orientador_entra_na_trilha(): void
    {
        $orientador = User::factory()->create(['role' => Role::Orientador]);
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id,
            'edicao_id' => Edicao::atual()->id,
        ]);
        Sanctum::actingAs($orientador);

        // Cancelar e submeter de novo produz o par cancelamento + submissão.
        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")->assertOk();

        $this->admin();
        $resposta = $this->getJson('/api/v1/admin/registros')->assertOk();

        $resposta->assertJsonPath('data.0.tipo', TipoRegistro::Cancelamento->value);
        $resposta->assertJsonPath('data.0.autor_email', $orientador->email);
        $resposta->assertJsonPath('data.0.projeto_titulo', $projeto->titulo);
        $resposta->assertJsonPath('data.0.por_terceiro', false);
    }

    public function test_mudar_os_parametros_da_avaliacao_vira_registro(): void
    {
        $this->seed(CatalogoSeeder::class); // edição atual
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_avaliador' => 5])->assertOk();
        $this->patchJson('/api/v1/admin/avaliacao/config', ['liberada_em' => '2026-10-01T08:00'])->assertOk();

        $this->assertDatabaseHas('registros_atividade', [
            'tipo' => 'avaliacao_min_avaliador',
            'autor_email' => $admin->email,
        ]);
        $this->assertDatabaseHas('registros_atividade', ['tipo' => 'avaliacao_liberacao']);

        // Salvar o mesmo valor de novo não gera registro repetido.
        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_avaliador' => 5])->assertOk();
        $this->assertSame(1, RegistroAtividade::where('tipo', 'avaliacao_min_avaliador')->count());
    }

    public function test_secao_separa_inscricoes_de_avaliacao_online(): void
    {
        $this->seed(CatalogoSeeder::class);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_projeto' => 4])->assertOk();

        // A seção "avaliacao" só traz a parametrização, com as tags daquela seção.
        $avaliacao = $this->getJson('/api/v1/admin/registros?secao=avaliacao')->assertOk();
        $this->assertSame(1, $avaliacao->json('meta.total'));
        $this->assertSame('avaliacao_min_projeto', $avaliacao->json('data.0.tipo'));
        $this->assertSame(
            [
                'avaliacao_liberacao', 'avaliacao_encerramento',
                'avaliacao_min_avaliador', 'avaliacao_min_projeto',
                'avaliacao_max_avaliador', 'avaliacao_max_projeto', 'avaliacao_limites_categoria',
                'avaliacao_regra_distribuicao', 'avaliacao_designacao_ao_cadastrar',
                'avaliacao_piso_fila', 'avaliacao_designacoes_projeto',
                'avaliacao_modo_distribuicao',
                'avaliacao_designacao_retirada', 'avaliacao_parecer_editado',
                'avaliacao_ajustes_inicio', 'avaliacao_ajustes_fim',
            ],
            array_column($avaliacao->json('meta.tipos'), 'value'),
        );

        // A seção "inscricoes" não enxerga a parametrização.
        $inscricoes = $this->getJson('/api/v1/admin/registros?secao=inscricoes')->assertOk();
        $this->assertSame(0, $inscricoes->json('meta.total'));
        $this->assertSame(
            ['submissao', 'cancelamento', 'exclusao', 'troca_email'],
            array_column($inscricoes->json('meta.tipos'), 'value'),
        );
    }

    public function test_registro_de_parametro_mostra_o_de_para(): void
    {
        $this->seed(CatalogoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_avaliador' => 7])->assertOk();

        $this->getJson('/api/v1/admin/registros?secao=avaliacao')
            ->assertOk()
            ->assertJsonPath('data.0.tipo_label', 'Mínimo por avaliador')
            ->assertJsonPath('data.0.detalhes_texto', '3 → 7');
    }

    public function test_secao_invalida_e_recusada(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/registros?secao=inexistente')
            ->assertStatus(422)
            ->assertJsonValidationErrors('secao');
    }

    public function test_secao_projetos_traz_so_as_correcoes_do_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $projeto = Projeto::factory()->submetido()->create(['user_id' => User::factory()->create()->id]);

        app(RegistroAtividadeService::class)->correcaoProjeto(
            TipoRegistro::ProjetoCategoria, $projeto, $admin, 'FETECMS', 'FETEC Jr', 'Erro de digitação.',
        );
        app(RegistroAtividadeService::class)->submissao($projeto, $projeto->user);

        Sanctum::actingAs($admin);

        $resposta = $this->getJson('/api/v1/admin/registros?secao=projetos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', TipoRegistro::ProjetoCategoria->value)
            ->assertJsonPath('meta.secao', 'projetos');

        // Os filtros de tipo da tela são só os da seção.
        $tipos = array_column($resposta->json('meta.tipos'), 'value');
        $this->assertSame([
            TipoRegistro::ProjetoCategoria->value,
            TipoRegistro::ProjetoArea->value,
            TipoRegistro::ProjetoSubarea->value,
            TipoRegistro::ProjetoVideo->value,
            TipoRegistro::ProjetoOrientador->value,
            TipoRegistro::ProjetoCoorientador->value,
        ], $tipos);

        // O CSV descreve o "de → para" e a justificativa.
        $csv = $this->get('/api/v1/admin/registros/exportar?secao=projetos')->assertOk()->getContent();
        $this->assertStringContainsString('FETECMS → FETEC Jr', $csv);
        $this->assertStringContainsString('justificativa: Erro de digitação.', $csv);
    }
}
