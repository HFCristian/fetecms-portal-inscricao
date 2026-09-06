<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Services\DistribuicaoService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mínimos da avaliação online (Parametrização → Avaliação Online): quantas
 * avaliações cada avaliador conclui — e quantos projetos ele vê — e quantas
 * cada projeto precisa receber.
 */
class MinimosAvaliacaoTest extends TestCase
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

    private function projetoSubmetido(int $areaId, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'area_id' => $areaId, 'titulo' => $titulo,
        ]);
    }

    public function test_config_traz_os_minimos_padrao(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/config')
            ->assertOk()
            ->assertJsonPath('data.min_por_avaliador', 3)
            ->assertJsonPath('data.min_por_projeto', 3);
    }

    public function test_admin_altera_cada_minimo_em_separado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_avaliador' => 5])
            ->assertOk()
            ->assertJsonPath('data.min_por_avaliador', 5)
            ->assertJsonPath('data.min_por_projeto', 3); // o outro fica como estava

        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_projeto' => 4])
            ->assertOk()
            ->assertJsonPath('data.min_por_avaliador', 5)
            ->assertJsonPath('data.min_por_projeto', 4);

        $edicao = Edicao::atual();
        $this->assertSame(5, $edicao->avaliacoes_min_por_avaliador);
        $this->assertSame(4, $edicao->avaliacoes_min_por_projeto);
    }

    public function test_minimo_fora_da_faixa_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_avaliador' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_por_avaliador');

        // Payload vazio: a exigência de "ao menos um limite" mora no primeiro campo.
        $this->patchJson('/api/v1/admin/avaliacao/minimos', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_por_avaliador');
    }

    public function test_minimos_sao_so_para_admin(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->patchJson('/api/v1/admin/avaliacao/minimos', ['min_por_projeto' => 2])->assertForbidden();
    }

    public function test_o_alvo_da_distribuicao_e_o_das_designacoes_nao_o_minimo(): void
    {
        // O mínimo é a cobertura que a feira precisa; quantos avaliadores ficam
        // com o projeto na lista é outro número, e é ele que a distribuição
        // persegue. Mexer só no mínimo não muda o tamanho da distribuição.
        Edicao::atual()->update([
            'avaliacoes_min_por_projeto' => 2,
            'designacoes_por_projeto' => 4,
        ]);

        $area = Area::create(['nome' => 'Área A']);
        foreach (range(1, 4) as $i) {
            $this->avaliador($area->id);
        }
        $projeto = $this->projetoSubmetido($area->id);

        $resultado = app(DistribuicaoService::class)->distribuir();

        $this->assertSame(4, $resultado['designadas_criadas']);
        $this->assertSame(4, Avaliacao::where('projeto_id', $projeto->id)->count());
        // Cobertura de sobra: nada a relatar.
        $this->assertSame([], $resultado['sub_cobertos']);
    }

    public function test_minimo_por_avaliador_e_a_capacidade_padrao_da_distribuicao(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1, 'avaliacoes_min_por_projeto' => 1]);

        $area = Area::create(['nome' => 'Área A']);
        $unico = $this->avaliador($area->id);
        $this->projetoSubmetido($area->id, 'P1');
        $this->projetoSubmetido($area->id, 'P2');

        $resultado = app(DistribuicaoService::class)->distribuir();

        // Capacidade 1: o avaliador assume um projeto só, o outro fica sub-coberto.
        $this->assertSame(1, Avaliacao::where('avaliador_id', $unico->id)->count());
        $this->assertCount(1, $resultado['sub_cobertos']);
    }

    public function test_avaliador_ve_no_maximo_o_minimo_por_avaliador(): void
    {
        Edicao::atual()->update([
            'avaliacoes_min_por_avaliador' => 2,
            'avaliacao_liberada_em' => now()->subDay(),
        ]);

        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        // Quatro designadas + uma concluída: a fila mostra as 2 primeiras pendentes
        // e a concluída vai para a seção de avaliados.
        foreach (range(1, 4) as $i) {
            Avaliacao::create([
                'projeto_id' => $this->projetoSubmetido($area->id, "P{$i}")->id,
                'avaliador_id' => $avaliador->id,
                'status' => 'designada',
            ]);
        }
        Avaliacao::create([
            'projeto_id' => $this->projetoSubmetido($area->id, 'Concluído')->id,
            'avaliador_id' => $avaliador->id,
            'status' => 'concluida',
            'nota' => 8.5,
            'concluida_em' => now(),
        ]);

        Sanctum::actingAs($avaliador);

        $resposta = $this->getJson('/api/v1/avaliacao')
            ->assertOk()
            ->assertJsonPath('data.min_por_avaliador', 2)
            ->assertJsonCount(2, 'data.projetos')
            ->assertJsonCount(1, 'data.concluidos');

        $this->assertSame(['P1', 'P2'], array_column($resposta->json('data.projetos'), 'titulo'));
        $this->assertSame(['Concluído'], array_column($resposta->json('data.concluidos'), 'titulo'));
    }
}
