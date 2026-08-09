<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrosPtBrTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_autenticado_em_pt_br(): void
    {
        $this->getJson('/api/v1/projetos')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Não autenticado. Faça login para continuar.');
    }

    public function test_rota_inexistente_em_pt_br(): void
    {
        $this->getJson('/api/v1/rota-inexistente')
            ->assertNotFound()
            ->assertJsonPath('message', 'Recurso não encontrado.');
    }

    public function test_acesso_por_papel_negado_em_pt_br(): void
    {
        Sanctum::actingAs(User::factory()->create()); // orientador

        $this->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'Acesso restrito a este papel.');
    }

    public function test_excesso_de_tentativas_de_login_em_pt_br(): void
    {
        // Relógio congelado: sem isso a espera cai para 59s no meio do segundo e
        // o texto humanizado ("1 minuto") oscila.
        $this->freezeTime();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'quem@exemplo.com', 'password' => 'errada']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'quem@exemplo.com', 'password' => 'errada'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Tentativas de login em excesso. Tente novamente em 1 minuto.');
    }

    /** Rotas com `throttle` (sem mensagem própria) também respondem em pt_BR. */
    public function test_excesso_de_requisicoes_em_pt_br(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/esqueci-senha', ['email' => 'quem@exemplo.com']);
        }

        $resposta = $this->postJson('/api/v1/auth/esqueci-senha', ['email' => 'quem@exemplo.com'])
            ->assertStatus(429);

        $this->assertStringStartsWith('Muitas requisições em pouco tempo. Tente novamente em ', $resposta->json('message'));
        $this->assertGreaterThan(0, $resposta->json('retry_after'));
    }
}
