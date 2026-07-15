<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use App\Services\DistribuicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DistribuicaoTest extends TestCase
{
    use RefreshDatabase;

    private function avaliador(int $areaId, ?int $subareaId = null, array $over = []): User
    {
        $user = User::factory()->avaliador()->create($over);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $areaId, 'subarea_id' => $subareaId,
        ]);

        return $user;
    }

    private function projetoSubmetido(int $areaId, ?int $subareaId = null, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'subarea_id' => $subareaId, 'titulo' => $titulo,
        ]);
    }

    private function distribuir(): array
    {
        return app(DistribuicaoService::class)->distribuir();
    }

    public function test_distribui_ate_3_ignora_demo_e_e_idempotente(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $demo = $this->avaliador($a->id, null, ['is_demo' => true]);

        $p = $this->projetoSubmetido($a->id);

        $r = $this->distribuir();

        $this->assertSame(3, $r['designadas_criadas']);
        $this->assertSame(3, Avaliacao::where('projeto_id', $p->id)->count());
        $this->assertSame(0, Avaliacao::where('avaliador_id', $demo->id)->count());
        $this->assertSame([], $r['sub_cobertos']);

        // Rodar de novo não adiciona nada.
        $this->assertSame(0, $this->distribuir()['designadas_criadas']);
        $this->assertSame(3, Avaliacao::where('projeto_id', $p->id)->count());
    }

    public function test_prefere_avaliadores_da_mesma_subarea(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $s1 = Subarea::create(['area_id' => $a->id, 'nome' => 'Sub 1']);
        $s2 = Subarea::create(['area_id' => $a->id, 'nome' => 'Sub 2']);

        $sub1a = $this->avaliador($a->id, $s1->id);
        $sub1b = $this->avaliador($a->id, $s1->id);
        $this->avaliador($a->id, $s2->id);
        $this->avaliador($a->id, $s2->id);

        $p = $this->projetoSubmetido($a->id, $s1->id);

        $this->distribuir();

        // Os dois da subárea do projeto entram (preferência).
        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $sub1a->id]);
        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $sub1b->id]);
        $this->assertSame(3, Avaliacao::where('projeto_id', $p->id)->count());
    }

    public function test_relata_projetos_sub_cobertos(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id); // apenas 1 avaliador na área

        $p = $this->projetoSubmetido($a->id, null, 'Projeto sub');

        $r = $this->distribuir();

        $this->assertSame(1, Avaliacao::where('projeto_id', $p->id)->count());
        $this->assertCount(1, $r['sub_cobertos']);
        $this->assertSame($p->id, $r['sub_cobertos'][0]['projeto_id']);
        $this->assertSame(2, $r['sub_cobertos'][0]['faltam']);
    }

    public function test_respeita_o_limite_individual(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $limitado = $this->avaliador($a->id);
        $limitado->avaliadorProfile->update(['limite_avaliacoes' => 1]);
        $this->avaliador($a->id);
        $this->avaliador($a->id);

        $this->projetoSubmetido($a->id, null, 'P1');
        $this->projetoSubmetido($a->id, null, 'P2');

        $this->distribuir();

        $this->assertLessThanOrEqual(1, Avaliacao::where('avaliador_id', $limitado->id)->count());
    }

    public function test_endpoint_de_distribuicao_do_admin(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $this->projetoSubmetido($a->id);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/avaliacao/distribuir')
            ->assertOk()
            ->assertJsonPath('data.designadas_criadas', 3);
    }

    public function test_distribuir_e_so_para_admin(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->postJson('/api/v1/admin/avaliacao/distribuir')->assertStatus(403);
    }
}
