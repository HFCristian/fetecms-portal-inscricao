<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\Projeto;
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
