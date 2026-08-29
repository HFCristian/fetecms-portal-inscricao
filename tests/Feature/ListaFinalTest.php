<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Cidade;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\Projeto;
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
        $this->projeto('Capital A', Categoria::Fetecms, $agrarias, 9.9, [], 'EE Capital A');
        $this->projeto('Capital B', Categoria::Fetecms, $agrarias, 9.8, [], 'EE Capital B');
        $this->projeto('Capital C', Categoria::Fetecms, $agrarias, 9.7, [], 'EE Capital C');
        $this->doInterior($this->projeto('Interior A', Categoria::Fetecms, $agrarias, 5.0, [], 'EE Interior A'), $interior);
        $this->doInterior($this->projeto('Interior B', Categoria::Fetecms, $agrarias, 4.0, [], 'EE Interior B'), $interior);

        // 4 vagas na área, 50% (2) reservadas ao interior.
        $lista = $this->servico()->gerar([
            'categorias' => [
                'fetecms' => [
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

        $this->projeto('Capital A', Categoria::Fetecms, $agrarias, 9.9, [], 'EE Capital A');
        $this->projeto('Capital B', Categoria::Fetecms, $agrarias, 9.8, [], 'EE Capital B');
        $this->projeto('Capital C', Categoria::Fetecms, $agrarias, 9.7, [], 'EE Capital C');
        $this->doInterior($this->projeto('Interior A', Categoria::Fetecms, $agrarias, 5.0, [], 'EE Interior A'), $interior);

        // 3 vagas com 2 reservadas ao interior, mas só existe 1 projeto do
        // interior: a vaga que sobra volta para a capital.
        $lista = $this->servico()->gerar([
            'categorias' => [
                'fetecms' => [
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
}
