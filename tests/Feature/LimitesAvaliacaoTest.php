<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Services\DistribuicaoService;
use App\Services\FilaAvaliadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Limites de avaliação (Parametrização → Avaliação Online): mínimo e máximo por
 * avaliador e por projeto, este último podendo variar por categoria.
 */
class LimitesAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    private function avaliador(int $areaId): User
    {
        $user = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);

        return $user;
    }

    private function projeto(int $areaId, Categoria $categoria, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'categoria' => $categoria, 'titulo' => $titulo,
        ]);
    }

    public function test_config_traz_os_pares_e_as_categorias(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/config')
            ->assertOk()
            ->assertJsonPath('data.min_por_avaliador', 3)
            ->assertJsonPath('data.max_por_avaliador', null)   // sem teto, como sempre foi
            ->assertJsonPath('data.min_por_projeto', 3)
            ->assertJsonPath('data.max_por_projeto', Avaliacao::TETO_POR_PROJETO)
            ->assertJsonCount(3, 'data.categorias')
            ->assertJsonPath('data.categorias.0.min', null)
            ->assertJsonPath('data.categorias.0.min_efetivo', 3);
    }

    public function test_admin_salva_os_limites_por_categoria(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/minimos', [
            'min_por_projeto' => 3,
            'max_por_projeto' => 5,
            'categorias' => [
                'fetec_jr' => ['min' => 1, 'max' => 2],
                'fetecms' => ['min' => null, 'max' => null],
                'fetecms_fundect' => ['min' => 4, 'max' => null],
            ],
        ])->assertOk();

        $limites = Edicao::limites();

        $this->assertSame(1, $limites->minPorProjeto(Categoria::FetecJr));
        $this->assertSame(2, $limites->maxPorProjeto(Categoria::FetecJr));
        $this->assertSame(3, $limites->minPorProjeto(Categoria::Fetecms));   // segue o geral
        $this->assertSame(5, $limites->maxPorProjeto(Categoria::Fetecms));
        $this->assertSame(4, $limites->minPorProjeto(Categoria::FetecmsFundect));
        $this->assertFalse($limites->minUniforme());

        $this->assertDatabaseHas('registros_atividade', [
            'tipo' => TipoRegistro::AvaliacaoLimitesCategoria->value,
        ]);
    }

    public function test_maximo_menor_que_minimo_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_avaliador' => 5, 'max_por_avaliador' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('max_por_avaliador');

        $this->patchJson('/api/v1/admin/avaliacao/minimos', [
            'categorias' => ['fetec_jr' => ['min' => 4, 'max' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('categorias.fetec_jr.max');
    }

    public function test_distribuicao_usa_o_minimo_da_categoria_do_projeto(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        foreach (range(1, 4) as $i) {
            $this->avaliador($area->id);
        }

        $jr = $this->projeto($area->id, Categoria::FetecJr, 'Jr');
        $ms = $this->projeto($area->id, Categoria::Fetecms, 'MS');

        Edicao::atual()->update(['avaliacoes_por_categoria' => [
            'fetec_jr' => ['min' => 1, 'max' => 1],
            'fetecms' => ['min' => null, 'max' => null],
            'fetecms_fundect' => ['min' => null, 'max' => null],
        ]]);

        app(DistribuicaoService::class)->distribuir();

        $this->assertSame(1, Avaliacao::where('projeto_id', $jr->id)->count());
        $this->assertSame(3, Avaliacao::where('projeto_id', $ms->id)->count());
    }

    public function test_teto_por_categoria_limita_quem_enxerga_o_projeto(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $jr = $this->projeto($area->id, Categoria::FetecJr, 'Jr');

        // O projeto já bateu o teto da categoria: ninguém mais o recebe.
        Avaliacao::create([
            'projeto_id' => $jr->id,
            'avaliador_id' => $this->avaliador($area->id)->id,
            'status' => StatusAvaliacao::Designada,
        ]);

        Edicao::atual()->update(['avaliacoes_por_categoria' => [
            'fetec_jr' => ['min' => 1, 'max' => 1],
            'fetecms' => ['min' => null, 'max' => null],
            'fetecms_fundect' => ['min' => null, 'max' => null],
        ]]);

        $this->assertSame(0, app(FilaAvaliadorService::class)->repor($avaliador));
    }

    public function test_teto_por_avaliador_para_a_reposicao_da_fila(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        foreach (range(1, 5) as $i) {
            $this->projeto($area->id, Categoria::Fetecms, "Projeto {$i}");
        }

        Edicao::atual()->update([
            'avaliacoes_min_por_avaliador' => 2,
            'avaliacoes_max_por_avaliador' => 3,
        ]);

        // Primeira leva: a fila enche até o mínimo (2).
        $this->assertSame(2, app(FilaAvaliadorService::class)->repor($avaliador));

        // Concluída uma, entra só mais uma — a terceira fecha o teto total.
        Avaliacao::where('avaliador_id', $avaliador->id)->first()
            ->update(['status' => StatusAvaliacao::Concluida, 'nota' => 8, 'concluida_em' => now()]);

        $this->assertSame(1, app(FilaAvaliadorService::class)->repor($avaliador->fresh()));
        $this->assertSame(3, Avaliacao::where('avaliador_id', $avaliador->id)->count());

        // Concluída outra, o teto já não deixa entrar mais nada.
        Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('status', StatusAvaliacao::Designada->value)->first()
            ->update(['status' => StatusAvaliacao::Concluida, 'nota' => 8, 'concluida_em' => now()]);

        $this->assertSame(0, app(FilaAvaliadorService::class)->repor($avaliador->fresh()));
    }

    public function test_sem_teto_a_fila_continua_repondo(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        foreach (range(1, 4) as $i) {
            $this->projeto($area->id, Categoria::Fetecms, "Projeto {$i}");
        }

        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1]);

        $this->assertSame(1, app(FilaAvaliadorService::class)->repor($avaliador));

        Avaliacao::where('avaliador_id', $avaliador->id)->first()
            ->update(['status' => StatusAvaliacao::Concluida, 'nota' => 8, 'concluida_em' => now()]);

        $this->assertSame(1, app(FilaAvaliadorService::class)->repor($avaliador->fresh()));
    }
}
