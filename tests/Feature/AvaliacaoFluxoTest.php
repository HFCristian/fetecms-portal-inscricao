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

class AvaliacaoFluxoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    /** @return array{0: User, 1: Avaliacao} */
    private function cenario(bool $liberada = true, bool $demo = false): array
    {
        $area = Area::create(['nome' => 'Área A']);
        $av = User::factory()->avaliador()->create(['is_demo' => $demo]);
        AvaliadorProfile::factory()->create(['user_id' => $av->id, 'area_id' => $area->id]);
        $proj = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'area_id' => $area->id, 'titulo' => 'Projeto X',
        ]);
        $aval = Avaliacao::create(['projeto_id' => $proj->id, 'avaliador_id' => $av->id, 'status' => 'designada']);

        if ($liberada) {
            Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);
        }

        return [$av, $aval];
    }

    public function test_inicia_e_conclui_com_nota(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")
            ->assertOk()->assertJsonPath('data.status', 'em_andamento');

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", ['nota' => 8])
            ->assertOk()
            ->assertJsonPath('data.status', 'concluida')
            ->assertJsonPath('data.nota', 8);

        $this->assertDatabaseHas('avaliacoes', ['id' => $aval->id, 'status' => 'concluida', 'nota' => 8]);
    }

    public function test_show_traz_os_detalhes_do_projeto(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->getJson("/api/v1/avaliacao/{$aval->id}")
            ->assertOk()
            ->assertJsonPath('data.projeto.titulo', 'Projeto X')
            ->assertJsonPath('data.avaliacao.status', 'designada');
    }

    public function test_nao_conclui_sem_iniciar(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", ['nota' => 8])->assertStatus(422);
    }

    public function test_nota_precisa_estar_entre_1_e_10(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", ['nota' => 11])->assertStatus(422);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", ['nota' => 0])->assertStatus(422);
    }

    public function test_apenas_uma_avaliacao_em_andamento_por_vez(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $av = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $av->id, 'area_id' => $area->id]);
        $orient = User::factory()->create();
        $a1 = Avaliacao::create(['projeto_id' => Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $area->id])->id, 'avaliador_id' => $av->id, 'status' => 'designada']);
        $a2 = Avaliacao::create(['projeto_id' => Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $area->id])->id, 'avaliador_id' => $av->id, 'status' => 'designada']);
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);

        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$a1->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/avaliacao/{$a2->id}/iniciar")->assertStatus(422);
    }

    public function test_nao_avalia_antes_da_liberacao(): void
    {
        [$av, $aval] = $this->cenario(liberada: false);
        Sanctum::actingAs($av);

        $this->getJson("/api/v1/avaliacao/{$aval->id}")->assertStatus(403);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertStatus(403);
    }

    public function test_demo_em_modo_teste_ignora_a_data(): void
    {
        [$av, $aval] = $this->cenario(liberada: false, demo: true);
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar?teste=1")
            ->assertOk()
            ->assertJsonPath('data.status', 'em_andamento');
    }

    public function test_avaliador_real_nao_ignora_a_data_mesmo_com_teste(): void
    {
        [$av, $aval] = $this->cenario(liberada: false, demo: false);
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar?teste=1")->assertStatus(403);
    }

    public function test_nao_acessa_avaliacao_de_outro_avaliador(): void
    {
        [, $aval] = $this->cenario();
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->getJson("/api/v1/avaliacao/{$aval->id}")->assertStatus(403);
    }
}
