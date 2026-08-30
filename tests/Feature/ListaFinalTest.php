<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Cidade;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ListaFinalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lista final da feira (Ranking dos projetos → Gerar lista final): seleção pela
 * nota, ordenação por categoria → área → título e numeração 001, 002… por
 * categoria+área.
 */
class ListaFinalTest extends TestCase
{
    use RefreshDatabase;

    private Estado $estado;

    private Cidade $cidade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        // Campo Grande é a capital; o interior entra pelas cidades criadas nos
        // testes que exercitam a reserva.
        $this->cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $this->estado->id, 'capital' => true]);
        // A lista oficial é registrada dentro de uma edição (Sprint 68).
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function area(string $nome, ?string $sigla): Area
    {
        return Area::create(['nome' => $nome, 'sigla' => $sigla]);
    }

    /**
     * Projeto submetido com escola, alunos, orientador e uma avaliação
     * concluída com a nota informada.
     *
     * @param  list<string>  $alunos
     */
    private function projeto(
        string $titulo,
        Categoria $categoria,
        ?Area $area,
        float $nota,
        array $alunos = [],
        ?string $escola = null,
        string $orientador = 'Marta Orientadora',
    ): Projeto {
        $instituicao = $escola === null ? null : Instituicao::create([
            'nome' => $escola, 'cidade_id' => $this->cidade->id,
        ]);

        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['name' => $orientador])->id,
            'titulo' => $titulo,
            'categoria' => $categoria,
            'area_id' => $area?->id,
            'instituicao_id' => $instituicao?->id,
        ]);

        foreach ($alunos as $nome) {
            Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => $nome]);
        }

        Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create()->id,
            'status' => StatusAvaliacao::Concluida,
            'nota' => $nota,
            'concluida_em' => now(),
        ]);

        return $projeto;
    }

    private function servico(): ListaFinalService
    {
        return app(ListaFinalService::class);
    }

    public function test_txt_sai_no_formato_do_edital(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');

        $this->projeto(
            'Horta na escola',
            Categoria::Fetecms,
            $agrarias,
            9.0,
            ['Zuleica Nunes', 'Ana Paula'],
            'EE Maria Constança',
        );

        $txt = $this->servico()->exportarTxt([]);

        $this->assertSame(
            "FET.AGR-001 - Horta na escola\n"
            ."EE Maria Constança / Campo Grande - MS\n"
            ."Ana Paula\n"          // alunos em ordem alfabética
            ."Zuleica Nunes\n"
            ."Marta Orientadora - Orientador(a)\n",
            $txt,
        );
    }

    public function test_ordena_por_categoria_area_e_titulo_com_sequencial_proprio(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $exatas = $this->area('Ciências Exatas e da Terra', 'EXA');

        // A ordem de criação e as notas são propositalmente embaralhadas.
        $this->projeto('Zebra solar', Categoria::FetecmsFundect, $exatas, 10.0);
        $this->projeto('Beterraba', Categoria::Fetecms, $agrarias, 5.0);
        $this->projeto('Abacaxi', Categoria::Fetecms, $agrarias, 6.0);
        $this->projeto('Foguete', Categoria::FetecJr, $exatas, 9.0);

        $codigos = array_column($this->servico()->gerar([]), 'codigo');

        $this->assertSame(['FET.AGR-001', 'FET.AGR-002', 'JR.EXA-001', 'PIC.EXA-001'], $codigos);

        // Dentro de FET.AGR, o alfabético manda — e não a nota.
        $titulos = array_column($this->servico()->gerar([]), 'titulo');
        $this->assertSame(['Abacaxi', 'Beterraba', 'Foguete', 'Zebra solar'], $titulos);
    }

    public function test_cota_total_pega_os_mais_bem_avaliados(): void
    {
        $area = $this->area('Ciências Agrárias', 'AGR');

        $this->projeto('Pior', Categoria::Fetecms, $area, 4.0);
        $this->projeto('Melhor', Categoria::Fetecms, $area, 9.5);
        $this->projeto('Mediano', Categoria::Fetecms, $area, 7.0);

        $titulos = array_column($this->servico()->gerar(['total' => 2]), 'titulo');

        // Entram os dois melhores, mas o arquivo sai em ordem alfabética.
        $this->assertSame(['Mediano', 'Melhor'], $titulos);
    }

    public function test_cotas_por_categoria_e_area_valem_ao_mesmo_tempo(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $exatas = $this->area('Ciências Exatas e da Terra', 'EXA');

        $this->projeto('Agrária A', Categoria::Fetecms, $agrarias, 9.0);
        $this->projeto('Agrária B', Categoria::Fetecms, $agrarias, 8.0);
        $this->projeto('Exata A', Categoria::Fetecms, $exatas, 7.0);
        $this->projeto('Jr agrária', Categoria::FetecJr, $agrarias, 6.0);

        $lista = $this->servico()->gerar([
            'categorias' => [
                'fetecms' => [
                    'cota' => 2,
                    'areas' => [$agrarias->id => ['cota' => 1]],
                ],
            ],
        ]);

        // A cota de área vale DENTRO da categoria: em FETECMS as agrárias
        // param na melhor (1 vaga) e a exata ainda cabe nas 2 da categoria. A
        // FETEC Jr não tem cota nenhuma, então entra inteira — a cota das
        // agrárias da FETECMS não a alcança.
        $this->assertSame(['Agrária A', 'Exata A', 'Jr agrária'], array_column($lista, 'titulo'));
    }

    public function test_projeto_sem_escola_usa_a_localidade_do_projeto(): void
    {
        $area = $this->area('Engenharias', 'ENG');
        $projeto = $this->projeto('Sem escola', Categoria::Fetecms, $area, 8.0);
        $projeto->update(['estado_id' => $this->estado->id, 'cidade_id' => $this->cidade->id]);

        $this->assertSame('Campo Grande - MS', $this->servico()->gerar([])[0]['escola']);
    }

    public function test_area_sem_sigla_cai_para_as_tres_primeiras_letras(): void
    {
        $area = $this->area('Robótica Educacional', null);
        $this->projeto('Braço mecânico', Categoria::FetecJr, $area, 8.0);

        $this->assertSame('JR.ROB-001', $this->servico()->gerar([])[0]['codigo']);
    }

    public function test_endpoint_baixa_o_txt_e_lista_as_opcoes(): void
    {
        $area = $this->area('Ciências Agrárias', 'AGR');
        $this->projeto('Horta', Categoria::Fetecms, $area, 8.0, [], 'EE Maria Constança');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/lista-final/opcoes')
            ->assertOk()
            ->assertJsonPath('data.total_disponivel', 1)
            ->assertJsonPath('data.categorias.0.sigla', 'FET')
            ->assertJsonPath('data.categorias.0.areas.0.sigla', 'AGR')
            ->assertJsonPath('data.categorias.0.areas.0.disponiveis', 1);

        $resposta = $this->post('/api/v1/admin/avaliacao/lista-final', [
            'total' => ['tipo' => 'fixo', 'valor' => 1],
        ]);

        $resposta->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('FET.AGR-001 - Horta', $resposta->getContent());
    }

    public function test_admin_define_a_sigla_da_area(): void
    {
        $area = $this->area('Robótica Educacional', null);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/areas/{$area->id}/sigla", ['sigla' => 'rob'])
            ->assertOk()
            ->assertJsonPath('data.0.sigla', 'ROB');

        $this->patchJson("/api/v1/admin/areas/{$area->id}/sigla", ['sigla' => 'ROBO'])
            ->assertStatus(422)->assertJsonValidationErrors('sigla');
    }

    // --- Cotas em porcentagem e reserva para o interior ---

    /** Cidade do interior (não capital) para os testes de reserva. */
    private function interior(string $nome = 'Dourados'): Cidade
    {
        return Cidade::firstOrCreate(
            ['nome' => $nome, 'estado_id' => $this->estado->id],
            ['capital' => false],
        );
    }

    public function test_cota_em_porcentagem_e_calculada_sobre_o_recorte_de_cima(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');

        foreach (range(1, 10) as $i) {
            $this->projeto('Projeto '.$i, Categoria::Fetecms, $agrarias, 10 - ($i / 10));
        }

        // 50% de 10 elegíveis = 5 na categoria; 40% desses 5 = 2 na área.
        $lista = $this->servico()->gerar([
            'categorias' => [
                'fetecms' => [
                    'cota' => ['tipo' => 'percentual', 'valor' => 50],
                    'areas' => [$agrarias->id => ['cota' => ['tipo' => 'percentual', 'valor' => 40]]],
                ],
            ],
        ]);

        $this->assertCount(2, $lista);
        // Os dois melhores: Projeto 1 (9,9) e Projeto 2 (9,8).
        $this->assertSame(['Projeto 1', 'Projeto 2'], array_column($lista, 'titulo'));
    }

    public function test_reserva_do_interior_segura_as_vagas_para_quem_e_do_interior(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $interior = $this->interior();

        // As melhores notas são todas da capital; o interior vem depois.
        // A reserva só existe na FUNDECT (Sprint 68).
        $this->projeto('Capital A', Categoria::FetecmsFundect, $agrarias, 9.9, [], 'EE Capital A');
        $this->projeto('Capital B', Categoria::FetecmsFundect, $agrarias, 9.8, [], 'EE Capital B');
        $this->projeto('Capital C', Categoria::FetecmsFundect, $agrarias, 9.7, [], 'EE Capital C');
        $this->doInterior($this->projeto('Interior A', Categoria::FetecmsFundect, $agrarias, 5.0, [], 'EE Interior A'), $interior);
        $this->doInterior($this->projeto('Interior B', Categoria::FetecmsFundect, $agrarias, 4.0, [], 'EE Interior B'), $interior);

        // 4 vagas na área, 50% (2) reservadas ao interior.
        $lista = $this->servico()->gerar([
            'categorias' => [
                'fetecms_fundect' => [
                    'areas' => [$agrarias->id => [
                        'cota' => 4,
                        'interior' => ['tipo' => 'percentual', 'valor' => 50],
                    ]],
                ],
            ],
        ]);

        $titulos = array_column($lista, 'titulo');
        sort($titulos);
        // Só duas da capital entram, mesmo sendo as melhores.
        $this->assertSame(['Capital A', 'Capital B', 'Interior A', 'Interior B'], $titulos);
    }

    public function test_reserva_nao_preenchida_devolve_a_vaga_para_os_demais(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $interior = $this->interior();

        $this->projeto('Capital A', Categoria::FetecmsFundect, $agrarias, 9.9, [], 'EE Capital A');
        $this->projeto('Capital B', Categoria::FetecmsFundect, $agrarias, 9.8, [], 'EE Capital B');
        $this->projeto('Capital C', Categoria::FetecmsFundect, $agrarias, 9.7, [], 'EE Capital C');
        $this->doInterior($this->projeto('Interior A', Categoria::FetecmsFundect, $agrarias, 5.0, [], 'EE Interior A'), $interior);

        // 3 vagas com 2 reservadas ao interior, mas só existe 1 projeto do
        // interior: a vaga que sobra volta para a capital.
        $lista = $this->servico()->gerar([
            'categorias' => [
                'fetecms_fundect' => [
                    'areas' => [$agrarias->id => ['cota' => 3, 'interior' => 2]],
                ],
            ],
        ]);

        $titulos = array_column($lista, 'titulo');
        sort($titulos);
        $this->assertSame(['Capital A', 'Capital B', 'Interior A'], $titulos);
    }

    public function test_reserva_do_interior_sem_cota_na_area_nao_tem_efeito(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');

        $this->projeto('Capital A', Categoria::Fetecms, $agrarias, 9.9, [], 'EE Capital A');
        $this->projeto('Capital B', Categoria::Fetecms, $agrarias, 9.8, [], 'EE Capital B');

        $lista = $this->servico()->gerar([
            'categorias' => [
                'fetecms' => [
                    'areas' => [$agrarias->id => ['interior' => ['tipo' => 'percentual', 'valor' => 100]]],
                ],
            ],
        ]);

        $this->assertCount(2, $lista);
    }

    /** Move a escola do projeto para uma cidade do interior. */
    private function doInterior(Projeto $projeto, Cidade $cidade): Projeto
    {
        $projeto->instituicao?->update(['cidade_id' => $cidade->id]);

        return $projeto;
    }

    // --- Sprint 68: reserva do interior só na FUNDECT + lista final oficial ---

    public function test_reserva_do_interior_e_ignorada_fora_da_fundect(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $interior = $this->interior();

        $this->projeto('Capital A', Categoria::Fetecms, $agrarias, 9.9, [], 'EE Capital A');
        $this->projeto('Capital B', Categoria::Fetecms, $agrarias, 9.8, [], 'EE Capital B');
        $this->doInterior($this->projeto('Interior A', Categoria::Fetecms, $agrarias, 5.0, [], 'EE Interior A'), $interior);

        // Mesma configuração que na FUNDECT seguraria uma vaga: aqui não segura.
        $lista = $this->servico()->gerar([
            'categorias' => [
                'fetecms' => [
                    'areas' => [$agrarias->id => ['cota' => 2, 'interior' => 2]],
                ],
            ],
        ]);

        $titulos = array_column($lista, 'titulo');
        sort($titulos);
        $this->assertSame(['Capital A', 'Capital B'], $titulos);
    }

    public function test_opcoes_dizem_quais_categorias_reservam_vaga_ao_interior(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $categorias = $this->getJson('/api/v1/admin/avaliacao/lista-final/opcoes')
            ->assertOk()
            ->json('data.categorias');

        $porValor = collect($categorias)->keyBy('value');
        $this->assertTrue($porValor['fetecms_fundect']['permite_interior']);
        $this->assertFalse($porValor['fetecms']['permite_interior']);
        $this->assertFalse($porValor['fetec_jr']['permite_interior']);
    }

    public function test_marcar_como_oficial_registra_a_lista_e_os_finalistas(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $melhor = $this->projeto('Melhor', Categoria::Fetecms, $agrarias, 9.9, ['Ana'], 'EE Alfa');
        $this->projeto('Pior', Categoria::Fetecms, $agrarias, 4.0, ['Beto'], 'EE Beta');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/avaliacao/lista-final', [
            'oficial' => true,
            'nome' => 'Lista oficial 2026',
            'total' => ['tipo' => 'fixo', 'valor' => 1],
        ])->assertOk()->assertSee('Melhor', false);

        $lista = ListaFinal::where('nome', 'Lista oficial 2026')->first();
        $this->assertNotNull($lista);
        $this->assertTrue($lista->vigente);
        $this->assertSame(1, $lista->versao);
        $this->assertSame($admin->id, $lista->gerada_por);
        $this->assertSame([$melhor->id], $lista->projetos()->pluck('projetos.id')->all());

        // É a lista vigente da edição — a que define os finalistas.
        $this->assertSame($lista->id, ListaFinal::vigente()->id);
    }

    public function test_gerar_sem_marcar_oficial_nao_registra_nada(): void
    {
        $this->projeto('Único', Categoria::Fetecms, $this->area('Ciências Agrárias', 'AGR'), 9.0);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/avaliacao/lista-final', [])->assertOk();

        $this->assertSame(0, ListaFinal::count());
    }

    public function test_uma_lista_oficial_por_vez_encerra_a_anterior(): void
    {
        $this->projeto('Único', Categoria::Fetecms, $this->area('Ciências Agrárias', 'AGR'), 9.0);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/avaliacao/lista-final', ['oficial' => true, 'nome' => 'Primeira'])->assertOk();
        $this->postJson('/api/v1/admin/avaliacao/lista-final', ['oficial' => true, 'nome' => 'Segunda'])->assertOk();

        $this->assertFalse(ListaFinal::where('nome', 'Primeira')->first()->vigente);
        $this->assertSame('Segunda', ListaFinal::vigente()->nome);
        $this->assertSame(2, ListaFinal::count());
    }

    public function test_listas_oficiais_aparecem_no_painel_e_baixam_o_txt(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $this->projeto('Bioplástico', Categoria::Fetecms, $agrarias, 9.0, ['Ana'], 'EE Alfa');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/avaliacao/lista-final', ['oficial' => true, 'nome' => 'Oficial'])->assertOk();

        $listas = $this->getJson('/api/v1/admin/avaliacao/listas-finais')->assertOk()->json('data');
        $this->assertCount(1, $listas);
        $this->assertSame('Oficial', $listas[0]['nome']);
        $this->assertTrue($listas[0]['vigente']);
        $this->assertSame(1, $listas[0]['projetos']);

        $lista = ListaFinal::first();
        $txt = $this->get("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/arquivo")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('FET.AGR-001 - Bioplástico', $txt);
        $this->assertStringContainsString('EE Alfa / Campo Grande - MS', $txt);
        $this->assertStringContainsString('Ana', $txt);
    }

    // --- Sprint 69: alterar a composição da lista oficial ---

    /** Oficializa e devolve a lista criada. */
    private function oficializar(User $admin, array $payload = []): ListaFinal
    {
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/avaliacao/lista-final', $payload + ['oficial' => true, 'nome' => 'Oficial'])
            ->assertOk();

        return ListaFinal::where('nome', 'Oficial')->firstOrFail();
    }

    public function test_admin_inclui_projeto_na_lista_com_justificativa(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $this->projeto('Melhor', Categoria::Fetecms, $agrarias, 9.9);
        $forade = $this->projeto('De fora', Categoria::Fetecms, $agrarias, 4.0);

        $admin = User::factory()->admin()->create();
        $lista = $this->oficializar($admin, ['total' => ['tipo' => 'fixo', 'valor' => 1]]);
        $this->assertSame(1, $lista->projetos()->count());

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos", [
            'projeto_id' => $forade->id,
            'justificativa' => 'Recurso deferido pela comissão organizadora.',
        ])->assertOk()->assertJsonPath('data.lista.projetos', 2);

        $lista->refresh();
        $this->assertSame(2, $lista->versao);
        $this->assertTrue($lista->projetos()->whereKey($forade->id)->exists());
        // Entrou à mão: fica marcado na composição.
        $this->assertSame(1, (int) $lista->projetos()->whereKey($forade->id)->first()->pivot->manual);

        $this->assertDatabaseHas('registros_atividade', [
            'tipo' => TipoRegistro::ListaFinalProjetoAdicionado->value,
            'projeto_id' => $forade->id,
            'autor_email' => $admin->email,
        ]);
    }

    public function test_admin_retira_projeto_da_lista_com_justificativa(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $dentro = $this->projeto('Dentro', Categoria::Fetecms, $agrarias, 9.9);

        $admin = User::factory()->admin()->create();
        $lista = $this->oficializar($admin);

        $this->deleteJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$dentro->id}", [
            'justificativa' => 'Projeto desclassificado por descumprimento do edital.',
        ])->assertOk()->assertJsonPath('data.lista.projetos', 0);

        $lista->refresh();
        $this->assertSame(2, $lista->versao);
        $this->assertFalse($lista->projetos()->whereKey($dentro->id)->exists());

        $registro = RegistroAtividade::where('tipo', TipoRegistro::ListaFinalProjetoRemovido)->first();
        $this->assertNotNull($registro);
        $this->assertStringContainsString('desclassificado', $registro->detalhes['justificativa']);
    }

    public function test_alteracao_sem_justificativa_e_recusada(): void
    {
        $dentro = $this->projeto('Dentro', Categoria::Fetecms, $this->area('Ciências Agrárias', 'AGR'), 9.0);
        $lista = $this->oficializar(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos", ['projeto_id' => $dentro->id])
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->deleteJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$dentro->id}")
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->assertSame(1, $lista->fresh()->versao);
    }

    public function test_nao_duplica_projeto_ja_presente_nem_remove_quem_nao_esta(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $dentro = $this->projeto('Dentro', Categoria::Fetecms, $agrarias, 9.9);
        $fora = $this->projeto('Fora', Categoria::Fetecms, $agrarias, 4.0);

        $lista = $this->oficializar(User::factory()->admin()->create(), ['total' => ['tipo' => 'fixo', 'valor' => 1]]);

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos", [
            'projeto_id' => $dentro->id, 'justificativa' => 'Tentativa duplicada.',
        ])->assertStatus(422)->assertJsonValidationErrors('projeto_id');

        $this->deleteJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$fora->id}", [
            'justificativa' => 'Não está na lista.',
        ])->assertStatus(422)->assertJsonValidationErrors('projeto_id');
    }

    public function test_o_arquivo_reflete_a_composicao_depois_da_alteracao(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $this->projeto('Bioplástico', Categoria::Fetecms, $agrarias, 9.9, ['Ana'], 'EE Alfa');
        $novo = $this->projeto('Robótica', Categoria::Fetecms, $agrarias, 4.0, ['Beto'], 'EE Beta');

        $admin = User::factory()->admin()->create();
        $lista = $this->oficializar($admin, ['total' => ['tipo' => 'fixo', 'valor' => 1]]);

        $antes = $this->get("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/arquivo")->getContent();
        $this->assertStringNotContainsString('Robótica', $antes);

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos", [
            'projeto_id' => $novo->id, 'justificativa' => 'Incluído por decisão da comissão.',
        ])->assertOk();

        $depois = $this->get("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/arquivo")->getContent();
        $this->assertStringContainsString('Robótica', $depois);
        // A numeração é refeita: os dois projetos da mesma categoria+área.
        $this->assertStringContainsString('FET.AGR-001', $depois);
        $this->assertStringContainsString('FET.AGR-002', $depois);
    }

    public function test_o_detalhe_da_lista_traz_composicao_e_candidatos(): void
    {
        $agrarias = $this->area('Ciências Agrárias', 'AGR');
        $this->projeto('Dentro', Categoria::Fetecms, $agrarias, 9.9);
        $fora = $this->projeto('Fora', Categoria::Fetecms, $agrarias, 4.0);

        $lista = $this->oficializar(User::factory()->admin()->create(), ['total' => ['tipo' => 'fixo', 'valor' => 1]]);

        $dados = $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}")->assertOk()->json('data');

        $this->assertCount(1, $dados['itens']);
        $this->assertSame('Dentro', $dados['itens'][0]['titulo']);
        $this->assertFalse($dados['itens'][0]['manual']);
        $this->assertSame([$fora->id], array_column($dados['candidatos'], 'id'));
    }

    public function test_as_mudancas_aparecem_na_secao_lista_final_dos_registros(): void
    {
        $novo = $this->projeto('Robótica', Categoria::Fetecms, $this->area('Ciências Agrárias', 'AGR'), 9.0);
        $admin = User::factory()->admin()->create();
        $lista = $this->oficializar($admin);

        $this->deleteJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/projetos/{$novo->id}", [
            'justificativa' => 'Retirado a pedido da escola.',
        ])->assertOk();

        $resposta = $this->getJson('/api/v1/admin/registros?secao='.TipoRegistro::SECAO_LISTA_FINAL)->assertOk();
        $tipos = array_column($resposta->json('data'), 'tipo');

        $this->assertContains(TipoRegistro::ListaFinalOficializada->value, $tipos);
        $this->assertContains(TipoRegistro::ListaFinalProjetoRemovido->value, $tipos);
    }
}
