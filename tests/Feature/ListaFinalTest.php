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
        $this->cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $this->estado->id]);
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
            'categorias' => ['fetecms' => 2],
            'areas' => [$agrarias->id => 1],
        ]);

        // A cota da área corta as agrárias em 1 (a melhor); a de categoria
        // ainda deixa entrar a exata; a FETEC Jr não tem cota, mas a área já
        // estourou.
        $this->assertSame(['Agrária A', 'Exata A'], array_column($lista, 'titulo'));
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
            ->assertJsonPath('data.areas.0.sigla', 'AGR')
            ->assertJsonPath('data.areas.0.disponiveis', 1);

        $resposta = $this->post('/api/v1/admin/avaliacao/lista-final', ['total' => 1]);

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
}
