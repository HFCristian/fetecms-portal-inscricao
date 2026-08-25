<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Cidade;
use App\Models\Estado;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ranking dos avaliadores (aba "Avaliação online"): quem mais concluiu, com
 * área, projetos em avaliação e de onde a pessoa é.
 */
class RankingAvaliadoresTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->area = Area::create(['nome' => 'Ciências Exatas']);
    }

    private function avaliador(string $nome, array $perfil = [], array $over = []): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome, ...$over]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $this->area->id, ...$perfil]);

        return $user;
    }

    private function avaliacoes(User $avaliador, int $concluidas, int $emAndamento = 0): void
    {
        $orientador = User::factory()->create();

        foreach (range(1, max($concluidas, 0)) as $i) {
            $projeto = Projeto::factory()->submetido()->create(['user_id' => $orientador->id, 'area_id' => $this->area->id]);
            Avaliacao::create(['projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id, 'status' => 'concluida', 'nota' => 8]);
        }

        foreach (range(1, max($emAndamento, 0)) as $i) {
            $projeto = Projeto::factory()->submetido()->create(['user_id' => $orientador->id, 'area_id' => $this->area->id]);
            Avaliacao::create(['projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id, 'status' => 'em_andamento']);
        }
    }

    public function test_ranking_traz_nome_area_numeros_e_localidade(): void
    {
        $ms = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $campoGrande = Cidade::create(['estado_id' => $ms->id, 'nome' => 'Campo Grande']);

        $lider = $this->avaliador('Zilda', ['estado_id' => $ms->id, 'cidade_id' => $campoGrande->id]);
        $segundo = $this->avaliador('Ana');
        $this->avaliador('Sem avaliação'); // não entra no ranking

        $this->avaliacoes($lider, 3, 1);
        $this->avaliacoes($segundo, 1);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/ranking-avaliadores')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nome', 'Zilda')
            ->assertJsonPath('data.0.posicao', 1)
            ->assertJsonPath('data.0.concluidas', 3)
            ->assertJsonPath('data.0.em_avaliacao', 1)
            ->assertJsonPath('data.0.area', 'Ciências Exatas')
            ->assertJsonPath('data.0.estado', 'MS')
            ->assertJsonPath('data.0.cidade', 'Campo Grande')
            ->assertJsonPath('data.1.nome', 'Ana')
            ->assertJsonPath('data.1.posicao', 2)
            ->assertJsonPath('data.1.cidade', null);
    }

    public function test_empate_divide_a_posicao(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $carla = $this->avaliador('Carla');

        $this->avaliacoes($ana, 2);
        $this->avaliacoes($bruno, 2);
        $this->avaliacoes($carla, 1);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/ranking-avaliadores')
            ->assertOk()
            // Dois em 1º, ninguém em 2º: o terceiro cai para a 3ª posição.
            ->assertJsonPath('data.0.posicao', 1)
            ->assertJsonPath('data.1.posicao', 1)
            ->assertJsonPath('data.2.nome', 'Carla')
            ->assertJsonPath('data.2.posicao', 3);
    }

    public function test_ranking_ignora_avaliador_demo(): void
    {
        $demo = $this->avaliador('Demo', [], ['is_demo' => true]);
        $this->avaliacoes($demo, 5);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/ranking-avaliadores')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_ranking_e_so_do_admin(): void
    {
        Sanctum::actingAs($this->avaliador('Ana'));

        $this->getJson('/api/v1/admin/avaliacao/ranking-avaliadores')->assertForbidden();
    }

    public function test_avaliador_informa_e_troca_a_propria_localidade(): void
    {
        $ms = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $dourados = Cidade::create(['estado_id' => $ms->id, 'nome' => 'Dourados']);
        $sp = Estado::create(['nome' => 'São Paulo', 'uf' => 'SP']);

        $avaliador = $this->avaliador('Ana');
        Sanctum::actingAs($avaliador);

        $this->putJson('/api/v1/avaliador/perfil/localidade', [
            'estado_id' => $ms->id, 'cidade_id' => $dourados->id,
        ])->assertOk()
            ->assertJsonPath('data.estado', 'Mato Grosso do Sul')
            ->assertJsonPath('data.cidade', 'Dourados');

        // Cidade de outro estado é recusada.
        $this->putJson('/api/v1/avaliador/perfil/localidade', [
            'estado_id' => $sp->id, 'cidade_id' => $dourados->id,
        ])->assertStatus(422)->assertJsonValidationErrors('cidade_id');

        // Limpar o estado limpa a cidade junto.
        $this->putJson('/api/v1/avaliador/perfil/localidade', ['estado_id' => null, 'cidade_id' => null])
            ->assertOk()
            ->assertJsonPath('data.estado_id', null)
            ->assertJsonPath('data.cidade_id', null);
    }

    public function test_cadastro_do_avaliador_aceita_localidade_opcional(): void
    {
        $ms = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $cidade = Cidade::create(['estado_id' => $ms->id, 'nome' => 'Três Lagoas']);

        $this->postJson('/api/v1/avaliadores', [
            'name' => 'Nova Avaliadora',
            'email' => 'nova@avaliadores.test',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '52998224725',
            'titulacao' => 'Mestrado (em andamento)',
            'area_id' => $this->area->id,
            'estado_id' => $ms->id,
            'cidade_id' => $cidade->id,
        ])->assertCreated();

        $this->assertDatabaseHas('avaliador_profiles', [
            'estado_id' => $ms->id, 'cidade_id' => $cidade->id,
        ]);
    }
}
