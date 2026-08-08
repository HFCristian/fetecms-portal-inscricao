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

    /**
     * Rubrica válida: 3 quesitos de 0 a 10 + comentários opcionais.
     *
     * @return array<string, mixed>
     */
    private function rubrica(int $video = 8, int $resumo = 7, int $pesquisa = 9): array
    {
        return [
            'nota_video' => $video,
            'comentario_video' => 'Bom ritmo, mas o áudio oscila.',
            'nota_resumo' => $resumo,
            'comentario_resumo' => null,
            'nota_pesquisa' => $pesquisa,
            'comentario_pesquisa' => 'Metodologia bem descrita.',
        ];
    }

    public function test_inicia_e_conclui_com_a_rubrica_somando_a_nota_final(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")
            ->assertOk()->assertJsonPath('data.status', 'em_andamento');

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->rubrica(8, 7, 9))
            ->assertOk()
            ->assertJsonPath('data.status', 'concluida')
            ->assertJsonPath('data.nota', 24) // 8 + 7 + 9
            ->assertJsonPath('data.nota_maxima', 30)
            ->assertJsonPath('data.nota_video', 8)
            ->assertJsonPath('data.comentario_video', 'Bom ritmo, mas o áudio oscila.')
            ->assertJsonPath('data.comentario_resumo', null);

        $this->assertDatabaseHas('avaliacoes', [
            'id' => $aval->id, 'status' => 'concluida', 'nota' => 24,
            'nota_video' => 8, 'nota_resumo' => 7, 'nota_pesquisa' => 9,
            'comentario_pesquisa' => 'Metodologia bem descrita.',
        ]);
    }

    public function test_a_nota_final_e_calculada_no_servidor_e_ignora_a_enviada(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->rubrica(1, 1, 1),
            'nota' => 30, // tentativa de forçar a nota final
        ])->assertOk()->assertJsonPath('data.nota', 3);
    }

    public function test_nota_zero_em_um_quesito_e_valida(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->rubrica(0, 10, 10))
            ->assertOk()
            ->assertJsonPath('data.nota', 20);
    }

    public function test_comentarios_sao_opcionais(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            'nota_video' => 5, 'nota_resumo' => 5, 'nota_pesquisa' => 5,
        ])->assertOk()->assertJsonPath('data.nota', 15);
    }

    public function test_comentario_em_branco_vira_nulo(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->rubrica(),
            'comentario_video' => '   ',
        ])->assertOk()->assertJsonPath('data.comentario_video', null);
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

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->rubrica())->assertStatus(422);
    }

    public function test_os_tres_quesitos_sao_obrigatorios(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nota_video', 'nota_resumo', 'nota_pesquisa']);
    }

    public function test_cada_quesito_precisa_estar_entre_0_e_10(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [...$this->rubrica(), 'nota_video' => 11])
            ->assertStatus(422)->assertJsonValidationErrors('nota_video');

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [...$this->rubrica(), 'nota_resumo' => -1])
            ->assertStatus(422)->assertJsonValidationErrors('nota_resumo');
    }

    public function test_a_avaliacao_segue_em_andamento_quando_a_rubrica_e_invalida(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [...$this->rubrica(), 'nota_video' => 11])
            ->assertStatus(422);

        $this->assertDatabaseHas('avaliacoes', [
            'id' => $aval->id, 'status' => 'em_andamento', 'nota' => null, 'nota_video' => null,
        ]);
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
