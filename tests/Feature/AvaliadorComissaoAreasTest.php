<?php

namespace Tests\Feature;

use App\Enums\GrupoCorrelato;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use App\Services\DistribuicaoService;
use App\Services\FilaAvaliadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Poderes do admin sobre o avaliador: comissão especial e áreas extras (receber
 * projetos de outras áreas/subáreas além da que ele escolheu).
 */
class AvaliadorComissaoAreasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    private function avaliador(int $areaId, ?int $subareaId = null, string $nome = 'Ana'): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome]);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $areaId, 'subarea_id' => $subareaId,
        ]);

        return $user->fresh();
    }

    private function projeto(int $areaId, ?int $subareaId = null, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'subarea_id' => $subareaId, 'titulo' => $titulo,
        ]);
    }

    public function test_admin_marca_e_desmarca_a_comissao_especial(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/comissao", ['comissao_especial' => true])
            ->assertOk()
            ->assertJsonPath('data.comissao_especial', true);
        $this->assertTrue($avaliador->avaliadorProfile->fresh()->comissao_especial);

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertJsonPath('data.0.comissao_especial', true);

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/comissao", ['comissao_especial' => false])
            ->assertOk();
        $this->assertFalse($avaliador->avaliadorProfile->fresh()->comissao_especial);
    }

    public function test_filtro_de_situacao_na_tabela(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $daComissao = $this->avaliador($area->id, null, 'Comissionada');
        $daComissao->avaliadorProfile->update(['comissao_especial' => true]);
        $this->avaliador($area->id, null, 'Comum');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores?situacao=comissao')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Comissionada');
    }

    public function test_comissao_e_areas_extras_sao_so_do_admin(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        Sanctum::actingAs($avaliador);

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/comissao", ['comissao_especial' => true])
            ->assertForbidden();
        $this->postJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/areas-extras", ['area_id' => $area->id])
            ->assertForbidden();
    }

    public function test_admin_libera_e_remove_area_extra(): void
    {
        $propria = Area::create(['nome' => 'Área própria']);
        $outra = Area::create(['nome' => 'Outra área']);
        $sub = Subarea::create(['area_id' => $outra->id, 'nome' => 'Sub da outra']);
        $avaliador = $this->avaliador($propria->id);

        Sanctum::actingAs(User::factory()->admin()->create());

        $resposta = $this->postJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/areas-extras", [
            'area_id' => $outra->id, 'subarea_id' => $sub->id,
        ])->assertOk()->assertJsonPath('data.areas_extras.0.area', 'Outra área');

        $extraId = $resposta->json('data.areas_extras.0.id');
        $this->assertDatabaseHas('avaliador_areas_extras', [
            'avaliador_profile_id' => $avaliador->avaliadorProfile->id,
            'area_id' => $outra->id,
            'subarea_id' => $sub->id,
        ]);

        // Repetir não duplica.
        $this->postJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/areas-extras", [
            'area_id' => $outra->id, 'subarea_id' => $sub->id,
        ])->assertOk()->assertJsonCount(1, 'data.areas_extras');

        $this->deleteJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/areas-extras/{$extraId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.areas_extras');
    }

    public function test_area_extra_recusa_subarea_de_outra_area_e_a_propria_classificacao(): void
    {
        $propria = Area::create(['nome' => 'Área própria']);
        $outra = Area::create(['nome' => 'Outra área']);
        $subDaPropria = Subarea::create(['area_id' => $propria->id, 'nome' => 'Sub da própria']);
        $avaliador = $this->avaliador($propria->id);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/areas-extras", [
            'area_id' => $outra->id, 'subarea_id' => $subDaPropria->id,
        ])->assertStatus(422)->assertJsonValidationErrors('subarea_id');

        $this->postJson("/api/v1/admin/avaliacao/avaliadores/{$avaliador->id}/areas-extras", [
            'area_id' => $propria->id,
        ])->assertStatus(422)->assertJsonValidationErrors('area_id');
    }

    public function test_area_extra_vale_na_reposicao_da_fila(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1]);
        $propria = Area::create(['nome' => 'Área própria']);
        $liberada = Area::create(['nome' => 'Área liberada']);

        $avaliador = $this->avaliador($propria->id);
        $avaliador->avaliadorProfile->areasExtras()->create(['area_id' => $liberada->id]);

        // Só existe projeto na área liberada pelo admin.
        $projeto = $this->projeto($liberada->id, null, 'Da área liberada');

        app(FilaAvaliadorService::class)->repor($avaliador->fresh());

        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $projeto->id]);
    }

    public function test_area_extra_vale_na_distribuicao_automatica(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_projeto' => 1]);
        $propria = Area::create(['nome' => 'Área própria', 'grupo_correlato' => GrupoCorrelato::Vida]);
        $liberada = Area::create(['nome' => 'Área liberada']);

        $avaliador = $this->avaliador($propria->id);
        $avaliador->avaliadorProfile->areasExtras()->create(['area_id' => $liberada->id]);

        $projeto = $this->projeto($liberada->id, null, 'Da área liberada');

        $resultado = app(DistribuicaoService::class)->distribuir();

        $this->assertSame(1, $resultado['designadas_criadas']);
        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $projeto->id]);
    }

    public function test_subarea_liberada_tem_a_preferencia_da_faixa_1(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1]);
        $propria = Area::create(['nome' => 'Área própria']);
        $liberada = Area::create(['nome' => 'Área liberada']);
        $sub = Subarea::create(['area_id' => $liberada->id, 'nome' => 'Sub liberada']);

        $avaliador = $this->avaliador($propria->id);
        $avaliador->avaliadorProfile->areasExtras()->create(['area_id' => $liberada->id, 'subarea_id' => $sub->id]);

        // Um projeto na área própria e um na subárea liberada: a subárea ganha.
        $this->projeto($propria->id, null, 'Da área própria');
        $daSubarea = $this->projeto($liberada->id, $sub->id, 'Da subárea liberada');

        app(FilaAvaliadorService::class)->repor($avaliador->fresh());

        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $daSubarea->id]);
        $this->assertSame(1, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_filtro_por_area_encontra_pela_area_extra(): void
    {
        $propria = Area::create(['nome' => 'Área própria']);
        $liberada = Area::create(['nome' => 'Área liberada']);
        $avaliador = $this->avaliador($propria->id, null, 'Ana');
        $avaliador->avaliadorProfile->areasExtras()->create(['area_id' => $liberada->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/avaliacao/avaliadores?area_id={$liberada->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Ana');
    }

    public function test_designa_o_projeto_para_a_comissao_inteira(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $projeto = $this->projeto($area->id, null, 'Projeto da comissão');

        $membro1 = $this->avaliador($area->id, null, 'Membro 1');
        $membro2 = $this->avaliador($area->id, null, 'Membro 2');
        $foraDaComissao = $this->avaliador($area->id, null, 'Fora');
        $membro1->avaliadorProfile->update(['comissao_especial' => true]);
        $membro2->avaliadorProfile->update(['comissao_especial' => true]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/designar", ['tipo' => 'comissao'])
            ->assertOk()
            ->assertJsonPath('data.designadas', 2);

        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $projeto->id, 'avaliador_id' => $membro1->id, 'designacao_manual' => true]);
        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $projeto->id, 'avaliador_id' => $membro2->id]);
        $this->assertDatabaseMissing('avaliacoes', ['projeto_id' => $projeto->id, 'avaliador_id' => $foraDaComissao->id]);
    }

    public function test_designa_o_projeto_so_para_os_membros_selecionados(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $projeto = $this->projeto($area->id, null, 'Projeto da comissão');

        $escolhido = $this->avaliador($area->id, null, 'Escolhido');
        $outro = $this->avaliador($area->id, null, 'Outro');
        $escolhido->avaliadorProfile->update(['comissao_especial' => true]);
        $outro->avaliadorProfile->update(['comissao_especial' => true]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/designar", [
            'tipo' => 'comissao',
            'avaliador_ids' => [$escolhido->id],
        ])->assertOk()->assertJsonPath('data.designadas', 1);

        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $projeto->id, 'avaliador_id' => $escolhido->id]);
        $this->assertDatabaseMissing('avaliacoes', ['projeto_id' => $projeto->id, 'avaliador_id' => $outro->id]);
    }

    public function test_designar_para_comissao_vazia_explica_o_motivo(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $projeto = $this->projeto($area->id, null, 'Projeto');
        $comum = $this->avaliador($area->id, null, 'Comum');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/designar", ['tipo' => 'comissao'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');

        // Selecionar quem não é da comissão também não passa.
        $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/designar", [
            'tipo' => 'comissao', 'avaliador_ids' => [$comum->id],
        ])->assertStatus(422)->assertJsonValidationErrors('tipo');
    }

    public function test_opcoes_de_avaliadores_filtra_pela_comissao(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $membro = $this->avaliador($area->id, null, 'Membro');
        $this->avaliador($area->id, null, 'Comum');
        $membro->avaliadorProfile->update(['comissao_especial' => true]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores/opcoes?comissao=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Membro');

        $this->getJson('/api/v1/admin/avaliacao/avaliadores/opcoes')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
