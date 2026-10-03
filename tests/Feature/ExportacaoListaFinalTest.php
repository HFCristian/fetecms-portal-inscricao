<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Area;
use App\Models\Coorientador;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\User;
use App\Support\LeitorPlanilha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 160 — exportação da lista final: lista nominal (pessoa a pessoa) e
 * recortes por projeto, em CSV e Excel.
 */
class ExportacaoListaFinalTest extends TestCase
{
    use RefreshDatabase;

    private ListaFinal $lista;

    private Projeto $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $orientador = User::factory()->create(['name' => 'Marta Orientadora', 'email' => 'marta@escola.test']);
        OrientadorProfile::factory()->create(['user_id' => $orientador->id, 'cpf' => '52998224725']);

        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id, 'titulo' => 'Biofiltro', 'edicao_id' => $edicao->id,
            'categoria' => 'fetecms', 'area_id' => $area->id,
            'instituicao_id' => Instituicao::create(['nome' => 'EE Maria Constança'])->id,
        ]);
        Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Zuleica Nunes', 'cpf' => '11144477735']);
        Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Ana Paula', 'cpf' => '22233344405']);
        Coorientador::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Caio Silva']);
        EstandeProjeto::create(['edicao_id' => $edicao->id, 'projeto_id' => $this->projeto->id, 'turno' => 'A', 'numero' => 12]);

        $this->lista = ListaFinal::create([
            'edicao_id' => $edicao->id, 'nome' => 'Lista oficial', 'vigente' => true, 'demo' => false, 'versao' => 2,
        ]);
        $this->lista->projetos()->attach($this->projeto->id);

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /** @return list<list<string>> */
    private function ler(string $conteudo, string $extensao): array
    {
        $caminho = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($caminho, $conteudo);

        return LeitorPlanilha::ler($caminho, $extensao);
    }

    public function test_lista_nominal_em_excel_traz_cada_pessoa_com_funcao_e_codigo(): void
    {
        $resposta = $this->get('/api/v1/admin/avaliacao/listas-finais/'.$this->lista->id.'/exportar?'.http_build_query([
            'nivel' => 'pessoa', 'formato' => 'xlsx', 'modelo' => 'nominal',
            'colunas' => ['nome', 'funcao', 'projeto', 'codigo_projeto', 'instituicao', 'categoria', 'area'],
        ]))->assertOk();

        $this->assertStringContainsString('lista-final-nominal-v2.xlsx', $resposta->headers->get('content-disposition'));
        $linhas = $this->ler($resposta->getContent(), 'xlsx');

        $this->assertSame(['Nome completo', 'Função', 'Projeto', 'Código do projeto', 'Instituição', 'Categoria', 'Área'], $linhas[0]);
        // Orientador, coorientador e estudantes em ordem alfabética.
        $this->assertSame(['Marta Orientadora', 'Caio Silva', 'Ana Paula', 'Zuleica Nunes'], array_column(array_slice($linhas, 1), 0));
        $this->assertSame(['Orientador(a)', 'Coorientador(a)', 'Estudante', 'Estudante'], array_column(array_slice($linhas, 1), 1));
        $this->assertSame('FET.AGR-001', $linhas[1][3]);
        $this->assertSame('EE Maria Constança', $linhas[1][4]);
    }

    public function test_certificados_em_csv_com_cpf_e_codigo_do_cracha(): void
    {
        $resposta = $this->get('/api/v1/admin/avaliacao/listas-finais/'.$this->lista->id.'/exportar?'.http_build_query([
            'nivel' => 'pessoa', 'formato' => 'csv', 'colunas' => ['nome', 'cpf', 'codigo_cracha'],
        ]))->assertOk();

        $csv = $resposta->getContent();
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('"Ana Paula";222.333.444-05;2026-'.$this->projeto->id.'-222-A', $csv);
    }

    public function test_por_projeto_junta_a_equipe_numa_linha(): void
    {
        $resposta = $this->get('/api/v1/admin/avaliacao/listas-finais/'.$this->lista->id.'/exportar?'.http_build_query([
            'nivel' => 'projeto', 'formato' => 'xlsx',
            'colunas' => ['codigo_projeto', 'projeto', 'orientador', 'estudantes', 'qtd_pessoas', 'estande', 'turno'],
        ]))->assertOk();

        $linhas = $this->ler($resposta->getContent(), 'xlsx');
        $this->assertSame(['FET.AGR-001', 'Biofiltro', 'Marta Orientadora', 'Ana Paula; Zuleica Nunes', '4', '12', 'Matutino'], $linhas[1]);
    }

    public function test_coluna_de_outro_nivel_e_ignorada_e_vazio_e_recusado(): void
    {
        $this->getJson('/api/v1/admin/avaliacao/listas-finais/'.$this->lista->id.'/exportar?'.http_build_query([
            'nivel' => 'projeto', 'formato' => 'csv', 'colunas' => ['cpf'],
        ]))->assertStatus(422)->assertJsonValidationErrors('colunas');
    }

    public function test_opcoes_trazem_os_modelos(): void
    {
        $this->getJson('/api/v1/admin/avaliacao/listas-finais/exportar/opcoes')
            ->assertOk()
            ->assertJsonPath('data.modelos.0.chave', 'nominal')
            ->assertJsonPath('data.modelos.0.nivel', 'pessoa');
    }
}
