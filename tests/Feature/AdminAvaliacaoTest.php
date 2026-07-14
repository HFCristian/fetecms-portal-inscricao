<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    private function avaliador(int $areaId, string $nome): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);

        return $user;
    }

    public function test_avaliadores_por_area_com_progresso(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);

        $ana = $this->avaliador($a->id, 'Ana');   // 2 concluídas + 1 em andamento
        $this->avaliador($a->id, 'Bruno');        // nada
        $carlos = $this->avaliador($b->id, 'Carlos'); // 3 concluídas

        $orient = User::factory()->create();
        $p1 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Projeto A1']);
        $p2 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Projeto A2']);
        $p3 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $b->id, 'titulo' => 'Projeto B1']);

        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $ana->id, 'status' => 'concluida', 'nota' => 8]);
        Avaliacao::create(['projeto_id' => $p2->id, 'avaliador_id' => $ana->id, 'status' => 'concluida', 'nota' => 9]);
        Avaliacao::create(['projeto_id' => $p3->id, 'avaliador_id' => $ana->id, 'status' => 'em_andamento']);
        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $carlos->id, 'status' => 'concluida', 'nota' => 7]);
        Avaliacao::create(['projeto_id' => $p2->id, 'avaliador_id' => $carlos->id, 'status' => 'concluida', 'nota' => 7]);
        Avaliacao::create(['projeto_id' => $p3->id, 'avaliador_id' => $carlos->id, 'status' => 'concluida', 'nota' => 7]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertOk()
            ->assertJsonPath('data.0.area', 'Área A')
            ->assertJsonPath('data.0.avaliadores.0.nome', 'Ana')
            ->assertJsonPath('data.0.avaliadores.0.em_avaliacao', 1)
            ->assertJsonPath('data.0.avaliadores.0.avaliou', 2)
            ->assertJsonPath('data.0.avaliadores.0.faltam', 1)
            ->assertJsonPath('data.0.avaliadores.1.nome', 'Bruno')
            ->assertJsonPath('data.0.avaliadores.1.avaliou', 0)
            ->assertJsonPath('data.0.avaliadores.1.faltam', 3)
            ->assertJsonPath('data.1.area', 'Área B')
            ->assertJsonPath('data.1.avaliadores.0.avaliou', 3)
            ->assertJsonPath('data.1.avaliadores.0.faltam', 0);
    }

    public function test_projetos_submetidos_por_area_com_avaliacoes_recebidas(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);

        $ana = $this->avaliador($a->id, 'Ana');
        $carlos = $this->avaliador($b->id, 'Carlos');

        $orient = User::factory()->create();
        $p1 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Projeto A1']);
        $p2 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $b->id, 'titulo' => 'Projeto B1']);
        // rascunho não deve aparecer
        Projeto::factory()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Rascunho']);

        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $ana->id, 'status' => 'concluida', 'nota' => 8]);
        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $carlos->id, 'status' => 'concluida', 'nota' => 9]);
        Avaliacao::create(['projeto_id' => $p2->id, 'avaliador_id' => $ana->id, 'status' => 'em_andamento']); // não conta

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/projetos')
            ->assertOk()
            ->assertJsonPath('data.0.area', 'Área A')
            ->assertJsonPath('data.0.projetos.0.titulo', 'Projeto A1')
            ->assertJsonPath('data.0.projetos.0.avaliacoes_recebidas', 2)
            ->assertJsonPath('data.1.area', 'Área B')
            ->assertJsonPath('data.1.projetos.0.avaliacoes_recebidas', 0);
    }

    public function test_nao_admin_nao_acessa_avaliacao_online(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')->assertStatus(403);
        $this->getJson('/api/v1/admin/avaliacao/projetos')->assertStatus(403);
    }
}
