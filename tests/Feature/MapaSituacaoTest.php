<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoDocumento;
use App\Enums\Turno;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\ChecagemEstande;
use App\Models\Cidade;
use App\Models\Credenciamento;
use App\Models\CredenciamentoDocumento;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\Estado;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\ItemConferencia;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
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

    /**
     * Sprint 245 — a partir do segundo dia, o mapa separa quem já tinha sido
     * credenciado num dia anterior de quem chegou hoje pela primeira vez.
     */
    public function test_separa_o_credenciado_em_dia_anterior_do_que_chegou_hoje(): void
    {
        $ontem = $this->noEstande('Chegou ontem', Turno::A, 10);
        $hoje = $this->noEstande('Chegou hoje', Turno::A, 11);
        $this->noEstande('Ainda não chegou', Turno::A, 12);
        $this->credenciar($ontem, now()->subDay()->setTime(9, 0));
        $this->credenciar($hoje, now()->startOfDay()->addMinute());
        $this->admin();

        $dados = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')->assertOk()->json('data');

        $this->assertFalse($dados['primeiro_dia']);
        $this->assertSame('anterior', $dados['estandes'][10]['credenciamento']);
        $this->assertSame('hoje', $dados['estandes'][11]['credenciamento']);
        $this->assertNull($dados['estandes'][12]['credenciamento']);
        $this->assertSame(['anterior' => 1, 'hoje' => 1], $dados['credenciamento']);

        // No dia anterior, quem chegou ontem era a novidade daquele dia.
        $dia = now()->subDay()->toDateString();
        $antes = $this->getJson("/api/v1/admin/mapa/planta/situacao?turno=A&dia={$dia}")->json('data');
        $this->assertSame('hoje', $antes['estandes'][10]['credenciamento']);
        $this->assertNull($antes['estandes'][11]['credenciamento']);

        // O primeiro dia do evento não tem "dia anterior".
        $primeiro = now()->subDays(2)->toDateString();
        $this->assertTrue($this->getJson("/api/v1/admin/mapa/planta/situacao?turno=A&dia={$primeiro}")->json('data.primeiro_dia'));
    }

    public function test_regravar_o_credenciamento_hoje_nao_o_torna_primeira_vez(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto, now()->subDay()->setTime(9, 0));
        $this->admin();

        // Regravar a ficha move o fim do atendimento para agora…
        Credenciamento::where('projeto_id', $projeto->id)->first()->update(['finalizado_em' => now()]);

        $linha = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')->json('data.estandes.42');
        $this->assertSame('anterior', $linha['credenciamento']);
        $this->assertSame(now()->subDay()->setTime(9, 0)->format('d/m/Y H:i'), $linha['credenciado_em']);

        // …e o mapa de ontem continua com ele credenciado.
        $dia = now()->subDay()->toDateString();
        $this->assertTrue($this->getJson("/api/v1/admin/mapa/planta/situacao?turno=A&dia={$dia}")->json('data.estandes.42.credenciado'));
    }

    public function test_corrigir_a_hora_para_antes_corrige_a_primeira_vez(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto);

        $credenciamento = Credenciamento::where('projeto_id', $projeto->id)->first();
        $credenciamento->update(['finalizado_em' => now()->subDay()->setTime(8, 0)]);

        $this->assertSame(
            now()->subDay()->setTime(8, 0)->toDateTimeString(),
            $credenciamento->fresh()->primeiro_finalizado_em->toDateTimeString(),
        );
    }

    public function test_filtra_e_exporta_quem_chegou_hoje_e_quem_ja_tinha_chegado(): void
    {
        $ontem = $this->noEstande('Chegou ontem', Turno::A, 10);
        $hoje = $this->noEstande('Chegou hoje', Turno::A, 11);
        $this->credenciar($ontem, now()->subDay()->setTime(9, 0));
        $this->credenciar($hoje, now()->startOfDay()->addMinute());
        $this->admin();

        $titulos = fn (string $valor) => array_column(
            $this->getJson("/api/v1/admin/mapa/planta/lista?turno=A&criterio=credenciamento&valor={$valor}")->assertOk()->json('data.linhas'),
            'titulo',
        );

        $this->assertSame(['Chegou ontem'], $titulos('anterior'));
        $this->assertSame(['Chegou hoje'], $titulos('hoje'));
        $this->assertSame(['Chegou ontem', 'Chegou hoje'], $titulos('sim'));

        $csv = $this->get('/api/v1/admin/mapa/planta/lista/csv?turno=A&criterio=credenciamento&valor=sim')->assertOk()->getContent();
        $this->assertStringContainsString('Em dia anterior', $csv);
        $this->assertStringContainsString('No dia (1ª vez)', $csv);

        $criterio = collect($this->getJson('/api/v1/admin/mapa/planta')->json('meta.filtros.criterios'))->firstWhere('value', 'credenciamento');
        $this->assertSame('opcoes', $criterio['tipo']);
        $this->assertSame(['sim', 'anterior', 'hoje', 'nao'], array_column($criterio['valores'], 'value'));
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

    /**
     * Sprints 198 e 200 — a tela cheia pinta a faixa da área (com a sigla) e o
     * painel conta, por área, quem já recebeu avaliação da comissão especial.
     */
    public function test_a_linha_traz_a_area_e_as_avaliacoes_da_comissao_especial(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->avaliar($projeto, 2);
        $daComissao = AvaliadorProfile::factory()->create(['comissao_especial' => true])->user;
        AvaliacaoPresencial::create([
            'edicao_id' => Edicao::atual()?->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $daComissao->id,
            'status' => StatusAvaliacao::Concluida->value, 'nota' => 9, 'concluida_em' => now(),
        ]);
        // Em andamento não conta, nem da comissão.
        AvaliacaoPresencial::create([
            'edicao_id' => Edicao::atual()?->id, 'projeto_id' => $projeto->id,
            'avaliador_id' => AvaliadorProfile::factory()->create(['comissao_especial' => true])->user_id,
            'status' => StatusAvaliacao::EmAndamento->value,
        ]);
        $this->admin();

        $linha = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')->assertOk()->json('data.estandes.42');

        $this->assertSame($this->area->id, $linha['area_id']);
        $this->assertSame('AGR', $linha['area_sigla']);
        $this->assertSame(3, $linha['avaliacoes']);
        $this->assertSame(1, $linha['avaliacoes_comite']);

        // A legenda das áreas vem com a planta.
        $this->assertSame(
            [['id' => $this->area->id, 'nome' => 'Ciências Agrárias', 'sigla' => 'AGR']],
            $this->getJson('/api/v1/admin/mapa/planta')->json('meta.filtros.areas'),
        );
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

    /**
     * Sprint 197 — a rua da borda esquerda: ao lado da primeira coluna, sem
     * fileira do outro lado. Nomeável como as outras, e acompanha a coluna.
     */
    public function test_ha_uma_rua_na_borda_esquerda_que_acompanha_a_primeira_coluna(): void
    {
        $planta = PlantaEvento::padrao();
        $borda = collect(PlantaEvento::ruas($planta))->firstWhere('borda', true);

        $this->assertNotNull($borda);
        $this->assertSame('v', $borda['orientacao']);
        $this->assertSame('v:-0.5', $borda['chave']);
        $this->assertEquals(-0.5, $borda['posicao']);

        // A primeira coluna anda duas casas para a direita: a rua vai junto.
        $planta['estandes'] = array_map(fn (array $e) => ['x' => $e['x'] + 2] + $e, $planta['estandes']);
        $this->assertSame('v:1.5', collect(PlantaEvento::ruas($planta))->firstWhere('borda', true)['chave']);

        // E o nome dado a ela é guardado como o de qualquer corredor.
        $planta['ruas'] = ['v:1.5' => 'Rua da Entrada'];
        $this->assertSame('Rua da Entrada', collect(PlantaEvento::ruas($planta))->firstWhere('borda', true)['nome']);
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

    /** Um arquivo do termo de uso de imagem enviado online, valendo para os estudantes dados. */
    private function termoOnline(Projeto $projeto, array $alunos, ?Carbon $quando = null): ProjetoDocumento
    {
        $documento = ProjetoDocumento::factory()->create([
            'projeto_id' => $projeto->id, 'tipo' => TipoDocumento::TermoUsoImagem->value,
            'nome_original' => 'termo.pdf', 'created_at' => $quando ?? now(),
        ]);
        // O arquivo enviado antes vale para pessoas (Sprint 183): aqui, estudantes.
        $documento->forceFill(['item_conferencia_id' => ItemConferencia::where('chave', ItemConferencia::CHAVE_TERMO_IMAGEM)->value('id')])->save();
        foreach ($alunos as $aluno) {
            $documento->pessoas()->create(['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id]);
        }

        return $documento;
    }

    public function test_o_estande_mostra_quem_ja_tem_o_termo_de_imagem_online_ou_no_balcao(): void
    {
        $this->admin();
        $projeto = $this->noEstande('Biofiltro', Turno::A, 42);
        $ana = Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana']);
        $beto = Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Beto']);
        $caio = Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Caio']);

        $arquivo = $this->termoOnline($projeto, [$ana]);

        $estande = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')->assertOk()->json('data.estandes.42');
        $this->assertSame('parcial', $estande['termo_imagem']);
        $this->assertSame(1, $estande['termo_imagem_cobertos']);
        $this->assertSame(3, $estande['termo_imagem_total']);
        $this->assertSame(['Beto', 'Caio'], $estande['termo_imagem_faltando']);
        $this->assertSame([['id' => $arquivo->id, 'nome_original' => 'termo.pdf']], $estande['termo_imagem_arquivos']);

        // No balcão: o Beto mostrou o papel, o Caio foi dispensado.
        $this->credenciar($projeto);
        $item = ItemConferencia::where('chave', ItemConferencia::CHAVE_TERMO_IMAGEM)->firstOrFail();
        $credenciamento = Credenciamento::where('projeto_id', $projeto->id)->firstOrFail();
        foreach ([[$beto, 'presente'], [$caio, 'nao_necessario']] as [$aluno, $situacao]) {
            CredenciamentoDocumento::create([
                'credenciamento_id' => $credenciamento->id, 'item_id' => $item->id,
                'pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id, 'pessoa_nome' => $aluno->nome, 'situacao' => $situacao,
            ]);
        }

        $estande = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')->json('data.estandes.42');
        $this->assertSame('completo', $estande['termo_imagem']);
        $this->assertSame(1, $estande['termo_imagem_online']);
        $this->assertSame(2, $estande['termo_imagem_balcao']);
    }

    public function test_balcao_que_recusou_o_termo_vale_mais_que_o_envio_online(): void
    {
        $this->admin();
        $projeto = $this->noEstande('Biofiltro', Turno::A, 42);
        $ana = Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana']);
        $this->termoOnline($projeto, [$ana]);

        $this->credenciar($projeto);
        CredenciamentoDocumento::create([
            'credenciamento_id' => Credenciamento::where('projeto_id', $projeto->id)->value('id'),
            'item_id' => ItemConferencia::where('chave', 'termo_uso_imagem')->value('id'),
            'pessoa_tipo' => 'aluno', 'pessoa_id' => $ana->id, 'pessoa_nome' => 'Ana', 'situacao' => 'ausente',
        ]);

        $estande = $this->getJson('/api/v1/admin/mapa/planta/situacao?turno=A')->json('data.estandes.42');
        $this->assertSame('nenhum', $estande['termo_imagem']);
        $this->assertSame(['Ana'], $estande['termo_imagem_faltando']);
    }

    public function test_o_dia_escolhido_ignora_termo_enviado_depois(): void
    {
        $this->admin();
        $projeto = $this->noEstande('Biofiltro', Turno::A, 42);
        $ana = Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana']);
        $this->termoOnline($projeto, [$ana], now());

        $ontem = now()->subDay()->toDateString();
        $estande = $this->getJson("/api/v1/admin/mapa/planta/situacao?turno=A&dia={$ontem}")->json('data.estandes.42');

        $this->assertSame('nenhum', $estande['termo_imagem']);
    }

    public function test_filtra_e_exporta_pelo_termo_de_imagem(): void
    {
        $this->admin();
        $completo = $this->noEstande('Completo', Turno::A, 1);
        $parcial = $this->noEstande('Parcial', Turno::A, 2);
        $pendente = $this->noEstande('Pendente', Turno::A, 3);
        $this->termoOnline($completo, [Aluno::factory()->create(['projeto_id' => $completo->id])]);
        $this->termoOnline($parcial, [Aluno::factory()->create(['projeto_id' => $parcial->id])]);
        Aluno::factory()->create(['projeto_id' => $parcial->id, 'nome' => 'Zeca']);
        Aluno::factory()->create(['projeto_id' => $pendente->id]);

        $titulos = fn (string $valor) => array_column(
            $this->getJson("/api/v1/admin/mapa/planta/lista?turno=A&criterio=termo_imagem&valor={$valor}")->assertOk()->json('data.linhas'),
            'titulo',
        );

        $this->assertSame(['Parcial', 'Pendente'], $titulos('incompleto'));
        $this->assertSame(['Completo'], $titulos('completo'));
        $this->assertSame(['Pendente'], $titulos('nenhum'));

        $opcao = collect($this->getJson('/api/v1/admin/mapa/planta')->json('meta.filtros.criterios'))->firstWhere('value', 'termo_imagem');
        $this->assertSame('opcoes', $opcao['tipo']);
        $this->assertSame('incompleto', $opcao['padrao']);

        $csv = $this->get('/api/v1/admin/mapa/planta/lista/csv?turno=A&criterio=termo_imagem&valor=parcial')->assertOk()->getContent();
        $this->assertStringContainsString('Termo de uso de imagem', $csv);
        $this->assertStringContainsString('"1 de 2";"Zeca"', $csv);

        $txt = $this->get('/api/v1/admin/mapa/planta/lista/txt?turno=A&criterio=termo_imagem&valor=parcial')->assertOk()->getContent();
        $this->assertStringContainsString('Termo de imagem: Parcial · 1 de 2 (falta: Zeca)', $txt);

        $this->get('/api/v1/admin/mapa/planta/lista/pdf?turno=A&criterio=termo_imagem&valor=parcial')->assertOk();
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
