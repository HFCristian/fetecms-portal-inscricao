<?php

namespace Tests\Feature;

use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ListaFinalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 168 — o admin reordena os projetos de um grupo da lista (e os códigos
 * seguem a ordem) ou digita o código de um projeto à mão.
 */
class OrdemCodigosListaTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Area $agr;

    private Area $bio;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->bio = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function lista(bool $rascunho = false): ListaFinal
    {
        return ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista oficial', 'tipo' => 'final',
            'vigente' => ! $rascunho, 'rascunho' => $rascunho, 'demo' => false, 'versao' => 1,
        ]);
    }

    private function projeto(ListaFinal $lista, string $titulo, ?Area $area = null): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'categoria' => 'fetecms', 'area_id' => ($area ?? $this->agr)->id,
        ]);
        $lista->projetos()->attach($projeto->id);

        return $projeto;
    }

    /** @return array<int, string> */
    private function codigos(ListaFinal $lista): array
    {
        return app(ListaFinalService::class)->codigosDaLista($lista->fresh());
    }

    public function test_reordenar_o_grupo_renumera_na_ordem_pedida(): void
    {
        $lista = $this->lista(rascunho: true);
        $a = $this->projeto($lista, 'Abelhas');
        $b = $this->projeto($lista, 'Bioplástico');
        $c = $this->projeto($lista, 'Compostagem');

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/ordem", ['projeto_ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.itens.0.titulo', 'Compostagem')
            ->assertJsonPath('data.itens.0.codigo', 'FET.AGR-001');

        $codigos = $this->codigos($lista);
        $this->assertSame('FET.AGR-001', $codigos[$c->id]);
        $this->assertSame('FET.AGR-002', $codigos[$a->id]);
        $this->assertSame('FET.AGR-003', $codigos[$b->id]);

        // No rascunho a edição é livre: sem versão nova e sem registro.
        $this->assertSame(1, $lista->fresh()->versao);
        $this->assertSame(0, RegistroAtividade::where('tipo', TipoRegistro::ListaFinalCodigoAlterado)->count());
    }

    public function test_depois_de_reordenar_quem_entra_no_rascunho_ganha_o_proximo_numero(): void
    {
        $lista = $this->lista(rascunho: true);
        $b = $this->projeto($lista, 'Bioplástico');
        $c = $this->projeto($lista, 'Compostagem');
        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/ordem", ['projeto_ids' => [$c->id, $b->id]])->assertOk();

        // Na ordem alfabética "Abelhas" viria primeiro e repetiria o 001.
        $a = Projeto::factory()->submetido()->create([
            'titulo' => 'Abelhas', 'edicao_id' => $this->edicao->id, 'categoria' => 'fetecms', 'area_id' => $this->agr->id,
        ]);
        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos", ['projeto_id' => $a->id])->assertOk();

        $codigos = $this->codigos($lista);
        $this->assertSame('FET.AGR-003', $codigos[$a->id]);
        $this->assertCount(3, array_unique($codigos));
    }

    public function test_lista_gerada_pede_justificativa_sobe_a_versao_e_registra_o_que_mudou(): void
    {
        $lista = $this->lista();
        $a = $this->projeto($lista, 'Abelhas');
        $b = $this->projeto($lista, 'Bioplástico');

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/ordem", ['projeto_ids' => [$b->id, $a->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('justificativa');

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/ordem", [
            'projeto_ids' => [$b->id, $a->id], 'justificativa' => 'Ordem dos estandes combinada com a montadora.',
        ])->assertOk();

        $this->assertSame(2, $lista->fresh()->versao);
        $registros = RegistroAtividade::where('tipo', TipoRegistro::ListaFinalCodigoAlterado)->get();
        $this->assertCount(2, $registros);
        $deB = $registros->firstWhere('projeto_id', $b->id);
        $this->assertSame('FET.AGR-002', $deB->detalhes['de']);
        $this->assertSame('FET.AGR-001', $deB->detalhes['para']);
    }

    public function test_reordenar_exige_o_grupo_inteiro_e_so_ele(): void
    {
        $lista = $this->lista(rascunho: true);
        $a = $this->projeto($lista, 'Abelhas');
        $this->projeto($lista, 'Bioplástico');
        $bio = $this->projeto($lista, 'Células', $this->bio);

        // Faltou um do grupo.
        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/ordem", ['projeto_ids' => [$a->id]])
            ->assertStatus(422)->assertJsonValidationErrors('projeto_ids');

        // Misturou grupos.
        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/ordem", ['projeto_ids' => [$a->id, $bio->id]])
            ->assertStatus(422)->assertJsonValidationErrors('projeto_ids');
    }

    public function test_codigo_digitado_e_normalizado_e_gravado(): void
    {
        $lista = $this->lista(rascunho: true);
        $a = $this->projeto($lista, 'Abelhas');
        $b = $this->projeto($lista, 'Bioplástico');

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$a->id}/codigo", ['codigo' => ' fet.agr - 010 '])
            ->assertOk();

        $codigos = $this->codigos($lista);
        $this->assertSame('FET.AGR-010', $codigos[$a->id]);
        // O resto ficou fixado como estava.
        $this->assertSame('FET.AGR-002', $codigos[$b->id]);
        $this->assertNotNull($lista->fresh()->codigos_congelados_em);
    }

    public function test_codigo_repetido_ou_mal_formado_e_recusado(): void
    {
        $lista = $this->lista(rascunho: true);
        $a = $this->projeto($lista, 'Abelhas');
        $this->projeto($lista, 'Bioplástico');

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$a->id}/codigo", ['codigo' => 'FET.AGR-002'])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'O código FET.AGR-002 já é de "Bioplástico" nesta lista.');

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$a->id}/codigo", ['codigo' => 'FET/AGR#1'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_codigo_na_lista_gerada_pede_justificativa_e_registra(): void
    {
        $lista = $this->lista();
        $a = $this->projeto($lista, 'Abelhas');

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$a->id}/codigo", ['codigo' => 'FET.AGR-099'])
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$a->id}/codigo", [
            'codigo' => 'FET.AGR-099', 'justificativa' => 'Número reservado para a equipe da feira afiliada.',
        ])->assertOk();

        $this->assertSame('FET.AGR-099', $this->codigos($lista)[$a->id]);
        $this->assertSame(2, $lista->fresh()->versao);
        $registro = RegistroAtividade::where('tipo', TipoRegistro::ListaFinalCodigoAlterado)->sole();
        $this->assertSame('FET.AGR-001', $registro->detalhes['de']);
        $this->assertSame('FET.AGR-099', $registro->detalhes['para']);
    }

    public function test_projeto_fora_da_lista_e_recusado(): void
    {
        $lista = $this->lista(rascunho: true);
        $this->projeto($lista, 'Abelhas');
        $fora = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id]);

        $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$fora->id}/codigo", ['codigo' => 'FET.AGR-050'])
            ->assertStatus(422)->assertJsonValidationErrors('projeto_id');
    }
}
