<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Aluno;
use App\Models\Instituicao;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstituicaoAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    public function test_nao_admin_nao_acessa_parametrizacao_de_escolas(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador]));

        $this->getJson('/api/v1/admin/instituicoes')->assertForbidden();
    }

    public function test_admin_busca_instituicoes_com_usos(): void
    {
        $inst = Instituicao::create(['nome' => 'Escola Estadual Modelo']);
        $orient = User::factory()->create(['role' => Role::Orientador]);
        Projeto::factory()->create(['user_id' => $orient->id, 'instituicao_id' => $inst->id]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/instituicoes?search=Modelo')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Escola Estadual Modelo')
            ->assertJsonPath('data.0.usos', 1);
    }

    public function test_admin_renomeia_instituicao(): void
    {
        $inst = Instituicao::create(['nome' => 'Escola  Mal  Escrita ']);
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/admin/instituicoes/{$inst->id}", ['nome' => 'Escola Bem Escrita'])
            ->assertOk()
            ->assertJsonPath('meta.message', 'Instituição renomeada.');

        $this->assertDatabaseHas('instituicoes', ['id' => $inst->id, 'nome' => 'Escola Bem Escrita']);
    }

    public function test_mesclar_instituicoes_reatribui_referencias_de_projeto_aluno_e_orientador(): void
    {
        $origem = Instituicao::create(['nome' => 'Escola Joao XXIII']);
        $destino = Instituicao::create(['nome' => 'Escola João XXIII']);

        $orient = User::factory()->create(['role' => Role::Orientador]);
        $perfil = OrientadorProfile::factory()->create(['user_id' => $orient->id, 'instituicao_id' => $origem->id]);
        $projeto = Projeto::factory()->create(['user_id' => $orient->id, 'instituicao_id' => $origem->id]);
        $aluno = Aluno::factory()->create(['projeto_id' => $projeto->id, 'instituicao_id' => $origem->id]);

        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/instituicoes/{$origem->id}/mesclar", ['destino_id' => $destino->id])
            ->assertOk()
            ->assertJsonPath('meta.message', 'Instituições mescladas.');

        $this->assertDatabaseMissing('instituicoes', ['id' => $origem->id]);
        $this->assertDatabaseHas('projetos', ['id' => $projeto->id, 'instituicao_id' => $destino->id]);
        $this->assertDatabaseHas('alunos', ['id' => $aluno->id, 'instituicao_id' => $destino->id]);
        $this->assertDatabaseHas('orientador_profiles', ['id' => $perfil->id, 'instituicao_id' => $destino->id]);
    }

    public function test_nao_mescla_instituicao_em_si_mesma(): void
    {
        $inst = Instituicao::create(['nome' => 'Escola Única']);
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/v1/admin/instituicoes/{$inst->id}/mesclar", ['destino_id' => $inst->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destino_id');
    }

    public function test_nao_exclui_instituicao_em_uso(): void
    {
        $inst = Instituicao::create(['nome' => 'Escola Em Uso']);
        $orient = User::factory()->create(['role' => Role::Orientador]);
        Projeto::factory()->create(['user_id' => $orient->id, 'instituicao_id' => $inst->id]);

        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/admin/instituicoes/{$inst->id}")->assertStatus(422);
        $this->assertDatabaseHas('instituicoes', ['id' => $inst->id]);
    }

    public function test_exclui_instituicao_sem_uso(): void
    {
        $inst = Instituicao::create(['nome' => 'Escola Sem Uso']);
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/admin/instituicoes/{$inst->id}")
            ->assertOk()
            ->assertJsonPath('meta.message', 'Instituição excluída.');
        $this->assertDatabaseMissing('instituicoes', ['id' => $inst->id]);
    }
}
