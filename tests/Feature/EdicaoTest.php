<?php

namespace Tests\Feature;

use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Scopes\EdicaoScope;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 66 — várias edições convivendo. Uma delas é a PADRÃO (vale para quem
 * não escolheu nenhuma) e cada usuário pode trocar a sua, o que troca todo o
 * escopo: projetos, prazos e limites.
 */
class EdicaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function edicaoNova(array $over = []): Edicao
    {
        return Edicao::create(array_merge([
            'nome' => 'XVII FETECMS',
            'ano' => 2027,
            'inscricoes_abertas' => true,
        ], $over));
    }

    public function test_a_edicao_semeada_e_a_padrao(): void
    {
        $this->assertTrue(Edicao::padrao()->padrao);
        $this->assertSame('XVI FETECMS', Edicao::padrao()->nome);
    }

    public function test_admin_cria_edicao_e_escolhe_a_padrao(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/edicoes', ['nome' => 'XVII FETECMS', 'ano' => 2027])
            ->assertCreated();

        $nova = Edicao::where('ano', 2027)->first();
        $this->assertNotNull($nova);
        // Nasce sem ser padrão: quem está inscrito na edição corrente não muda de escopo sozinho.
        $this->assertFalse($nova->padrao);

        $this->patchJson("/api/v1/admin/edicoes/{$nova->id}/padrao")->assertOk();

        $this->assertTrue($nova->fresh()->padrao);
        // Só uma padrão por vez.
        $this->assertSame(1, Edicao::where('padrao', true)->count());
        $this->assertSame($nova->id, Edicao::padrao()->id);
    }

    public function test_edicao_nova_pode_herdar_a_parametrizacao_da_anterior(): void
    {
        $atual = Edicao::padrao();
        $atual->update([
            'avaliacoes_min_por_projeto' => 5,
            'avaliacoes_max_por_avaliador' => 12,
            'distribuicao_ao_cadastrar' => true,
            // As datas NÃO são copiadas: cada ano tem seu calendário.
            'submissoes_ate' => now()->addMonth(),
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/edicoes', [
            'nome' => 'XVII FETECMS', 'ano' => 2027, 'copiar_de' => $atual->id,
        ])->assertCreated();

        $nova = Edicao::where('ano', 2027)->first();
        $this->assertSame(5, $nova->avaliacoes_min_por_projeto);
        $this->assertSame(12, $nova->avaliacoes_max_por_avaliador);
        $this->assertTrue($nova->distribuicao_ao_cadastrar);
        $this->assertNull($nova->submissoes_ate);
    }

    public function test_nome_repetido_no_mesmo_ano_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/edicoes', ['nome' => 'XVI FETECMS', 'ano' => 2026])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nome');

        // Mesmo nome em outro ano passa.
        $this->postJson('/api/v1/admin/edicoes', ['nome' => 'XVI FETECMS', 'ano' => 2027])
            ->assertCreated();
    }

    public function test_trocar_de_edicao_troca_os_projetos_que_o_orientador_ve(): void
    {
        $atual = Edicao::padrao();
        $nova = $this->edicaoNova();

        $orientador = User::factory()->create();
        $deste = Projeto::factory()->create(['user_id' => $orientador->id, 'edicao_id' => $atual->id, 'titulo' => 'Deste ano']);
        $doProximo = Projeto::factory()->create(['user_id' => $orientador->id, 'edicao_id' => $nova->id, 'titulo' => 'Do ano que vem']);

        Sanctum::actingAs($orientador);

        $ids = fn () => array_column($this->getJson('/api/v1/projetos')->assertOk()->json('data'), 'id');

        $this->assertSame([$deste->id], $ids());

        $this->putJson('/api/v1/edicoes/atual', ['edicao_id' => $nova->id])
            ->assertOk()
            ->assertJsonPath('data.atual_id', $nova->id);

        $this->assertSame([$doProximo->id], $ids());

        // Voltar para "seguir a padrão" devolve o escopo original.
        $this->putJson('/api/v1/edicoes/atual', ['edicao_id' => null])->assertOk();
        $this->assertSame([$deste->id], $ids());
    }

    public function test_o_projeto_nasce_na_edicao_em_escopo(): void
    {
        $nova = $this->edicaoNova();
        $orientador = User::factory()->create(['edicao_id' => $nova->id]);
        Sanctum::actingAs($orientador);

        $this->postJson('/api/v1/projetos', ['titulo' => 'Novo projeto', 'pais' => 'BR'])
            ->assertCreated();

        $projeto = Projeto::withoutGlobalScope(EdicaoScope::class)->where('titulo', 'Novo projeto')->first();
        $this->assertSame($nova->id, $projeto->edicao_id);
    }

    public function test_prazos_seguem_a_edicao_em_escopo(): void
    {
        // Na padrão as inscrições estão encerradas; na nova, abertas.
        Edicao::padrao()->update(['submissoes_ate' => now()->subDay()]);
        $nova = $this->edicaoNova(['submissoes_ate' => now()->addMonth()]);

        $orientador = User::factory()->create();
        Sanctum::actingAs($orientador);

        $this->postJson('/api/v1/projetos', ['titulo' => 'Fora do prazo', 'pais' => 'BR'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INSCRICOES_ENCERRADAS');

        $this->putJson('/api/v1/edicoes/atual', ['edicao_id' => $nova->id])->assertOk();

        $this->postJson('/api/v1/projetos', ['titulo' => 'Dentro do prazo', 'pais' => 'BR'])
            ->assertCreated();
    }

    public function test_todo_usuario_ve_o_seletor_de_edicoes(): void
    {
        $this->edicaoNova();

        foreach ([User::factory()->create(), User::factory()->avaliador()->create(), User::factory()->admin()->create()] as $user) {
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/edicoes')
                ->assertOk()
                ->assertJsonCount(2, 'data.edicoes')
                ->assertJsonPath('data.atual_id', Edicao::padrao()->id);
        }
    }

    public function test_edicao_com_projeto_ou_padrao_nao_e_excluida(): void
    {
        $padrao = Edicao::padrao();
        $comProjeto = $this->edicaoNova();
        Projeto::factory()->create(['user_id' => User::factory()->create()->id, 'edicao_id' => $comProjeto->id]);
        $vazia = $this->edicaoNova(['nome' => 'XVIII FETECMS', 'ano' => 2028]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/v1/admin/edicoes/{$padrao->id}")
            ->assertStatus(422)->assertJsonValidationErrors('edicao');
        $this->deleteJson("/api/v1/admin/edicoes/{$comProjeto->id}")
            ->assertStatus(422)->assertJsonValidationErrors('edicao');

        $this->deleteJson("/api/v1/admin/edicoes/{$vazia->id}")->assertOk();
        $this->assertDatabaseMissing('edicoes', ['id' => $vazia->id]);
    }

    public function test_quem_seguia_a_edicao_excluida_volta_para_a_padrao(): void
    {
        $vazia = $this->edicaoNova();
        $orientador = User::factory()->create(['edicao_id' => $vazia->id]);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->deleteJson("/api/v1/admin/edicoes/{$vazia->id}")->assertOk();

        $this->assertNull($orientador->fresh()->edicao_id);
        $this->assertSame(Edicao::padrao()->id, Edicao::escopoDe($orientador->fresh())->id);
    }

    public function test_orientador_nao_administra_edicoes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/edicoes')->assertForbidden();
        $this->postJson('/api/v1/admin/edicoes', ['nome' => 'Pirata', 'ano' => 2030])->assertForbidden();
    }

    public function test_admin_ve_projetos_da_edicao_em_escopo_no_painel(): void
    {
        $atual = Edicao::padrao();
        $nova = $this->edicaoNova();
        $orientador = User::factory()->create();

        Projeto::factory()->submetido()->create(['user_id' => $orientador->id, 'edicao_id' => $atual->id]);
        Projeto::factory()->submetido()->count(3)->create(['user_id' => $orientador->id, 'edicao_id' => $nova->id]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()->assertJsonPath('data.projetos_submetidos', 1);

        $this->putJson('/api/v1/edicoes/atual', ['edicao_id' => $nova->id])->assertOk();

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()->assertJsonPath('data.projetos_submetidos', 3);
    }
}
