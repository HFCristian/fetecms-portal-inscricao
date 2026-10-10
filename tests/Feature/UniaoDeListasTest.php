<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Services\ListaFinalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 179 — a lista (preliminar ou final) montada pela união de listas já
 * geradas, preliminares **e** finais, herdando os códigos já fixados.
 */
class UniaoDeListasTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Area $agr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function projeto(string $titulo): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'categoria' => 'fetecms', 'area_id' => $this->agr->id,
        ]);
    }

    /** Uma lista já gerada com os projetos e, se dados, os códigos fixados. */
    private function gerada(string $tipo, string $nome, array $projetos, array $codigos = [], bool $vigente = false, bool $rascunho = false): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => $nome, 'tipo' => $tipo, 'vigente' => $vigente,
            'rascunho' => $rascunho, 'demo' => false, 'versao' => 1, 'gerada_em' => $rascunho ? null : now(),
            'codigos_congelados_em' => $codigos === [] ? null : now(),
        ]);
        foreach ($projetos as $p) {
            $lista->projetos()->attach($p->id, ['manual' => false, 'codigo' => $codigos[$p->id] ?? null]);
        }

        return $lista;
    }

    private function unir(array $listas, string $tipo, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/avaliacao/listas/uniao', ['listas' => $listas, 'tipo' => $tipo] + $extra);
    }

    public function test_monta_preliminar_juntando_a_final_ativa_e_uma_preliminar(): void
    {
        [$a, $b, $c] = [$this->projeto('Abelhas'), $this->projeto('Biofiltro'), $this->projeto('Compostagem')];
        $final = $this->gerada('final', 'Final ativa', [$a, $b], vigente: true);
        $preliminar = $this->gerada('preliminar', 'Suplentes', [$b, $c]);

        $resposta = $this->unir([$preliminar->id, $final->id], 'preliminar', ['nome' => 'Final + suplentes'])
            ->assertCreated()
            ->assertJsonPath('data.lista.tipo', 'preliminar')
            ->assertJsonPath('data.lista.rascunho', true)
            ->assertJsonPath('data.lista.nome', 'Final + suplentes')
            ->assertJsonPath('data.lista.projetos', 3);

        // A final ativa vem primeiro na origem — é ela que manda na herança.
        $this->assertSame(['Final ativa', 'Suplentes'], array_column($resposta->json('data.lista.origens'), 'nome'));
        $this->assertSame('final', $resposta->json('data.lista.origens.0.tipo'));
        // A final ativa continua sendo a ativa: o rascunho não define finalista.
        $this->assertSame($final->id, ListaFinal::vigente()->id);
    }

    public function test_monta_final_a_partir_de_finais(): void
    {
        [$a, $b] = [$this->projeto('Abelhas'), $this->projeto('Biofiltro')];
        $antiga = $this->gerada('final', 'Final antiga', [$a]);
        $ativa = $this->gerada('final', 'Final ativa', [$b], vigente: true);

        $this->unir([$antiga->id, $ativa->id], 'final')
            ->assertCreated()
            ->assertJsonPath('data.lista.tipo', 'final')
            ->assertJsonPath('data.lista.projetos', 2);
    }

    public function test_herda_os_codigos_fixados_e_numera_quem_nao_tem(): void
    {
        [$a, $b, $c] = [$this->projeto('Abelhas'), $this->projeto('Biofiltro'), $this->projeto('Compostagem')];
        // A final ativa já mandou os códigos: Biofiltro é o 007.
        $final = $this->gerada('final', 'Final ativa', [$a, $b], [$a->id => 'FET.AGR-001', $b->id => 'FET.AGR-007'], vigente: true);
        // Numa preliminar antiga, Biofiltro era o 002 — a final ativa vale mais.
        $preliminar = $this->gerada('preliminar', 'Antiga', [$b, $c], [$b->id => 'FET.AGR-002', $c->id => 'FET.AGR-001']);

        $id = $this->unir([$preliminar->id, $final->id], 'final')->assertCreated()->json('data.lista.id');
        $lista = ListaFinal::findOrFail($id);
        $codigos = app(ListaFinalService::class)->codigosDaLista($lista);

        $this->assertSame('FET.AGR-001', $codigos[$a->id]);
        $this->assertSame('FET.AGR-007', $codigos[$b->id]);
        // Compostagem herdou o 001 da preliminar, que já é de Abelhas: repetido
        // vira aviso e barra a geração até o admin decidir.
        $this->assertSame('FET.AGR-001', $codigos[$c->id]);
        $this->assertNotNull($lista->codigos_congelados_em);

        $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$id}")
            ->assertJsonPath('data.lista.avisos_codigos.duplicados', 2);
        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$id}/gerar")->assertStatus(422);
    }

    public function test_quem_nao_herda_codigo_ganha_o_proximo_numero_do_grupo(): void
    {
        [$a, $b] = [$this->projeto('Abelhas'), $this->projeto('Zebra')];
        $final = $this->gerada('final', 'Final ativa', [$a], [$a->id => 'FET.AGR-004'], vigente: true);
        $preliminar = $this->gerada('preliminar', 'Sem códigos fixos', [$b]);

        $id = $this->unir([$final->id, $preliminar->id], 'preliminar')->assertCreated()->json('data.lista.id');
        $codigos = app(ListaFinalService::class)->codigosDaLista(ListaFinal::findOrFail($id));

        $this->assertSame('FET.AGR-004', $codigos[$a->id]);
        $this->assertSame('FET.AGR-005', $codigos[$b->id]);
    }

    public function test_sem_codigo_fixado_nas_origens_a_lista_numera_normalmente(): void
    {
        [$a, $b] = [$this->projeto('Abelhas'), $this->projeto('Biofiltro')];
        $p1 = $this->gerada('preliminar', 'Uma', [$b]);
        $p2 = $this->gerada('preliminar', 'Outra', [$a]);

        $id = $this->unir([$p1->id, $p2->id], 'final')->assertCreated()->json('data.lista.id');
        $lista = ListaFinal::findOrFail($id);

        $this->assertNull($lista->codigos_congelados_em);
        $this->assertSame([$a->id => 'FET.AGR-001', $b->id => 'FET.AGR-002'], app(ListaFinalService::class)->codigosDaLista($lista));
    }

    public function test_rascunho_lista_demo_e_tipo_invalido_sao_recusados(): void
    {
        $a = $this->projeto('Abelhas');
        $gerada = $this->gerada('preliminar', 'Gerada', [$a]);
        $rascunho = $this->gerada('preliminar', 'Rascunho', [$a], rascunho: true);
        $demo = $this->gerada('final', 'Demo', [$a]);
        $demo->update(['demo' => true]);

        $this->unir([$gerada->id, $rascunho->id], 'final')->assertStatus(422)->assertJsonValidationErrors('listas');
        $this->unir([$demo->id], 'final')->assertStatus(422)->assertJsonValidationErrors('listas');
        $this->unir([$gerada->id], 'oficial')->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->unir([], 'final')->assertStatus(422)->assertJsonValidationErrors('listas');
    }
}
