<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 164 — listas **preliminares** (várias, nenhuma define finalista) e
 * **finais** (uma ativa por edição), editadas livremente no rascunho e
 * fechadas ao gerar. A final nasce da classificação ou da união de
 * preliminares escolhidas.
 */
class ListasPreliminaresEFinaisTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function avaliado(string $titulo, float $nota): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'categoria' => Categoria::Fetecms, 'area_id' => $this->area->id, 'edicao_id' => $this->edicao->id,
        ]);
        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => User::factory()->avaliador()->create()->id,
            'status' => StatusAvaliacao::Concluida, 'nota' => $nota, 'concluida_em' => now(),
        ]);

        return $projeto;
    }

    /** Gera um rascunho pela classificação e devolve o id. */
    private function rascunho(string $tipo, int $total, string $nome): int
    {
        return $this->postJson('/api/v1/admin/avaliacao/lista-final', [
            'tipo' => $tipo, 'nome' => $nome, 'total' => ['tipo' => 'fixo', 'valor' => $total],
        ])->assertCreated()
            ->assertJsonPath('data.lista.tipo', $tipo)
            ->assertJsonPath('data.lista.rascunho', true)
            ->json('data.lista.id');
    }

    private function gerar(int $id): void
    {
        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$id}/gerar")->assertOk();
    }

    public function test_preliminar_e_editada_livre_no_rascunho_e_gerada_sem_virar_finalista(): void
    {
        $a = $this->avaliado('Abelhas', 9);
        $b = $this->avaliado('Biofiltro', 8);
        $sem = Projeto::factory()->submetido()->create(['titulo' => 'Sem avaliação', 'edicao_id' => $this->edicao->id]);

        $id = $this->rascunho('preliminar', 1, 'Preliminar de agrárias');

        // Rascunho: entra e sai sem justificativa, inclusive projeto sem avaliação.
        $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$id}")
            ->assertOk()
            ->assertJsonFragment(['titulo' => 'Sem avaliação']);
        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$id}/projetos", ['projeto_id' => $sem->id])->assertOk();
        $this->deleteJson("/api/v1/admin/avaliacao/listas-finais/{$id}/projetos/{$a->id}")->assertOk();
        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$id}/projetos", ['projeto_id' => $b->id])->assertOk();

        $lista = ListaFinal::findOrFail($id);
        $this->assertEqualsCanonicalizing([$sem->id, $b->id], $lista->projetos()->pluck('projetos.id')->all());
        $this->assertSame(1, $lista->versao);
        $this->assertSame(0, RegistroAtividade::where('tipo', TipoRegistro::ListaFinalProjetoAdicionado)->count());

        $this->gerar($id);

        $lista->refresh();
        $this->assertFalse($lista->rascunho);
        $this->assertFalse($lista->vigente);
        $this->assertNull(ListaFinal::vigente());
        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::ListaPreliminarGerada)->exists());

        // Gerada, mexer pede justificativa.
        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$id}/projetos", ['projeto_id' => $a->id])
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');
    }

    public function test_varias_preliminares_convivem(): void
    {
        $this->avaliado('Abelhas', 9);
        $this->gerar($this->rascunho('preliminar', 1, 'Primeira'));
        $this->gerar($this->rascunho('preliminar', 1, 'Segunda'));

        $listas = collect($this->getJson('/api/v1/admin/avaliacao/listas-finais')->assertOk()->json('data'));
        $this->assertSame(2, $listas->where('tipo', 'preliminar')->where('rascunho', false)->count());
    }

    public function test_final_pela_uniao_de_preliminares_escolhidas(): void
    {
        $a = $this->avaliado('Abelhas', 9);
        $b = $this->avaliado('Biofiltro', 8);
        $c = $this->avaliado('Compostagem', 7);

        $p1 = $this->rascunho('preliminar', 2, 'Primeira'); // Abelhas, Biofiltro
        $this->gerar($p1);
        $p2 = $this->rascunho('preliminar', 1, 'Segunda');  // Abelhas
        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$p2}/projetos", ['projeto_id' => $c->id])->assertOk();
        $this->gerar($p2);
        $rascunho = $this->rascunho('preliminar', 1, 'Ainda rascunho');

        // Preliminar em rascunho não serve de origem.
        $this->postJson('/api/v1/admin/avaliacao/listas/final-de-preliminares', ['preliminares' => [$p1, $rascunho]])
            ->assertStatus(422)->assertJsonValidationErrors('preliminares');

        $resposta = $this->postJson('/api/v1/admin/avaliacao/listas/final-de-preliminares', [
            'preliminares' => [$p1, $p2], 'nome' => 'Final oficial',
        ])->assertCreated()
            ->assertJsonPath('data.lista.tipo', 'final')
            ->assertJsonPath('data.lista.rascunho', true)
            ->assertJsonPath('data.lista.origens.0.nome', 'Primeira');

        $final = ListaFinal::findOrFail($resposta->json('data.lista.id'));
        // União sem repetir: Abelhas está nas duas.
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $final->projetos()->pluck('projetos.id')->all());

        $this->gerar($final->id);
        $this->assertSame($final->id, ListaFinal::vigente()->id);
    }

    public function test_so_uma_final_ativa_e_a_anterior_pode_voltar_com_justificativa(): void
    {
        $this->avaliado('Abelhas', 9);
        $primeira = $this->rascunho('final', 1, 'Primeira final');
        $this->gerar($primeira);
        $segunda = $this->rascunho('final', 1, 'Segunda final');
        $this->gerar($segunda);

        $this->assertSame($segunda, ListaFinal::vigente()->id);
        $this->assertFalse(ListaFinal::find($primeira)->vigente);

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$primeira}/reativar", [])
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$primeira}/reativar", ['justificativa' => 'Recurso deferido.'])
            ->assertOk()
            ->assertJsonPath('data.lista.vigente', true);

        $this->assertSame($primeira, ListaFinal::vigente()->id);
        $this->assertFalse(ListaFinal::find($segunda)->vigente);
        $registro = RegistroAtividade::where('tipo', TipoRegistro::ListaFinalReativada)->firstOrFail();
        $this->assertSame('Recurso deferido.', $registro->detalhes['justificativa']);
    }

    public function test_preliminar_nao_vira_ativa_nem_manda_codigo(): void
    {
        $this->avaliado('Abelhas', 9);
        $id = $this->rascunho('preliminar', 1, 'Preliminar');
        $this->gerar($id);

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$id}/reativar", ['justificativa' => 'Tentativa.'])
            ->assertStatus(422)->assertJsonValidationErrors('lista');

        $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$id}/codigos")
            ->assertOk()
            ->assertJsonPath('data.pode_enviar', false);
    }
}
