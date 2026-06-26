<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlterarSenhaTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge([
            'current_password' => 'password', // padrão da UserFactory
            'password' => 'nova-senha-segura',
            'password_confirmation' => 'nova-senha-segura',
        ], $over);
    }

    public function test_usuario_altera_a_propria_senha(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/senha', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.message', 'Senha alterada com sucesso.');

        $this->assertTrue(Hash::check('nova-senha-segura', $user->fresh()->password));
    }

    public function test_avaliador_e_admin_tambem_podem(): void
    {
        foreach ([User::factory()->avaliador()->create(), User::factory()->admin()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->putJson('/api/v1/auth/senha', $this->payload())->assertOk();
            $this->assertTrue(Hash::check('nova-senha-segura', $user->fresh()->password));
        }
    }

    public function test_senha_atual_incorreta_e_rejeitada(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/senha', $this->payload(['current_password' => 'errada']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        // Senha não mudou.
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_nova_senha_curta_e_rejeitada(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/auth/senha', $this->payload(['password' => 'curta', 'password_confirmation' => 'curta']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_confirmacao_diferente_e_rejeitada(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/auth/senha', $this->payload(['password_confirmation' => 'confirmacao-diferente']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_exige_autenticacao(): void
    {
        $this->putJson('/api/v1/auth/senha', $this->payload())->assertStatus(401);
    }
}
