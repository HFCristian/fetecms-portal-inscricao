<?php

namespace Tests\Feature;

use App\Models\OrientadorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

    private function orientadorComPerfil(): User
    {
        return User::factory()
            ->has(OrientadorProfile::factory(), 'orientadorProfile')
            ->create();
    }

    public function test_orientador_ve_o_proprio_perfil(): void
    {
        $user = $this->orientadorComPerfil();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/perfil')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.orientador_profile.cpf', $user->orientadorProfile->cpf);
    }

    public function test_orientador_atualiza_o_proprio_perfil(): void
    {
        $user = $this->orientadorComPerfil();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/perfil', [
            'name' => 'Nome Atualizado',
            'cidade' => 'Dourados',
        ])->assertOk()->assertJsonPath('data.name', 'Nome Atualizado');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nome Atualizado']);
        $this->assertDatabaseHas('orientador_profiles', [
            'user_id' => $user->id,
            'cidade' => 'Dourados',
        ]);
    }

    public function test_avaliador_nao_acessa_perfil_de_orientador(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->getJson('/api/v1/perfil')->assertForbidden();
    }

    public function test_visitante_nao_acessa_perfil(): void
    {
        $this->getJson('/api/v1/perfil')->assertUnauthorized();
    }
}
