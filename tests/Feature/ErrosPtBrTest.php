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

    public function test_excesso_de_tentativas_em_pt_br(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'quem@exemplo.com', 'password' => 'errada']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'quem@exemplo.com', 'password' => 'errada'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Muitas requisições em pouco tempo. Aguarde um instante e tente novamente.');
    }
}
