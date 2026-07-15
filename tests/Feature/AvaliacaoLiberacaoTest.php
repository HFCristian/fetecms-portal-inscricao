<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvaliacaoLiberacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    private function avaliadorDesignado(): array
    {
        $area = Area::create(['nome' => 'Área A']);
        $av = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $av->id, 'area_id' => $area->id]);
        $proj = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'area_id' => $area->id, 'titulo' => 'Projeto X',
        ]);
        Avaliacao::create(['projeto_id' => $proj->id, 'avaliador_id' => $av->id, 'status' => 'designada']);

        return [$av, $proj];
    }

    public function test_admin_define_data_de_liberacao_no_futuro(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/config', ['liberada_em' => now()->addDays(5)->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.liberada', false); // data no futuro → ainda não liberada

        $this->getJson('/api/v1/admin/avaliacao/config')
            ->assertOk()
            ->assertJsonPath('data.liberada', false)
            ->assertJsonPath('data.liberada_em_input', fn ($v) => $v !== null)
            ->assertJsonPath('data.liberada_em_label', fn ($v) => $v !== null);
    }

    public function test_liberada_quando_a_data_ja_passou(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/config', ['liberada_em' => now()->subDay()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.liberada', true);
    }

    public function test_avaliador_nao_ve_projetos_antes_da_liberacao(): void
    {
        [$av] = $this->avaliadorDesignado();

        Sanctum::actingAs($av);

        $this->getJson('/api/v1/avaliacao')
            ->assertOk()
            ->assertJsonPath('data.liberada', false)
            ->assertJsonCount(0, 'data.projetos');
    }

    public function test_avaliador_ve_projetos_designados_apos_liberacao(): void
    {
        [$av] = $this->avaliadorDesignado();
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);

        Sanctum::actingAs($av);

        $this->getJson('/api/v1/avaliacao')
            ->assertOk()
            ->assertJsonPath('data.liberada', true)
            ->assertJsonCount(1, 'data.projetos')
            ->assertJsonPath('data.projetos.0.titulo', 'Projeto X')
            ->assertJsonPath('data.projetos.0.status', 'designada');
    }

    public function test_data_e_interpretada_no_fuso_do_app_sem_shift(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/config', ['liberada_em' => '2026-08-17T07:00'])
            ->assertOk()
            ->assertJsonPath('data.liberada_em_input', '2026-08-17T07:00')
            ->assertJsonPath('data.liberada_em_label', '17/08/2026 07:00');

        // 07:00 continua 07:00 (não vira 11:00).
        $this->assertSame('07:00', Edicao::atual()->avaliacao_liberada_em->format('H:i'));
    }

    public function test_demo_ve_projetos_no_indice_com_teste_antes_da_liberacao(): void
    {
        [$av] = $this->avaliadorDesignado(); // designado, sem liberação
        $av->update(['is_demo' => true]);
        Sanctum::actingAs($av);

        $this->getJson('/api/v1/avaliacao')
            ->assertOk()
            ->assertJsonPath('data.is_demo', true)
            ->assertJsonPath('data.pode_avaliar', false)
            ->assertJsonCount(0, 'data.projetos');

        $this->getJson('/api/v1/avaliacao?teste=1')
            ->assertOk()
            ->assertJsonPath('data.pode_avaliar', true)
            ->assertJsonCount(1, 'data.projetos');
    }

    public function test_permissoes_de_papel(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());
        $this->getJson('/api/v1/admin/avaliacao/config')->assertStatus(403);

        Sanctum::actingAs(User::factory()->create()); // orientador
        $this->getJson('/api/v1/avaliacao')->assertStatus(403);
    }
}
