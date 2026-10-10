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
 * Sprint 177 — os códigos da lista podem ficar em branco, fugir do padrão ou
 * da sequência (com aviso), e o código repetido impede a geração.
 */
class AvisosCodigosListaTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Area $agr;

    private Area $bio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->bio = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function rascunho(string $tipo = 'final'): ListaFinal
    {
        return ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Rascunho', 'tipo' => $tipo,
            'vigente' => false, 'rascunho' => true, 'demo' => false, 'versao' => 1,
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

    private function codigo(ListaFinal $lista, Projeto $projeto, ?string $codigo)
    {
        return $this->putJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$projeto->id}/codigo", ['codigo' => $codigo]);
    }

    /** @return array<string, list<string>> título => avisos */
    private function avisos(ListaFinal $lista): array
    {
        $itens = $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}")->assertOk()->json('data.itens');

        return array_column($itens, 'avisos', 'titulo');
    }

    public function test_lista_sem_alteracao_nao_tem_aviso(): void
    {
        $lista = $this->rascunho();
        $this->projeto($lista, 'Abelhas');
        $this->projeto($lista, 'Bioplástico');

        $this->assertSame(['Abelhas' => [], 'Bioplástico' => []], $this->avisos($lista));
    }

    public function test_apagar_o_codigo_deixa_o_projeto_sem_codigo_e_a_lista_ainda_gera(): void
    {
        $lista = $this->rascunho();
        $a = $this->projeto($lista, 'Abelhas');
        $this->projeto($lista, 'Bioplástico');

        $this->codigo($lista, $a, '')
            ->assertOk()
            ->assertJsonPath('meta.message', 'Código removido: o projeto ficou sem código.')
            ->assertJsonPath('data.lista.avisos_codigos.faltantes', 1)
            // Sem código vai para o fim do grupo.
            ->assertJsonPath('data.itens.1.titulo', 'Abelhas')
            ->assertJsonPath('data.itens.1.codigo', '');

        $avisos = $this->avisos($lista);
        $this->assertSame(['faltante'], $avisos['Abelhas']);
        // O 002 ficou sozinho: a sequência do grupo pede 001.
        $this->assertSame(['fora_sequencia'], $avisos['Bioplástico']);

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/gerar")->assertOk();

        $txt = app(ListaFinalService::class)->exportarTxtDaLista($lista->fresh());
        $this->assertStringContainsString('(sem código) - Abelhas', $txt);
        // Quem escreve "CÓDIGO - Título" não recebe código vazio.
        $this->assertArrayNotHasKey($a->id, app(ListaFinalService::class)->codigosDaLista($lista->fresh()));
    }

    public function test_codigo_fora_do_padrao_avisa_e_nao_impede(): void
    {
        $lista = $this->rascunho();
        $a = $this->projeto($lista, 'Abelhas');
        $b = $this->projeto($lista, 'Bioplástico');

        $this->codigo($lista, $a, 'FET.BIO-001')->assertOk();
        $this->codigo($lista, $b, 'abc12')->assertOk();

        $avisos = $this->avisos($lista);
        $this->assertSame(['fora_padrao'], $avisos['Abelhas']);
        $this->assertSame(['fora_padrao'], $avisos['Bioplástico']);

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/gerar")->assertOk();
    }

    public function test_salto_na_numeracao_do_grupo_avisa_fora_da_sequencia(): void
    {
        $lista = $this->rascunho();
        $this->projeto($lista, 'Abelhas');
        $this->projeto($lista, 'Bioplástico');
        $c = $this->projeto($lista, 'Compostagem');
        $this->projeto($lista, 'Drone', $this->bio);

        $this->codigo($lista, $c, 'FET.AGR-005')->assertOk();

        $avisos = $this->avisos($lista);
        $this->assertSame(['fora_sequencia'], $avisos['Compostagem']);
        $this->assertSame([], $avisos['Abelhas']);
        // Outro grupo tem a sua própria sequência.
        $this->assertSame([], $avisos['Drone']);
    }

    public function test_codigo_repetido_impede_gerar_a_preliminar_e_a_final(): void
    {
        foreach (['preliminar', 'final'] as $tipo) {
            $lista = $this->rascunho($tipo);
            $a = $this->projeto($lista, "Abelhas {$tipo}");
            $this->projeto($lista, "Bioplástico {$tipo}");

            $this->codigo($lista, $a, 'FET.AGR-002')->assertOk();
            // Dois 002 e nenhum 001: repetido e, de quebra, fora da sequência.
            $this->assertSame(['fora_sequencia', 'duplicado'], $this->avisos($lista)["Abelhas {$tipo}"]);

            $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/gerar")
                ->assertStatus(422)
                ->assertJsonValidationErrors('codigos');
            $this->assertTrue($lista->fresh()->rascunho);

            // Corrigido o código, gera.
            $this->codigo($lista, $a, 'FET.AGR-001')->assertOk();
            $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/gerar")->assertOk();
        }
    }

    public function test_editar_o_codigo_reordena_o_grupo_pelo_codigo(): void
    {
        $lista = $this->rascunho();
        $this->projeto($lista, 'Abelhas');
        $this->projeto($lista, 'Bioplástico');
        $c = $this->projeto($lista, 'Compostagem');

        // -010 vem depois de -002 (ordem natural), e "000" antes de todos.
        $this->codigo($lista, $c, 'FET.AGR-000')
            ->assertOk()
            ->assertJsonPath('data.itens.0.titulo', 'Compostagem');

        $this->codigo($lista, $c, 'FET.AGR-10')
            ->assertOk()
            ->assertJsonPath('data.itens.2.titulo', 'Compostagem');
    }
}
