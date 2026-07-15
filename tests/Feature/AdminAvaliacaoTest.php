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

    public function test_projetos_submetidos_por_area_com_metricas(): void
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
        Avaliacao::create(['projeto_id' => $p2->id, 'avaliador_id' => $ana->id, 'status' => 'em_andamento']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/projetos')
            ->assertOk()
            ->assertJsonPath('data.0.area', 'Área A')
            ->assertJsonPath('data.0.projetos.0.titulo', 'Projeto A1')
            ->assertJsonPath('data.0.projetos.0.realizadas', 2)
            ->assertJsonPath('data.0.projetos.0.em_avaliacao', 0)
            ->assertJsonPath('data.0.projetos.0.faltantes', 1)
            ->assertJsonPath('data.1.area', 'Área B')
            ->assertJsonPath('data.1.projetos.0.realizadas', 0)
            ->assertJsonPath('data.1.projetos.0.em_avaliacao', 1)
            ->assertJsonPath('data.1.projetos.0.faltantes', 3);
    }

    public function test_admin_designa_projeto_a_avaliador_especifico(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');
        $proj = Projeto::factory()->submetido()->create(['user_id' => User::factory()->create()->id, 'area_id' => $a->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$proj->id}/designar", [
            'tipo' => 'avaliador', 'alvo_id' => $ana->id,
        ])->assertOk()->assertJsonPath('data.designadas', 1);

        $this->assertDatabaseHas('avaliacoes', [
            'projeto_id' => $proj->id, 'avaliador_id' => $ana->id, 'status' => 'designada',
        ]);

        // Idempotente: designar de novo não duplica.
        $this->postJson("/api/v1/admin/avaliacao/projetos/{$proj->id}/designar", [
            'tipo' => 'avaliador', 'alvo_id' => $ana->id,
        ])->assertOk()->assertJsonPath('data.designadas', 0);
    }

    public function test_admin_designa_projeto_a_todos_de_uma_area(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);
        $this->avaliador($a->id, 'Ana');
        $this->avaliador($a->id, 'Bruno');
        $this->avaliador($b->id, 'Carlos'); // outra área: não designa

        $proj = Projeto::factory()->submetido()->create(['user_id' => User::factory()->create()->id, 'area_id' => $a->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$proj->id}/designar", [
            'tipo' => 'area', 'alvo_id' => $a->id,
        ])->assertOk()->assertJsonPath('data.designadas', 2);

        $this->assertSame(2, Avaliacao::where('projeto_id', $proj->id)->count());
    }

    public function test_nao_designa_projeto_nao_submetido(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');
        $rascunho = Projeto::factory()->create(['user_id' => User::factory()->create()->id, 'area_id' => $a->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$rascunho->id}/designar", [
            'tipo' => 'avaliador', 'alvo_id' => $ana->id,
        ])->assertStatus(422);
    }

    public function test_avaliador_comeca_sem_limite(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id, 'Ana');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertOk()
            ->assertJsonPath('data.0.avaliadores.0.limite', null);
    }

    public function test_admin_define_e_remove_limite_do_avaliador(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$ana->id}/limite", ['limite' => 2])
            ->assertOk()
            ->assertJsonPath('data.limite', 2);
        $this->assertDatabaseHas('avaliador_profiles', ['user_id' => $ana->id, 'limite_avaliacoes' => 2]);

        // A lista passa a exibir o limite.
        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertJsonPath('data.0.avaliadores.0.limite', 2);

        // Remover o limite (null).
        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$ana->id}/limite", ['limite' => null])
            ->assertOk()
            ->assertJsonPath('data.limite', null);
        $this->assertDatabaseHas('avaliador_profiles', ['user_id' => $ana->id, 'limite_avaliacoes' => null]);
    }

    public function test_limitar_exige_que_o_alvo_seja_avaliador(): void
    {
        $orientador = User::factory()->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$orientador->id}/limite", ['limite' => 2])
            ->assertStatus(404);
    }

    public function test_atingiu_limite_considera_as_assumidas(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');
        $perfil = $ana->avaliadorProfile;

        $perfil->update(['limite_avaliacoes' => 2]);
        $this->assertTrue($perfil->atingiuLimite(2));   // 2 assumidas >= limite 2
        $this->assertFalse($perfil->atingiuLimite(1));  // ainda pode assumir

        $perfil->update(['limite_avaliacoes' => null]); // sem limite nunca bloqueia
        $this->assertFalse($perfil->atingiuLimite(99));
    }

    public function test_marca_avaliador_como_demo_e_a_lista_reflete(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertJsonPath('data.0.avaliadores.0.is_demo', false);

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$ana->id}/demo", ['is_demo' => true])
            ->assertOk()
            ->assertJsonPath('data.is_demo', true);
        $this->assertDatabaseHas('users', ['id' => $ana->id, 'is_demo' => true]);

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertJsonPath('data.0.avaliadores.0.is_demo', true);
    }

    public function test_limpar_dados_de_teste_apaga_so_dos_demo(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $demo = $this->avaliador($a->id, 'Demo');
        $demo->update(['is_demo' => true]);
        $real = $this->avaliador($a->id, 'Real');

        $orient = User::factory()->create();
        $p = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id]);
        Avaliacao::create(['projeto_id' => $p->id, 'avaliador_id' => $demo->id, 'status' => 'concluida', 'nota' => 8]);
        Avaliacao::create(['projeto_id' => $p->id, 'avaliador_id' => $real->id, 'status' => 'concluida', 'nota' => 9]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson('/api/v1/admin/avaliacao/testes')
            ->assertOk()
            ->assertJsonPath('data.apagadas', 1);

        $this->assertDatabaseMissing('avaliacoes', ['avaliador_id' => $demo->id]);
        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $real->id]);
    }

    public function test_demo_exige_que_o_alvo_seja_avaliador(): void
    {
        $orientador = User::factory()->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$orientador->id}/demo", ['is_demo' => true])
            ->assertStatus(404);
    }

    public function test_nao_admin_nao_acessa_avaliacao_online(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')->assertStatus(403);
        $this->getJson('/api/v1/admin/avaliacao/projetos')->assertStatus(403);
    }
}
