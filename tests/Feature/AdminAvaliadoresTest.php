<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AvaliadorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAvaliadoresTest extends TestCase
{
    use RefreshDatabase;

    private function avaliadorNaArea(int $areaId, bool $ativo): void
    {
        $user = User::factory()->avaliador()->create(['is_active' => $ativo]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);
    }

    public function test_metricas_de_avaliadores_totais_e_por_area(): void
    {
        $a = Area::create(['nome' => 'Área Alfa']);
        $b = Area::create(['nome' => 'Área Beta']);

        $this->avaliadorNaArea($a->id, true);
        $this->avaliadorNaArea($a->id, false);
        $this->avaliadorNaArea($b->id, true);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliadores')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.ativos', 2)
            ->assertJsonPath('data.inativos', 1)
            ->assertJsonCount(2, 'data.por_area')
            ->assertJsonPath('data.por_area.0.area', 'Área Alfa') // maior contagem primeiro
            ->assertJsonPath('data.por_area.0.total', 2)
            ->assertJsonPath('data.por_area.1.total', 1);
    }

    public function test_nao_admin_nao_acessa_avaliadores(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/avaliadores')->assertStatus(403);
    }
}
