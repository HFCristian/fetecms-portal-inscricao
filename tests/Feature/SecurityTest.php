<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_respostas_trazem_cabecalhos_de_seguranca(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_visitante_e_bloqueado_em_rota_protegida(): void
    {
        $this->getJson('/api/v1/projetos')->assertUnauthorized();
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_avaliador_nao_acessa_area_do_orientador(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->getJson('/api/v1/projetos')->assertForbidden();
    }

    public function test_orientador_nao_acessa_area_do_admin(): void
    {
        Sanctum::actingAs(User::factory()->create()); // orientador

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }
}
