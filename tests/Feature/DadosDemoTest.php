<?php

namespace Tests\Feature;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\Scopes\EdicaoScope;
use App\Models\User;
use App\Services\AdminAvaliacaoService;
use App\Services\DadosDemoService;
use App\Services\DesignacaoService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 117 — Parametrização → **Dados de demonstração**.
 *
 * Duas coisas ao mesmo tempo: a tela que enxerga (e apaga) tudo que é de ensaio
 * e o conserto do vazamento que a motivou — o projeto de um orientador de
 * treinamento aparecia em *Projetos submetidos* e podia ser designado a um
 * avaliador de verdade.
 */
class DadosDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Um projeto submetido de um orientador demo (ou real, conforme o flag). */
    private function projetoDe(bool $demo): Projeto
    {
        $orientador = User::factory()->create(['is_demo' => $demo]);

        return Projeto::factory()->create([
            'user_id' => $orientador->id,
            'edicao_id' => Edicao::atual()?->id,
            'status' => ProjetoStatus::Submetido,
            'area_id' => Area::first()->id,
        ]);
    }

    // --- O vazamento que motivou a sprint -------------------------------

    public function test_projeto_de_conta_demo_sai_da_tabela_de_projetos_submetidos(): void
    {
        $this->admin();
        $demo = $this->projetoDe(true);
        $real = $this->projetoDe(false);

        $ids = app(AdminAvaliacaoService::class)->projetos()->pluck('id')->all();

        $this->assertContains($real->id, $ids);
        $this->assertNotContains($demo->id, $ids, 'Projeto de ensaio não pode ser designável.');
    }

    public function test_projeto_demo_sai_tambem_do_resumo_por_area_e_do_filtro(): void
    {
        $this->admin();
        $demo = $this->projetoDe(true);

        $servico = app(AdminAvaliacaoService::class);

        $titulos = collect($servico->projetosSubmetidosPorArea())
            ->flatMap(fn (array $g) => array_column($g['projetos'], 'titulo'))
            ->all();

        $this->assertNotContains($demo->titulo, $titulos);
        $this->assertSame([], $servico->areasComProjeto());
    }

    public function test_designacao_de_projeto_demo_nao_aparece_na_tela_de_designacoes(): void
    {
        $this->admin();
        $demo = $this->projetoDe(true);

        $avaliador = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'area_id' => $demo->area_id]);

        Avaliacao::create(['projeto_id' => $demo->id, 'avaliador_id' => $avaliador->id]);

        $this->assertSame(0, app(DesignacaoService::class)->listar([])->total());
    }

    // --- O panorama ------------------------------------------------------

    public function test_panorama_reune_contas_projetos_e_listas_de_ensaio(): void
    {
        $this->admin();
        $demo = $this->projetoDe(true);
        $this->projetoDe(false);

        ListaFinal::create([
            'edicao_id' => $demo->edicao_id,
            'nome' => 'Lista de treinamento',
            'vigente' => true,
            'demo' => true,
            'versao' => 1,
        ]);

        $resposta = $this->getJson('/api/v1/admin/demo')->assertOk()->json('data');

        $this->assertSame(1, $resposta['contas']['total']);
        $this->assertSame(1, $resposta['projetos']['total']);
        $this->assertSame(1, $resposta['listas']['total']);
        $this->assertSame($demo->titulo, $resposta['projetos']['itens'][0]['titulo']);
    }

    /** O ensaio esquecido na edição passada é justamente o que se quer achar. */
    public function test_panorama_atravessa_o_escopo_de_edicao(): void
    {
        $this->admin();
        $projeto = $this->projetoDe(true);
        $projeto->forceFill(['edicao_id' => null])->save();

        $panorama = app(DadosDemoService::class)->panorama();

        $this->assertSame(1, $panorama['projetos']['total']);
    }

    // --- As ações --------------------------------------------------------

    public function test_desmarcar_a_conta_devolve_o_projeto_para_os_numeros(): void
    {
        $this->admin();
        $projeto = $this->projetoDe(true);

        $this->patchJson("/api/v1/admin/demo/contas/{$projeto->user_id}", ['demo' => false])
            ->assertOk()
            ->assertJsonPath('data.is_demo', false);

        $ids = app(AdminAvaliacaoService::class)->projetos()->pluck('id')->all();
        $this->assertContains($projeto->id, $ids);
    }

    public function test_excluir_conta_leva_projetos_avaliacoes_e_registros_junto(): void
    {
        $this->admin();
        $projeto = $this->projetoDe(true);
        $avaliador = User::factory()->avaliador()->create();
        Avaliacao::create(['projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id]);
        RegistroAtividade::create([
            'tipo' => TipoRegistro::Submissao,
            'user_id' => $projeto->user_id,
            'autor_email' => $projeto->user->email,
            'autor_nome' => $projeto->user->name,
            'autor_role' => Role::Orientador->value,
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'dono_email' => $projeto->user->email,
        ]);

        $this->deleteJson("/api/v1/admin/demo/contas/{$projeto->user_id}")->assertOk();

        $this->assertNull(User::find($projeto->user_id));
        $this->assertSame(0, Projeto::withoutGlobalScope(EdicaoScope::class)->withTrashed()->count());
        $this->assertSame(0, Avaliacao::count());
        $this->assertSame(0, RegistroAtividade::count());
    }

    /** A trava que faz a tela ser segura: dado real não sai por aqui. */
    public function test_nao_exclui_conta_que_nao_e_de_demonstracao(): void
    {
        $this->admin();
        $real = $this->projetoDe(false);

        $this->deleteJson("/api/v1/admin/demo/contas/{$real->user_id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('demo');

        $this->assertNotNull(User::find($real->user_id));
    }

    public function test_nao_exclui_projeto_de_conta_real(): void
    {
        $this->admin();
        $real = $this->projetoDe(false);

        $this->deleteJson("/api/v1/admin/demo/projetos/{$real->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('demo');

        $this->assertNotNull(Projeto::find($real->id));
    }

    public function test_limpar_tudo_apaga_so_o_que_e_de_ensaio(): void
    {
        $this->admin();
        $demo = $this->projetoDe(true);
        $real = $this->projetoDe(false);

        ListaFinal::create([
            'edicao_id' => $demo->edicao_id,
            'nome' => 'Treinamento',
            'vigente' => true,
            'demo' => true,
            'versao' => 1,
        ]);

        $resposta = $this->postJson('/api/v1/admin/demo/limpar')->assertOk();

        $this->assertSame(1, $resposta->json('meta.totais.contas'));
        $this->assertSame(1, $resposta->json('meta.totais.projetos'));
        $this->assertSame(1, $resposta->json('meta.totais.listas'));

        $this->assertNull(User::find($demo->user_id));
        $this->assertNotNull(User::find($real->user_id));
        $this->assertNotNull(Projeto::find($real->id));
        $this->assertSame(0, ListaFinal::count());
    }

    /** A aba é do RBAC: quem não tem Parametrização não abre. */
    public function test_admin_sem_a_aba_parametrizacao_recebe_403(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::atual()?->id]);
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/demo')->assertForbidden();
    }
}
