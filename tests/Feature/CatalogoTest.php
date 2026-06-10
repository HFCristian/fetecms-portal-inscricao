<?php

namespace Tests\Feature;

use App\Models\PalavraChave;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_busca_palavras_chave_globais(): void
    {
        PalavraChave::create(['texto' => 'Biotecnologia']);
        PalavraChave::create(['texto' => 'Energia Solar']);

        $this->getJson('/api/v1/catalogos/palavras-chave?search=Energia')
            ->assertOk()
            ->assertJsonFragment(['Energia Solar'])
            ->assertJsonMissing(['Biotecnologia']);
    }

    /**
     * Regressão: com o cache `database`, cachear a Collection do Eloquent gerava
     * __PHP_Incomplete_Class na releitura (data virava objeto, não array).
     * Aqui forçamos miss + hit e exigimos um array nas duas.
     */
    public function test_areas_continuam_array_com_cache_database(): void
    {
        config(['cache.default' => 'database']);
        Cache::store('database')->forget('catalogo.areas');

        $this->getJson('/api/v1/catalogos/areas')->assertOk()->assertJsonCount(9, 'data'); // miss
        $this->getJson('/api/v1/catalogos/areas')                                          // hit
            ->assertOk()
            ->assertJsonCount(9, 'data')
            ->assertJsonStructure(['data' => [['id', 'nome']]]);
    }
}
