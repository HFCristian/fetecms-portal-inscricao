<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 115 — o que a organização designou aparece, e aparece separado.
 *
 * O relato de produção: uma avaliadora recebeu projetos designados pelo admin e
 * eles não apareceram na tela dela. A causa era o corte da fila — a lista vinha
 * ordenada por id e era truncada no mínimo por avaliador, então a designação
 * manual, sendo a mais nova, caía fora.
 */
class AvaliadorPainelDesignadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);
    }

    private function avaliador(int $areaId): User
    {
        $user = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);

        return $user;
    }

    private function projeto(int $areaId, string $titulo): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'categoria' => Categoria::Fetecms, 'titulo' => $titulo,
        ]);
    }

    private function designar(Projeto $p, User $u, bool $manual = false, ?StatusAvaliacao $status = null): Avaliacao
    {
        return Avaliacao::create([
            'projeto_id' => $p->id,
            'avaliador_id' => $u->id,
            'status' => $status ?? StatusAvaliacao::Designada,
            'designacao_manual' => $manual,
        ]);
    }

    public function test_designacao_manual_nao_e_cortada_pelo_limite_da_fila(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 3]);

        // Três automáticas primeiro (ids menores) e a manual por último — é a
        // ordem real: o admin designa depois da distribuição.
        foreach (['Auto 1', 'Auto 2', 'Auto 3'] as $titulo) {
            $this->designar($this->projeto($area->id, $titulo), $avaliador);
        }
        $daOrganizacao = $this->projeto($area->id, 'Escolhido pela comissão');
        $this->designar($daOrganizacao, $avaliador, manual: true);

        Sanctum::actingAs($avaliador);

        $resposta = $this->getJson('/api/v1/avaliacao')->assertOk();

        // Antes da Sprint 115 este projeto simplesmente não vinha na resposta.
        $this->assertSame(
            ['Escolhido pela comissão'],
            array_column($resposta->json('data.designados_organizacao'), 'titulo'),
        );

        // E a fila automática continua limitada ao mínimo por avaliador.
        $this->assertCount(3, $resposta->json('data.projetos'));
    }

    public function test_avaliacao_ja_aberta_tambem_escapa_do_corte(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 2]);

        foreach (['Auto 1', 'Auto 2', 'Auto 3'] as $titulo) {
            $this->designar($this->projeto($area->id, $titulo), $avaliador);
        }
        $aberta = $this->projeto($area->id, 'Já comecei');
        $this->designar($aberta, $avaliador, status: StatusAvaliacao::EmAndamento);

        Sanctum::actingAs($avaliador);

        $resposta = $this->getJson('/api/v1/avaliacao')->assertOk();

        $this->assertSame(
            ['Já comecei'],
            array_column($resposta->json('data.designados_organizacao'), 'titulo'),
        );
        $this->assertCount(2, $resposta->json('data.projetos'));
    }

    public function test_sem_designacao_manual_a_lista_destacada_fica_vazia(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $this->designar($this->projeto($area->id, 'Auto 1'), $avaliador);

        Sanctum::actingAs($avaliador);

        $this->getJson('/api/v1/avaliacao')
            ->assertOk()
            ->assertJsonCount(0, 'data.designados_organizacao')
            ->assertJsonCount(1, 'data.projetos');
    }

    public function test_a_lista_destacada_diz_quais_sao_designacao_manual(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $this->designar($this->projeto($area->id, 'Da comissão'), $avaliador, manual: true);
        $this->designar(
            $this->projeto($area->id, 'Aberta por mim'), $avaliador, status: StatusAvaliacao::EmAndamento,
        );

        Sanctum::actingAs($avaliador);

        $lista = collect($this->getJson('/api/v1/avaliacao')->assertOk()->json('data.designados_organizacao'))
            ->pluck('designacao_manual', 'titulo');

        $this->assertTrue($lista['Da comissão']);
        $this->assertFalse($lista['Aberta por mim']);
    }

    public function test_concluida_continua_no_historico_e_fora_das_duas_listas(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $this->designar(
            $this->projeto($area->id, 'Enviada'), $avaliador,
            manual: true, status: StatusAvaliacao::Concluida,
        )->update(['nota' => 9, 'concluida_em' => now()]);

        Sanctum::actingAs($avaliador);

        $this->getJson('/api/v1/avaliacao')
            ->assertOk()
            ->assertJsonCount(0, 'data.designados_organizacao')
            ->assertJsonCount(0, 'data.projetos')
            ->assertJsonCount(1, 'data.concluidos');
    }
}
