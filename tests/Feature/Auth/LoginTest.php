<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_com_credenciais_validas(): void
    {
        $user = User::factory()->create(['email' => 'a@b.com']); // senha padrão "password"

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@b.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.email', 'a@b.com');
    }

    public function test_login_com_senha_errada_falha(): void
    {
        User::factory()->create(['email' => 'a@b.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@b.com',
            'password' => 'errada',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_conta_inativa_nao_loga(): void
    {
        User::factory()->create(['email' => 'a@b.com', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'a@b.com',
            'password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_me_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_retorna_usuario_autenticado(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_logout_funciona(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/auth/logout')->assertOk();
    }
}
