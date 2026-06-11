<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogoAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function projetoUsando(Area $area, ?Subarea $sub = null): Projeto
    {
        $orient = User::factory()->create(['role' => Role::Orientador]);

        return Projeto::factory()->create([
            'user_id' => $orient->id,
            'area_id' => $area->id,
            'subarea_id' => $sub?->id,
        ]);
    }

    public function test_nao_admin_nao_acessa_parametrizacao(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador]));

        $this->getJson('/api/v1/admin/catalogo')->assertForbidden();
    }

    public function test_admin_ve_arvore_com_usos(): void
    {
        $area = Area::create(['nome' => 'Engenharias']);
        $sub = $area->subareas()->create(['nome' => 'Mecatrônica']);
        $this->projetoUsando($area, $sub);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/catalogo')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Engenharias')
            ->assertJsonPath('data.0.usos', 1)
            ->assertJsonPath('data.0.subareas.0.nome', 'Mecatrônica')
            ->assertJsonPath('data.0.subareas.0.usos', 1);
    }

    public function test_admin_renomeia_area(): void
    {
        $area = Area::create(['nome' => 'Engenharia']);
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/admin/areas/{$area->id}", ['nome' => 'Engenharias'])
            ->assertOk()
            ->assertJsonPath('meta.message', 'Área renomeada.');

        $this->assertDatabaseHas('areas', ['id' => $area->id, 'nome' => 'Engenharias']);
    }

    public function test_mesclar_subareas_reatribui_referencias(): void
    {
        $area = Area::create(['nome' => 'Ciências Biológicas']);
        $origem = $area->subareas()->create(['nome' => 'Genetica']);
        $destino = $area->subareas()->create(['nome' => 'Genética']);
        $proj = $this->projetoUsando($area, $origem);

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/subareas/{$origem->id}/mesclar", ['destino_id' => $destino->id])
            ->assertOk()
            ->assertJsonPath('meta.message', 'Subáreas mescladas.');

        $this->assertDatabaseMissing('subareas', ['id' => $origem->id]);
        $this->assertDatabaseHas('projetos', ['id' => $proj->id, 'subarea_id' => $destino->id]);
    }

    public function test_nao_mescla_subarea_de_outra_area(): void
    {
        $bio = Area::create(['nome' => 'Biológicas']);
        $eng = Area::create(['nome' => 'Engenharias']);
        $subBio = $bio->subareas()->create(['nome' => 'Genética']);
        $subEng = $eng->subareas()->create(['nome' => 'Robótica']);

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/subareas/{$subBio->id}/mesclar", ['destino_id' => $subEng->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destino_id');
    }

    public function test_mesclar_areas_move_subareas_e_referencias(): void
    {
        $origem = Area::create(['nome' => 'Eng. Antiga']);
        $destino = Area::create(['nome' => 'Engenharias']);
        $subO = $origem->subareas()->create(['nome' => 'Robótica']);
        $proj = $this->projetoUsando($origem, $subO);

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/areas/{$origem->id}/mesclar", ['destino_id' => $destino->id])->assertOk();

        $this->assertDatabaseMissing('areas', ['id' => $origem->id]);
        $this->assertDatabaseHas('subareas', ['id' => $subO->id, 'area_id' => $destino->id]);
        $this->assertDatabaseHas('projetos', ['id' => $proj->id, 'area_id' => $destino->id, 'subarea_id' => $subO->id]);
    }

    public function test_mesclar_areas_funde_subareas_homonimas(): void
    {
        $origem = Area::create(['nome' => 'A']);
        $destino = Area::create(['nome' => 'B']);
        $subO = $origem->subareas()->create(['nome' => 'Genética']);
        $subD = $destino->subareas()->create(['nome' => 'Genética']);
        $proj = $this->projetoUsando($origem, $subO);

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/areas/{$origem->id}/mesclar", ['destino_id' => $destino->id])->assertOk();

        $this->assertDatabaseMissing('subareas', ['id' => $subO->id]);
        $this->assertDatabaseHas('projetos', ['id' => $proj->id, 'subarea_id' => $subD->id]);
        $this->assertSame(1, Subarea::where('area_id', $destino->id)->where('nome', 'Genética')->count());
    }

    public function test_nao_exclui_subarea_em_uso(): void
    {
        $area = Area::create(['nome' => 'Ciências da Saúde']);
        $sub = $area->subareas()->create(['nome' => 'Enfermagem']);
        $this->projetoUsando($area, $sub);

        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/admin/subareas/{$sub->id}")->assertStatus(422);
        $this->assertDatabaseHas('subareas', ['id' => $sub->id]);
    }

    public function test_exclui_subarea_sem_uso(): void
    {
        $area = Area::create(['nome' => 'Ciências da Saúde']);
        $sub = $area->subareas()->create(['nome' => 'Fisioterapia']);

        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/admin/subareas/{$sub->id}")->assertOk();
        $this->assertDatabaseMissing('subareas', ['id' => $sub->id]);
    }

    public function test_nao_exclui_area_com_subareas(): void
    {
        $area = Area::create(['nome' => 'Ciências Humanas']);
        $area->subareas()->create(['nome' => 'História']);

        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/admin/areas/{$area->id}")->assertStatus(422);
        $this->assertDatabaseHas('areas', ['id' => $area->id]);
    }
}
