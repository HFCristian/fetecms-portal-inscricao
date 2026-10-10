<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 180 — apelido (o nome da lista) e descrição, na criação e depois,
 * sem justificativa e sem versão nova.
 */
class ApelidoDescricaoListaTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $projeto = Projeto::factory()->submetido()->create([
            'categoria' => Categoria::Fetecms, 'edicao_id' => $this->edicao->id,
            'area_id' => Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR'])->id,
        ]);
        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => User::factory()->avaliador()->create()->id,
            'status' => StatusAvaliacao::Concluida, 'nota' => 9, 'concluida_em' => now(),
        ]);
    }

    public function test_lista_nasce_com_apelido_e_descricao_e_aparece_na_relacao(): void
    {
        $id = $this->postJson('/api/v1/admin/avaliacao/lista-final', [
            'tipo' => 'preliminar', 'nome' => 'Cotas FUNDECT', 'descricao' => '70% do interior, só agrárias',
            'total' => ['tipo' => 'fixo', 'valor' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.lista.nome', 'Cotas FUNDECT')
            ->assertJsonPath('data.lista.descricao', '70% do interior, só agrárias')
            ->json('data.lista.id');

        $relacao = $this->getJson('/api/v1/admin/avaliacao/listas-finais')->assertOk()->json('data');
        $this->assertSame('70% do interior, só agrárias', collect($relacao)->firstWhere('id', $id)['descricao']);
    }

    public function test_sem_descricao_ela_fica_vazia(): void
    {
        $this->postJson('/api/v1/admin/avaliacao/lista-final', [
            'tipo' => 'final', 'total' => ['tipo' => 'fixo', 'valor' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.lista.nome', 'Lista final · XVI FETECMS')
            ->assertJsonPath('data.lista.descricao', null);
    }

    public function test_apelido_e_descricao_mudam_depois_sem_justificativa_nem_versao(): void
    {
        $id = $this->postJson('/api/v1/admin/avaliacao/lista-final', [
            'tipo' => 'final', 'total' => ['tipo' => 'fixo', 'valor' => 1],
        ])->json('data.lista.id');
        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$id}/gerar")->assertOk();

        $this->patchJson("/api/v1/admin/avaliacao/listas-finais/{$id}", [
            'nome' => 'Final oficial', 'descricao' => 'Aprovada na reunião de 30/09.',
        ])->assertOk()
            ->assertJsonPath('meta.message', 'Apelido e descrição salvos.')
            ->assertJsonPath('data.lista.nome', 'Final oficial')
            ->assertJsonPath('data.lista.descricao', 'Aprovada na reunião de 30/09.');

        $lista = ListaFinal::findOrFail($id);
        $this->assertSame(1, $lista->versao);
        $this->assertTrue($lista->vigente);

        // Apelido em branco volta ao padrão; descrição em branco some.
        $this->patchJson("/api/v1/admin/avaliacao/listas-finais/{$id}", ['nome' => '', 'descricao' => ''])
            ->assertOk()
            ->assertJsonPath('data.lista.nome', 'Lista final · XVI FETECMS')
            ->assertJsonPath('data.lista.descricao', null);
    }

    public function test_apelido_comprido_demais_e_recusado(): void
    {
        $id = $this->postJson('/api/v1/admin/avaliacao/lista-final', [
            'tipo' => 'final', 'total' => ['tipo' => 'fixo', 'valor' => 1],
        ])->json('data.lista.id');

        $this->patchJson("/api/v1/admin/avaliacao/listas-finais/{$id}", ['nome' => str_repeat('a', 121), 'descricao' => null])
            ->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_so_admin_rotula_a_lista(): void
    {
        $lista = ListaFinal::create(['edicao_id' => $this->edicao->id, 'nome' => 'X', 'versao' => 1]);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}", ['nome' => 'Y', 'descricao' => null])
            ->assertForbidden();
    }
}
