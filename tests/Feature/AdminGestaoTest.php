<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGestaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lista_todos_os_administradores(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();
        User::factory()->admin()->create(['is_active' => false]);
        User::factory()->create(); // orientador — não entra na lista

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/admins')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.role', 'admin');
    }

    public function test_admin_edita_nome_e_email(): void
    {
        $admin = User::factory()->admin()->create();
        $alvo = User::factory()->admin()->create(['name' => 'Antigo', 'email' => 'antigo@fetec.test']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/admins/{$alvo->id}", [
            'name' => 'Novo Nome',
            'email' => 'novo@fetec.test',
        ])->assertOk()->assertJsonPath('data.email', 'novo@fetec.test');

        $this->assertDatabaseHas('users', ['id' => $alvo->id, 'name' => 'Novo Nome', 'email' => 'novo@fetec.test']);
    }

    public function test_email_duplicado_e_rejeitado(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'ocupado@fetec.test']);
        $alvo = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/admins/{$alvo->id}", [
            'name' => 'X',
            'email' => 'ocupado@fetec.test',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_manter_o_proprio_email_ao_editar_nao_conflita(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'eu@fetec.test']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/admins/{$admin->id}", [
            'name' => 'Eu Mesmo',
            'email' => 'eu@fetec.test', // mesmo e-mail
        ])->assertOk();
    }

    public function test_admin_desativa_e_reativa_outro_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $alvo = User::factory()->admin()->create(['is_active' => true]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/admins/{$alvo->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('users', ['id' => $alvo->id, 'is_active' => false]);

        $this->patchJson("/api/v1/admin/admins/{$alvo->id}/status", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_nao_pode_desativar_a_si_mesmo(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/admins/{$admin->id}/status", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    public function test_nao_pode_desativar_o_ultimo_admin_ativo(): void
    {
        // Autor inativo (via token) tentando desativar o único admin ativo restante.
        $autor = User::factory()->admin()->create(['is_active' => false]);
        $ultimo = User::factory()->admin()->create(['is_active' => true]);
        Sanctum::actingAs($autor);

        $this->patchJson("/api/v1/admin/admins/{$ultimo->id}/status", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    public function test_alvo_precisa_ser_administrador(): void
    {
        $admin = User::factory()->admin()->create();
        $orientador = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/admins/{$orientador->id}", ['name' => 'X', 'email' => 'x@y.test'])
            ->assertStatus(404);
    }

    public function test_nao_admin_nao_acessa_a_gestao(): void
    {
        Sanctum::actingAs(User::factory()->create()); // orientador

        $this->getJson('/api/v1/admin/admins')->assertStatus(403);
    }
}
