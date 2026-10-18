<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comitê especial → Designações: o admin do comitê designa projetos **só** para
 * os avaliadores da comissão especial.
 */
class ComiteDesignacaoTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->area = Area::create(['nome' => 'Ciências Exatas']);
        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);
    }

    private function avaliador(string $nome, bool $comissao): User
    {
        $user = User::factory()->avaliador()->create([
            'name' => $nome,
            'email' => Str::slug($nome, '.').'@fetec.test',
        ]);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id,
            'area_id' => $this->area->id,
            'comissao_especial' => $comissao,
        ]);

        return $user->fresh();
    }

    private function projeto(string $titulo): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'titulo' => $titulo,
            'area_id' => $this->area->id,
        ]);
    }

    /** Um admin cujo escopo abre **apenas** a aba Comitê. */
    private function adminDoComite(): User
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_as_opcoes_trazem_so_avaliadores_da_comissao(): void
    {
        $daComissao = $this->avaliador('Ana Comissao', comissao: true);
        $this->avaliador('Bruno Comum', comissao: false);
        $this->projeto('Bioplástico');
        $this->adminDoComite();

        $dados = $this->getJson('/api/v1/admin/comite/designacoes/opcoes')->assertOk()->json('data');

        $this->assertCount(1, $dados['avaliadores']);
        $this->assertSame($daComissao->id, $dados['avaliadores'][0]['id']);
        // Os projetos são os mesmos da designação comum.
        $this->assertCount(1, $dados['projetos']);
    }

    public function test_designa_para_a_comissao_especial(): void
    {
        $daComissao = $this->avaliador('Ana Comissao', comissao: true);
        $projeto = $this->projeto('Bioplástico');
        $this->adminDoComite();

        $this->postJson('/api/v1/admin/comite/designacoes', [
            'projeto_ids' => [$projeto->id],
            'avaliador_ids' => [$daComissao->id],
        ])->assertOk()->assertJsonPath('data.designadas', 1);

        $this->assertDatabaseHas('avaliacoes', [
            'projeto_id' => $projeto->id,
            'avaliador_id' => $daComissao->id,
            'designacao_manual' => true,
        ]);
    }

    public function test_avaliador_fora_da_comissao_e_recusado_pelo_servidor(): void
    {
        $comum = $this->avaliador('Bruno Comum', comissao: false);
        $projeto = $this->projeto('Bioplástico');
        $this->adminDoComite();

        // A tela nem oferece — e trocar o id no payload também não passa.
        $this->postJson('/api/v1/admin/comite/designacoes', [
            'projeto_ids' => [$projeto->id],
            'avaliador_ids' => [$comum->id],
        ])->assertStatus(422)->assertJsonValidationErrors('avaliador_ids');

        $this->assertSame(0, Avaliacao::count());
    }

    public function test_da_lista_mista_so_a_comissao_e_designada(): void
    {
        $daComissao = $this->avaliador('Ana Comissao', comissao: true);
        $comum = $this->avaliador('Bruno Comum', comissao: false);
        $projeto = $this->projeto('Bioplástico');
        $this->adminDoComite();

        $this->postJson('/api/v1/admin/comite/designacoes', [
            'projeto_ids' => [$projeto->id],
            'avaliador_ids' => [$daComissao->id, $comum->id],
        ])->assertOk()->assertJsonPath('data.designadas', 1);

        $this->assertSame(1, Avaliacao::count());
        $this->assertSame($daComissao->id, Avaliacao::sole()->avaliador_id);
    }

    public function test_admin_sem_a_aba_comite_nao_entra(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/comite/designacoes/opcoes')->assertForbidden();
        $this->postJson('/api/v1/admin/comite/designacoes', [
            'projeto_ids' => [1], 'avaliador_ids' => [1],
        ])->assertForbidden();
    }

    /**
     * Três finalistas com código, turno e estande, e um projeto que não está
     * na lista final.
     *
     * @return array<string, Projeto>
     */
    private function finalistas(): array
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);
        $locais = [
            'Robô seguidor' => ['FET.EXA-001', 'A', 42],
            'Horta vertical' => ['FET.EXA-002', 'B', 42],
            // Com turno e ainda sem estande gerado: vale o de Turnos.
            'Cisterna de chuva' => ['FET.EXA-003', 'B', null],
        ];

        $projetos = [];
        foreach ($locais as $titulo => [$codigo, $turno, $estande]) {
            $projeto = $this->projeto($titulo);
            $lista->projetos()->attach($projeto->id, ['codigo' => $codigo]);
            TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno]);
            if ($estande !== null) {
                EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno, 'numero' => $estande]);
            }
            $projetos[$titulo] = $projeto;
        }
        $projetos['Bioplástico'] = $this->projeto('Bioplástico');

        return $projetos;
    }

    /** @return list<string> */
    private function titulos(string $query = ''): array
    {
        return array_column(
            $this->getJson('/api/v1/admin/comite/designacoes/opcoes'.$query)->assertOk()->json('data.projetos'),
            'titulo',
        );
    }

    public function test_cada_projeto_vem_com_codigo_turno_e_estande(): void
    {
        $this->finalistas();
        $this->adminDoComite();

        $dados = $this->getJson('/api/v1/admin/comite/designacoes/opcoes')->assertOk()->json('data');
        $porTitulo = array_column($dados['projetos'], null, 'titulo');

        $this->assertSame('FET.EXA-001', $porTitulo['Robô seguidor']['codigo']);
        $this->assertSame('A', $porTitulo['Robô seguidor']['turno']);
        $this->assertSame('Turno A (matutino)', $porTitulo['Robô seguidor']['turno_label']);
        $this->assertSame(42, $porTitulo['Robô seguidor']['estande']);
        $this->assertSame('B', $porTitulo['Cisterna de chuva']['turno']);
        $this->assertNull($porTitulo['Cisterna de chuva']['estande']);
        // Fora da lista final: sem local.
        $this->assertNull($porTitulo['Bioplástico']['codigo']);
        $this->assertSame(['A', 'B'], array_column($dados['turnos'], 'value'));
    }

    public function test_a_busca_casa_titulo_codigo_estande_e_turno(): void
    {
        $this->finalistas();
        $this->adminDoComite();

        $this->assertSame(['Bioplástico'], $this->titulos('?projeto=biopl'));
        $this->assertSame(['Horta vertical'], $this->titulos('?projeto=exa-002'));
        $this->assertSame(['Horta vertical', 'Robô seguidor'], $this->titulos('?projeto=042'));
        $this->assertSame(['Horta vertical', 'Robô seguidor'], $this->titulos('?projeto='.urlencode('estande 42')));
        $this->assertSame(['Cisterna de chuva', 'Horta vertical'], $this->titulos('?projeto=vespertino'));
        $this->assertSame(['Robô seguidor'], $this->titulos('?projeto='.urlencode('turno a')));
    }

    public function test_os_filtros_de_codigo_estande_e_turno_se_somam(): void
    {
        $this->finalistas();
        $this->adminDoComite();

        $this->assertSame(['Cisterna de chuva', 'Horta vertical', 'Robô seguidor'], $this->titulos('?codigo=fet.exa'));
        $this->assertSame(['Horta vertical', 'Robô seguidor'], $this->titulos('?estande=42'));
        $this->assertSame(['Horta vertical', 'Robô seguidor'], $this->titulos('?estande=042'));
        $this->assertSame(['Cisterna de chuva', 'Horta vertical'], $this->titulos('?turno=B'));
        $this->assertSame(['Horta vertical'], $this->titulos('?estande=42&turno=B'));
        $this->assertSame([], $this->titulos('?codigo=BIO'));
        // O filtro e a busca por título também se somam.
        $this->assertSame(['Horta vertical'], $this->titulos('?turno=B&projeto=horta'));
    }

    public function test_filtro_invalido_e_recusado(): void
    {
        $this->adminDoComite();

        $this->getJson('/api/v1/admin/comite/designacoes/opcoes?turno=C')
            ->assertStatus(422)->assertJsonValidationErrors('turno');
        $this->getJson('/api/v1/admin/comite/designacoes/opcoes?estande=abc')
            ->assertStatus(422)->assertJsonValidationErrors('estande');
    }

    public function test_a_designacao_da_avaliacao_online_nao_traz_o_local(): void
    {
        $this->finalistas();
        Sanctum::actingAs(User::factory()->admin()->create());

        $dados = $this->getJson('/api/v1/admin/avaliacao/designacoes/opcoes')->assertOk()->json('data');

        $this->assertArrayNotHasKey('codigo', $dados['projetos'][0]);
        $this->assertArrayNotHasKey('turnos', $dados);
    }

    public function test_a_designacao_comum_continua_alcancando_todo_mundo(): void
    {
        $comum = $this->avaliador('Bruno Comum', comissao: false);
        $projeto = $this->projeto('Bioplástico');

        // Na aba Avaliação online, o recorte do comitê não vale: lá a pergunta
        // é como cobrir a feira.
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/avaliacao/designacoes/designar', [
            'projeto_ids' => [$projeto->id],
            'avaliador_ids' => [$comum->id],
        ])->assertOk()->assertJsonPath('data.designadas', 1);
    }
}
