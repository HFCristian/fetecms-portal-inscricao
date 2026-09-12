<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\Cidade;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\Estado;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\MapaLayout;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\PlantaEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 120 — Mapa do Evento → **a planta do ginásio**.
 *
 * O desenho padrão (a prancha da XVI FETECMS), a ocupação de cada estande nos
 * dois turnos e o versionamento: cada gravação é uma versão nova, e restaurar
 * uma antiga também.
 */
class MapaPlantaTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Cidade $cidade;

    protected function setUp(): void
    {
        parent::setUp();

        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $this->cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $estado->id, 'capital' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'turnos_config' => ['capacidade' => ['A' => 10, 'B' => 10], 'regras' => []],
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Um projeto já com turno e estande — o que o mapa mostra. */
    private function noEstande(string $titulo, Turno $turno, int $numero): Projeto
    {
        $instituicao = Instituicao::create(['nome' => 'EE '.$titulo, 'cidade_id' => $this->cidade->id]);

        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'categoria' => Categoria::Fetecms,
            'area_id' => $this->area->id,
            'instituicao_id' => $instituicao->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);

        TurnoApresentacao::create([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'turno' => $turno->value,
        ]);

        EstandeProjeto::create([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'turno' => $turno->value,
            'numero' => $numero,
        ]);

        return $projeto;
    }

    // --- A planta padrão --------------------------------------------------

    /** A prancha da montadora: 230 estandes, numeração completa. */
    public function test_planta_padrao_reproduz_a_prancha_da_feira(): void
    {
        $planta = PlantaEvento::padrao();
        $numeros = array_column($planta['estandes'], 'numero');

        $this->assertCount(230, $numeros);
        $this->assertSame(range(1, 230), $numeros);
        // Duas ilhas encostadas nas paredes, uma em cada bloco.
        $porNumero = collect($planta['estandes'])->keyBy('numero');
        $this->assertSame(['x' => 0.0, 'y' => 0.0], $this->xy($porNumero[1]));
        $this->assertSame(['x' => 24.0, 'y' => 0.0], $this->xy($porNumero[109]));
        $this->assertSame(['x' => 24.0, 'y' => 9.0], $this->xy($porNumero[116]));
    }

    public function test_normalizar_descarta_numero_repetido_e_invalido(): void
    {
        $planta = PlantaEvento::normalizar([
            'nome' => '  ',
            'estandes' => [
                ['numero' => 2, 'x' => 1, 'y' => 1],
                ['numero' => 2, 'x' => 5, 'y' => 5],   // repetido: o segundo cai
                ['numero' => 0, 'x' => 0, 'y' => 0],   // sem número: cai
                ['numero' => 1, 'x' => 0, 'y' => 0],
            ],
        ]);

        $this->assertSame([1, 2], array_column($planta['estandes'], 'numero'));
        $this->assertSame('Planta do evento', $planta['nome']);
    }

    public function test_edicao_sem_planta_salva_recebe_a_padrao_para_desenhar(): void
    {
        $this->admin();

        $dados = $this->getJson('/api/v1/admin/mapa/planta')->assertOk()->json('data');

        $this->assertFalse($dados['salva']);
        $this->assertNull($dados['versao']);
        $this->assertCount(230, $dados['layout']['estandes']);
    }

    // --- A ocupação -------------------------------------------------------

    public function test_cada_estande_mostra_o_projeto_dos_dois_turnos(): void
    {
        $this->admin();
        $manha = $this->noEstande('Bioplástico', Turno::A, 42);
        $tarde = $this->noEstande('Sensor de nível', Turno::B, 42);
        $this->noEstande('Horta na escola', Turno::A, 7);

        $dados = $this->getJson('/api/v1/admin/mapa/planta')->assertOk()->json('data');

        $this->assertSame(2, $dados['ocupados']);
        $this->assertSame($manha->titulo, $dados['ocupacao'][42]['A']['titulo']);
        $this->assertSame($tarde->titulo, $dados['ocupacao'][42]['B']['titulo']);
        // Estande com um turno só mostra o outro vazio, em vez de sumir.
        $this->assertSame('Horta na escola', $dados['ocupacao'][7]['A']['titulo']);
        $this->assertNull($dados['ocupacao'][7]['B']);
    }

    // --- O versionamento --------------------------------------------------

    public function test_salvar_cria_uma_versao_nova_e_aposenta_a_anterior(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/mapa/planta', [
            'nome' => 'Planta 2026',
            'estandes' => [['numero' => 1, 'x' => 0, 'y' => 0], ['numero' => 2, 'x' => 1, 'y' => 0]],
            'marcacoes' => [],
        ])->assertOk();

        $this->postJson('/api/v1/admin/mapa/planta', [
            'nome' => 'Planta 2026 (ajustada)',
            'estandes' => [['numero' => 1, 'x' => 0, 'y' => 0]],
            'marcacoes' => [],
        ])->assertOk();

        $this->assertSame(2, MapaLayout::count());
        $vigente = MapaLayout::vigente();
        $this->assertSame(2, $vigente->versao);
        $this->assertSame('Planta 2026 (ajustada)', $vigente->nome);
        $this->assertSame($admin->id, $vigente->criado_por);
        $this->assertSame(1, MapaLayout::where('vigente', true)->count());
    }

    public function test_planta_sem_estande_nenhum_e_recusada(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/mapa/planta', ['estandes' => [], 'marcacoes' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('estandes');
    }

    /** Restaurar não ressuscita a versão antiga: grava o desenho dela como nova. */
    public function test_restaurar_uma_versao_antiga_gera_versao_nova(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/mapa/planta', [
            'estandes' => [['numero' => 1, 'x' => 0, 'y' => 0], ['numero' => 2, 'x' => 1, 'y' => 0]],
        ])->assertOk();
        $primeira = MapaLayout::where('versao', 1)->firstOrFail();

        $this->postJson('/api/v1/admin/mapa/planta', [
            'estandes' => [['numero' => 1, 'x' => 0, 'y' => 0]],
        ])->assertOk();

        $dados = $this->postJson("/api/v1/admin/mapa/planta/{$primeira->id}/restaurar")
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $dados['versao']);
        $this->assertCount(2, $dados['layout']['estandes']);
        $this->assertSame(3, MapaLayout::count());
    }

    public function test_admin_sem_a_aba_mapa_recebe_403(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::atual()?->id]);
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/mapa/planta')->assertForbidden();
    }

    /** @return array{x: float, y: float} */
    private function xy(array $estande): array
    {
        return ['x' => (float) $estande['x'], 'y' => (float) $estande['y']];
    }
}
