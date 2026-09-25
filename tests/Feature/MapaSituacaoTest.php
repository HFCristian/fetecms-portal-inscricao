<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\ChecagemEstande;
use App\Models\Cidade;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\Estado;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\PlantaEvento;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mapa do Evento → a planta que **muda de cor** conforme a feira acontece.
 *
 * O que estes testes seguram:
 * - os três estágios saem de fatos já registrados com hora (credenciamento,
 *   checagem, avaliação presencial) — nada é marcado à mão;
 * - o **seletor de dia** é um corte no tempo: escolher ontem devolve o mapa
 *   como ele estava no fim de ontem;
 * - o **turno** separa os dois projetos que dividem o mesmo estande;
 * - as **ruas** são achadas no desenho e o admin só as batiza;
 * - o filtro e a exportação respondem ao mesmo recorte da tela.
 */
class MapaSituacaoTest extends TestCase
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
            'evento_de' => now()->subDays(2)->startOfDay(),
            'evento_ate' => now()->addDay()->endOfDay(),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

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

    private function credenciar(Projeto $projeto, ?Carbon $quando = null): void
    {
        Credenciamento::create([
            'projeto_id' => $projeto->id,
            'credenciado_por' => User::factory()->admin()->create()->id,
            'finalizado_em' => $quando ?? now(),
        ]);
    }

    private function checar(Projeto $projeto, ?Carbon $quando = null): void
    {
        ChecagemEstande::create([
            'projeto_id' => $projeto->id,
            'verificado_por' => User::factory()->admin()->create()->id,
            'verificado_em' => $quando ?? now(),
        ]);
    }

    private function avaliar(Projeto $projeto, int $quantas, ?Carbon $quando = null): void
    {
        for ($i = 0; $i < $quantas; $i++) {
            AvaliacaoPresencial::create([
                'edicao_id' => Edicao::atual()?->id,
                'projeto_id' => $projeto->id,
                'avaliador_id' => User::factory()->avaliador()->create()->id,
                'status' => StatusAvaliacao::Concluida->value,
                'nota' => 8,
                'concluida_em' => $quando ?? now(),
            ]);
        }
    }

    // ------------------------------------------------------------------ //
    // Os quatro estágios                                                  //
    // ------------------------------------------------------------------ //

    public function test_o_estande_muda_de_cor_a_cada_etapa_do_evento(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->admin();

        $situacao = fn () => $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')
            ->assertOk()->json('data.estandes.42.situacao');

        // Alocado e nada mais: o projeto existe, a equipe não chegou.
        $this->assertSame('aguardando', $situacao());

        $this->credenciar($projeto);
        $this->assertSame('credenciado', $situacao());

        $this->checar($projeto);
        $this->assertSame('checado', $situacao());

        $this->avaliar($projeto, 1);
        $this->assertSame('avaliado', $situacao());
    }

    public function test_conta_avaliacoes_realizadas_e_faltantes(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto);
        $this->avaliar($projeto, 2);
        $this->admin();

        $estande = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')
            ->assertOk()->json('data.estandes.42');

        $this->assertSame(2, $estande['avaliacoes']);
        $this->assertSame(1, $estande['avaliacoes_faltantes']);
        $this->assertSame(AvaliacaoPresencial::MAX_POR_PROJETO, $estande['avaliacoes_maximo']);
    }

    /** Avaliação em andamento não pinta o estande: só a concluída conta. */
    public function test_avaliacao_em_andamento_nao_conta(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto);

        AvaliacaoPresencial::create([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create()->id,
            'status' => StatusAvaliacao::EmAndamento->value,
            'iniciada_em' => now(),
        ]);

        $this->admin();

        $estande = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')
            ->assertOk()->json('data.estandes.42');

        $this->assertSame(0, $estande['avaliacoes']);
        $this->assertSame('credenciado', $estande['situacao']);
    }

    // ------------------------------------------------------------------ //
    // Turno e dia                                                         //
    // ------------------------------------------------------------------ //

    public function test_cada_turno_mostra_o_seu_projeto_no_mesmo_estande(): void
    {
        $manha = $this->noEstande('Projeto da manhã', Turno::A, 42);
        $this->noEstande('Projeto da tarde', Turno::B, 42);
        $this->credenciar($manha);
        $this->admin();

        $a = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')->assertOk()->json('data.estandes.42');
        $b = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=B')->assertOk()->json('data.estandes.42');

        $this->assertSame('Projeto da manhã', $a['titulo']);
        $this->assertSame('credenciado', $a['situacao']);
        $this->assertSame('Projeto da tarde', $b['titulo']);
        $this->assertSame('aguardando', $b['situacao']);
    }

    /**
     * O seletor de dia é um corte no tempo, e é o que dispensa gravar
     * instantâneo: o mapa de ontem é o de hoje lido mais cedo.
     */
    public function test_o_dia_escolhido_corta_o_que_ja_tinha_acontecido(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        // Credenciado anteontem, checado hoje.
        $this->credenciar($projeto, now()->subDays(2)->setTime(10, 0));
        $this->checar($projeto);
        $this->admin();

        $ontem = now()->subDay()->toDateString();
        $anteontem = now()->subDays(2)->toDateString();

        $hoje = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')->json('data.estandes.42');
        $this->assertSame('checado', $hoje['situacao']);

        // No fim de anteontem ele estava só credenciado…
        $antes = $this->getJson("/api/v1/admin/mapa/planta/situacao?turno=A&dia={$anteontem}")->json('data.estandes.42');
        $this->assertSame('credenciado', $antes['situacao']);

        // …e ontem continuava assim, porque a checagem ainda não tinha acontecido.
        $meio = $this->getJson("/api/v1/admin/mapa/planta/situacao?turno=A&dia={$ontem}")->json('data.estandes.42');
        $this->assertSame('credenciado', $meio['situacao']);
    }

    public function test_os_dias_oferecidos_sao_os_da_janela_do_evento(): void
    {
        $this->admin();

        $dias = $this->getJson('/api/v1/admin/mapa/planta')->assertOk()->json('meta.filtros.dias');

        // De anteontem a amanhã: quatro dias.
        $this->assertCount(4, $dias);
        $this->assertSame(now()->subDays(2)->toDateString(), $dias[0]['value']);
        $this->assertTrue(collect($dias)->firstWhere('hoje', true) !== null);
    }

    // ------------------------------------------------------------------ //
    // Ruas                                                                //
    // ------------------------------------------------------------------ //

    /**
     * As ruas são os corredores, e corredor é o que sobra entre duas fileiras —
     * por isso elas são achadas no desenho, e não desenhadas de novo.
     */
    public function test_a_planta_padrao_tem_os_corredores_entre_as_ilhas(): void
    {
        $ruas = PlantaEvento::ruas(PlantaEvento::padrao());

        $this->assertNotEmpty($ruas);
        // O corredor central, entre o bloco de cima e o de baixo.
        $this->assertNotNull(collect($ruas)->firstWhere('orientacao', 'h'));
        // E os corredores entre as ilhas de duas colunas.
        $this->assertGreaterThan(4, collect($ruas)->where('orientacao', 'v')->count());
        $this->assertNull($ruas[0]['nome']);
    }

    public function test_admin_batiza_um_corredor_e_o_nome_sobrevive_a_nova_versao(): void
    {
        $this->admin();
        $planta = PlantaEvento::padrao();
        $chave = PlantaEvento::ruas($planta)[0]['chave'];

        $resposta = $this->postJson('/api/v1/admin/mapa/planta', [
            'nome' => 'Planta 2026',
            'estandes' => $planta['estandes'],
            'marcacoes' => [],
            'ruas' => [$chave => 'Rua das Agrárias'],
        ])->assertOk();

        $rua = collect($resposta->json('data.ruas'))->firstWhere('chave', $chave);
        $this->assertSame('Rua das Agrárias', $rua['nome']);

        // E volta assim na leitura seguinte.
        $this->assertSame(
            'Rua das Agrárias',
            collect($this->getJson('/api/v1/admin/mapa/planta')->json('data.ruas'))
                ->firstWhere('chave', $chave)['nome'],
        );
    }

    public function test_nome_de_rua_com_chave_invalida_e_descartado(): void
    {
        $planta = PlantaEvento::normalizar([
            'estandes' => [['numero' => 1, 'x' => 0, 'y' => 0]],
            'ruas' => ['h:8' => 'Rua boa', 'qualquer-coisa' => 'Rua inventada', 'v:2' => '   '],
        ]);

        $this->assertSame(['h:8' => 'Rua boa'], $planta['ruas']);
    }

    // ------------------------------------------------------------------ //
    // Filtro e exportação                                                 //
    // ------------------------------------------------------------------ //

    public function test_filtra_por_credenciamento_e_por_checagem(): void
    {
        $credenciado = $this->noEstande('Com credenciamento', Turno::A, 10);
        $this->noEstande('Sem credenciamento', Turno::A, 11);
        $this->credenciar($credenciado);
        $this->admin();

        $sim = $this->getJson('/api/v1/admin/mapa/planta/lista?turno=A&criterio=credenciamento&valor=sim')
            ->assertOk()->json('data');
        $this->assertSame(1, $sim['total']);
        $this->assertSame('Com credenciamento', $sim['linhas'][0]['titulo']);

        $nao = $this->getJson('/api/v1/admin/mapa/planta/lista?turno=A&criterio=credenciamento&valor=nao')
            ->assertOk()->json('data');
        $this->assertSame(1, $nao['total']);
        $this->assertSame('Sem credenciamento', $nao['linhas'][0]['titulo']);

        // Ninguém foi checado ainda.
        $this->assertSame(0, $this->getJson('/api/v1/admin/mapa/planta/lista?turno=A&criterio=checagem&valor=sim')
            ->json('data.total'));
    }

    public function test_filtra_por_quantidade_de_avaliacoes(): void
    {
        $duas = $this->noEstande('Com duas', Turno::A, 10);
        $nenhuma = $this->noEstande('Sem nenhuma', Turno::A, 11);
        $this->avaliar($duas, 2);
        $this->admin();

        $realizadas = $this->getJson('/api/v1/admin/mapa/planta/lista?turno=A&criterio=avaliacoes_realizadas&valor=2')
            ->assertOk()->json('data');
        $this->assertSame(1, $realizadas['total']);
        $this->assertSame('Com duas', $realizadas['linhas'][0]['titulo']);

        // Faltantes é o outro lado da mesma conta: 3 - 0 = 3.
        $faltantes = $this->getJson('/api/v1/admin/mapa/planta/lista?turno=A&criterio=avaliacoes_faltantes&valor=3')
            ->assertOk()->json('data');
        $this->assertSame(1, $faltantes['total']);
        $this->assertSame('Sem nenhuma', $faltantes['linhas'][0]['titulo']);
        $this->assertSame($nenhuma->id, $faltantes['linhas'][0]['projeto_id']);
    }

    public function test_exporta_o_recorte_em_txt_csv_e_pdf(): void
    {
        $projeto = $this->noEstande('Bioplástico de mandioca', Turno::A, 42);
        $this->credenciar($projeto);
        $this->admin();

        $txt = $this->get('/api/v1/admin/mapa/planta/lista/txt?turno=A&criterio=credenciamento&valor=sim')
            ->assertOk()->getContent();
        $this->assertStringContainsString('Bioplástico de mandioca', $txt);
        $this->assertStringContainsString('042', $txt);

        $csv = $this->get('/api/v1/admin/mapa/planta/lista/csv?turno=A&criterio=credenciamento&valor=sim')
            ->assertOk()->getContent();
        $this->assertStringContainsString('Avaliações faltantes', $csv);
        $this->assertStringContainsString('Bioplástico de mandioca', $csv);

        $pdf = $this->get('/api/v1/admin/mapa/planta/lista/pdf?turno=A&criterio=credenciamento&valor=sim')
            ->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_admin_sem_a_aba_mapa_nao_ve_a_situacao(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::atual()?->id]);
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/mapa/planta/situacao')->assertForbidden();
        $this->getJson('/api/v1/admin/mapa/planta/lista')->assertForbidden();
    }
}
