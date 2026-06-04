<?php

namespace Tests\Feature;

use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    public function test_categorias_retorna_as_tres_com_limite_de_alunos(): void
    {
        $this->getJson('/api/v1/catalogos/categorias')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['value' => 'fetec_jr', 'max_alunos' => 4])
            ->assertJsonFragment(['value' => 'fetecms', 'max_alunos' => 3]);
    }

    public function test_areas_e_subareas_filtradas_por_area(): void
    {
        $areas = $this->getJson('/api/v1/catalogos/areas')->assertOk()->json('data');
        $this->assertNotEmpty($areas);

        $areaId = $areas[0]['id'];
        $this->getJson("/api/v1/catalogos/subareas?area_id={$areaId}")
            ->assertOk()
            ->assertJsonPath('data.0.area_id', $areaId);
    }

    public function test_estados_e_cidades_filtradas_por_estado(): void
    {
        $this->getJson('/api/v1/catalogos/estados')->assertOk()->assertJsonFragment(['uf' => 'MS']);

        $msId = collect($this->getJson('/api/v1/catalogos/estados')->json('data'))
            ->firstWhere('uf', 'MS')['id'];

        $this->getJson("/api/v1/catalogos/cidades?estado_id={$msId}")
            ->assertOk()
            ->assertJsonFragment(['nome' => 'Campo Grande']);
    }

    public function test_instituicoes_com_busca(): void
    {
        $this->getJson('/api/v1/catalogos/instituicoes?search=IFMS')
            ->assertOk()
            ->assertJsonFragment(['nome' => 'IFMS Campus Três Lagoas']);
    }
}
