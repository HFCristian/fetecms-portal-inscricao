<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

    /** Falha de login que não bloqueia (ainda dentro do limite). */
    private function tentarComSenhaErrada(string $email = 'a@b.com'): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'errada']);
    }

    public function test_bloqueia_apos_tentativas_seguidas_e_informa_o_tempo_de_espera(): void
    {
        User::factory()->create(['email' => 'a@b.com']);

        for ($i = 0; $i < AuthService::MAX_TENTATIVAS; $i++) {
            $this->tentarComSenhaErrada()->assertStatus(422);
        }

        $resposta = $this->tentarComSenhaErrada()->assertStatus(429);

        // O front usa `retry_after` para a contagem regressiva; o header fica
        // preservado para clientes HTTP (mobile) e proxies.
        $this->assertGreaterThan(0, $resposta->json('retry_after'));
        $this->assertLessThanOrEqual(AuthService::BLOQUEIO_SEGUNDOS, $resposta->json('retry_after'));
        $resposta->assertHeader('Retry-After');
        $this->assertStringContainsString('Tentativas de login em excesso', $resposta->json('message'));
    }

    public function test_bloqueio_barra_ate_a_senha_correta(): void
    {
        User::factory()->create(['email' => 'a@b.com']);

        for ($i = 0; $i < AuthService::MAX_TENTATIVAS; $i++) {
            $this->tentarComSenhaErrada();
        }

        // Bloqueado é bloqueado: nem a senha certa passa enquanto durar a espera.
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.com', 'password' => 'password'])
            ->assertStatus(429);
    }

    public function test_login_valido_zera_o_contador_de_tentativas(): void
    {
        User::factory()->create(['email' => 'a@b.com']);

        for ($i = 0; $i < AuthService::MAX_TENTATIVAS - 1; $i++) {
            $this->tentarComSenhaErrada()->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.com', 'password' => 'password'])->assertOk();

        // Contador zerado: as falhas seguintes recomeçam do zero, sem bloquear.
        for ($i = 0; $i < AuthService::MAX_TENTATIVAS - 1; $i++) {
            $this->tentarComSenhaErrada()->assertStatus(422);
        }
    }

    public function test_bloqueio_e_por_email_nao_derruba_outro_usuario_do_mesmo_ip(): void
    {
        User::factory()->create(['email' => 'a@b.com']);
        User::factory()->create(['email' => 'outro@b.com']);

        for ($i = 0; $i < AuthService::MAX_TENTATIVAS; $i++) {
            $this->tentarComSenhaErrada('a@b.com');
        }

        $this->tentarComSenhaErrada('a@b.com')->assertStatus(429);

        // Mesmo IP (escola com NAT), outra conta: segue entrando normalmente.
        $this->postJson('/api/v1/auth/login', ['email' => 'outro@b.com', 'password' => 'password'])
            ->assertOk();
    }
}
