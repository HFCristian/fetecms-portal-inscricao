<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvaliadorPerfilTest extends TestCase
{
    use RefreshDatabase;

    private Area $exatas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
        $this->exatas = Area::create(['nome' => 'Ciências Exatas e da Terra']);
    }

    private function avaliador(string $nome = 'Ana', ?Area $area = null): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome]);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id,
            'area_id' => ($area ?? $this->exatas)->id,
        ]);

        return $user;
    }

    /** Avaliações concluídas de um avaliador (uma por projeto). */
    private function concluiu(User $avaliador, int $quantas): void
    {
        $orientador = User::factory()->create();

        for ($i = 0; $i < $quantas; $i++) {
            $projeto = Projeto::factory()->submetido()->create([
                'user_id' => $orientador->id, 'area_id' => $this->exatas->id,
            ]);
            Avaliacao::create([
                'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
                'status' => 'concluida', 'nota' => 8, 'concluida_em' => now(),
            ]);
        }
    }

    private function liberarAvaliacao(): void
    {
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);
    }

    // --- Estatísticas ---

    public function test_conta_as_avaliacoes_concluidas_e_a_carga_horaria_do_certificado(): void
    {
        $ana = $this->avaliador();
        $this->concluiu($ana, 3);
        Sanctum::actingAs($ana);

        // 3 × 2h30 = 7h30.
        $this->getJson('/api/v1/avaliador/perfil')
            ->assertOk()
            ->assertJsonPath('data.estatisticas.avaliacoes_concluidas', 3)
            ->assertJsonPath('data.estatisticas.certificado_minutos', 450)
            ->assertJsonPath('data.estatisticas.certificado_label', '7h30')
            ->assertJsonPath('data.estatisticas.por_avaliacao_label', '2h30');
    }

    public function test_avaliacao_em_andamento_nao_conta_no_certificado(): void
    {
        $ana = $this->avaliador();
        $this->concluiu($ana, 1);
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'area_id' => $this->exatas->id,
        ]);
        Avaliacao::create(['projeto_id' => $projeto->id, 'avaliador_id' => $ana->id, 'status' => 'em_andamento']);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/avaliador/perfil')
            ->assertOk()
            ->assertJsonPath('data.estatisticas.avaliacoes_concluidas', 1)
            ->assertJsonPath('data.estatisticas.certificado_label', '2h30')
            // O card de projetos designados conta tudo o que chegou para ele.
            ->assertJsonPath('data.projetos_designados', 2);
    }

    public function test_carga_horaria_em_hora_cheia_sai_sem_os_minutos(): void
    {
        $ana = $this->avaliador();
        $this->concluiu($ana, 2); // 2 × 2h30 = 5h
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/avaliador/perfil')
            ->assertOk()
            ->assertJsonPath('data.estatisticas.certificado_label', '5h');
    }

    public function test_posicao_no_ranking_de_quem_mais_avaliou(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $carla = $this->avaliador('Carla');
        $this->concluiu($bruno, 3);
        $this->concluiu($ana, 2);
        $this->concluiu($carla, 1);

        Sanctum::actingAs($ana);
        $this->getJson('/api/v1/avaliador/perfil')
            ->assertOk()
            ->assertJsonPath('data.estatisticas.posicao', 2)
            ->assertJsonPath('data.estatisticas.total_no_ranking', 3)
            ->assertJsonPath('data.estatisticas.empate', false);

        Sanctum::actingAs($bruno);
        $this->getJson('/api/v1/avaliador/perfil')->assertJsonPath('data.estatisticas.posicao', 1);
    }

    public function test_avaliadores_empatados_dividem_a_posicao(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $carla = $this->avaliador('Carla');
        $this->concluiu($ana, 2);
        $this->concluiu($bruno, 2);
        $this->concluiu($carla, 1);

        Sanctum::actingAs($ana);

        // Ana e Bruno são os dois primeiros; ninguém fica em 2º.
        $this->getJson('/api/v1/avaliador/perfil')
            ->assertOk()
            ->assertJsonPath('data.estatisticas.posicao', 1)
            ->assertJsonPath('data.estatisticas.empate', true);

        Sanctum::actingAs($carla);
        $this->getJson('/api/v1/avaliador/perfil')->assertJsonPath('data.estatisticas.posicao', 3);
    }

    public function test_quem_nao_avaliou_ainda_fica_fora_do_ranking(): void
    {
        $ana = $this->avaliador('Ana');
        $this->concluiu($this->avaliador('Bruno'), 1);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/avaliador/perfil')
            ->assertOk()
            ->assertJsonPath('data.estatisticas.avaliacoes_concluidas', 0)
            ->assertJsonPath('data.estatisticas.certificado_label', '0h')
            ->assertJsonPath('data.estatisticas.posicao', null)
            ->assertJsonPath('data.estatisticas.total_no_ranking', 1);
    }

    public function test_o_perfil_traz_os_dados_do_avaliador(): void
    {
        $ana = $this->avaliador();
        $subarea = Subarea::create(['area_id' => $this->exatas->id, 'nome' => 'Astronomia']);
        $ana->avaliadorProfile->update(['subarea_id' => $subarea->id, 'titulacao' => 'Mestrado (em andamento)']);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/avaliador/perfil')
            ->assertOk()
            ->assertJsonPath('data.nome', 'Ana')
            ->assertJsonPath('data.email', $ana->email)
            ->assertJsonPath('data.titulacao', 'Mestrado (em andamento)')
            ->assertJsonPath('data.area', 'Ciências Exatas e da Terra')
            ->assertJsonPath('data.subarea', 'Astronomia')
            ->assertJsonPath('data.max_por_avaliador', 3);
    }

    public function test_perfil_de_avaliador_e_so_do_avaliador(): void
    {
        Sanctum::actingAs(User::factory()->create()); // orientador
        $this->getJson('/api/v1/avaliador/perfil')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/avaliador/perfil')->assertForbidden();
    }

    // --- Troca de área/subárea ---

    public function test_troca_a_area_antes_do_periodo_de_avaliacao(): void
    {
        $ana = $this->avaliador();
        $bio = Area::create(['nome' => 'Ciências Biológicas']);
        $botanica = Subarea::create(['area_id' => $bio->id, 'nome' => 'Botânica']);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/avaliador/perfil')->assertJsonPath('data.pode_trocar_area', true);

        $this->putJson('/api/v1/avaliador/perfil/classificacao', [
            'area_id' => $bio->id, 'subarea_id' => $botanica->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.area', 'Ciências Biológicas')
            ->assertJsonPath('data.subarea', 'Botânica');

        $this->assertDatabaseHas('avaliador_profiles', [
            'user_id' => $ana->id, 'area_id' => $bio->id, 'subarea_id' => $botanica->id,
        ]);
    }

    public function test_trocar_de_area_sem_subarea_limpa_a_anterior(): void
    {
        $ana = $this->avaliador();
        $subarea = Subarea::create(['area_id' => $this->exatas->id, 'nome' => 'Astronomia']);
        $ana->avaliadorProfile->update(['subarea_id' => $subarea->id]);
        $bio = Area::create(['nome' => 'Ciências Biológicas']);
        Sanctum::actingAs($ana);

        // A subárea antiga era de outra área: não pode sobrar no perfil.
        $this->putJson('/api/v1/avaliador/perfil/classificacao', ['area_id' => $bio->id])
            ->assertOk()
            ->assertJsonPath('data.subarea', null);

        $this->assertDatabaseHas('avaliador_profiles', ['user_id' => $ana->id, 'subarea_id' => null]);
    }

    public function test_a_subarea_precisa_ser_da_area_escolhida(): void
    {
        $ana = $this->avaliador();
        $bio = Area::create(['nome' => 'Ciências Biológicas']);
        $astronomia = Subarea::create(['area_id' => $this->exatas->id, 'nome' => 'Astronomia']);
        Sanctum::actingAs($ana);

        $this->putJson('/api/v1/avaliador/perfil/classificacao', [
            'area_id' => $bio->id, 'subarea_id' => $astronomia->id,
        ])->assertStatus(422)->assertJsonValidationErrors('subarea_id');
    }

    public function test_a_area_e_obrigatoria_e_precisa_existir(): void
    {
        Sanctum::actingAs($this->avaliador());

        $this->putJson('/api/v1/avaliador/perfil/classificacao', [])
            ->assertStatus(422)->assertJsonValidationErrors('area_id');

        $this->putJson('/api/v1/avaliador/perfil/classificacao', ['area_id' => 99999])
            ->assertStatus(422)->assertJsonValidationErrors('area_id');
    }

    public function test_nao_troca_a_area_depois_que_a_avaliacao_foi_liberada(): void
    {
        $ana = $this->avaliador();
        $bio = Area::create(['nome' => 'Ciências Biológicas']);
        $this->liberarAvaliacao();
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/avaliador/perfil')->assertJsonPath('data.pode_trocar_area', false);

        $this->putJson('/api/v1/avaliador/perfil/classificacao', ['area_id' => $bio->id])
            ->assertStatus(422)->assertJsonValidationErrors('area_id');

        // Continua na área de antes.
        $this->assertDatabaseHas('avaliador_profiles', [
            'user_id' => $ana->id, 'area_id' => $this->exatas->id,
        ]);
    }

    public function test_avaliador_demo_tambem_trava_apos_a_liberacao(): void
    {
        $ana = $this->avaliador();
        $ana->update(['is_demo' => true]);
        $this->liberarAvaliacao();
        Sanctum::actingAs($ana);

        // O modo teste adianta a avaliação, não a troca de área.
        $this->putJson('/api/v1/avaliador/perfil/classificacao', [
            'area_id' => Area::create(['nome' => 'Ciências Biológicas'])->id,
        ])->assertStatus(422);
    }

    public function test_orientador_nao_troca_area_de_avaliador(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/avaliador/perfil/classificacao', ['area_id' => $this->exatas->id])
            ->assertForbidden();
    }
}
