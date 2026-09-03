<?php

namespace Tests\Feature;

use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\Subarea;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 96 — Avaliação online → Designações: a tabela com tudo que está na mão
 * de cada avaliador, o tempo parado e a retirada com reposição imediata.
 */
class DesignacoesAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function avaliador(int $areaId, ?int $subareaId = null, array $over = []): User
    {
        $user = User::factory()->avaliador()->create($over);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $areaId, 'subarea_id' => $subareaId,
        ]);

        return $user->fresh();
    }

    private function projeto(int $areaId, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'titulo' => $titulo,
        ]);
    }

    private function designar(Projeto $p, User $a, StatusAvaliacao $status = StatusAvaliacao::Designada): Avaliacao
    {
        return Avaliacao::create([
            'projeto_id' => $p->id, 'avaliador_id' => $a->id, 'status' => $status,
        ]);
    }

    public function test_lista_as_designacoes_com_o_tempo_de_cada_uma(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id, null, ['name' => 'Ana Souza']);
        $projeto = $this->projeto($area->id, 'Robô seguidor');

        // `created_at` não é fillable: a data de designação entra pelo query builder.
        $designacao = $this->designar($projeto, $avaliador);
        Avaliacao::where('id', $designacao->id)->update(['created_at' => now()->subDays(3)]);
        $this->admin();

        $resposta = $this->getJson('/api/v1/admin/avaliacao/designacoes')->assertOk();

        $linha = $resposta->json('data.0');
        $this->assertSame('Robô seguidor', $linha['projeto']);
        $this->assertSame('Ana Souza', $linha['avaliador']);
        $this->assertSame('designada', $linha['situacao']);
        $this->assertSame('há 3 dias', $linha['tempo_label']);
        $this->assertSame(72, $linha['horas']);
        $this->assertTrue($linha['pode_retirar']);
        $this->assertSame(1, $resposta->json('meta.resumo.designada'));
    }

    public function test_filtra_por_avaliador_situacao_e_busca(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($area->id, null, ['name' => 'Ana Souza']);
        $bruno = $this->avaliador($area->id, null, ['name' => 'Bruno Lima']);
        $robo = $this->projeto($area->id, 'Robô seguidor');
        $horta = $this->projeto($area->id, 'Horta vertical');

        $this->designar($robo, $ana);
        $this->designar($horta, $bruno, StatusAvaliacao::EmAndamento);
        $this->admin();

        $this->getJson('/api/v1/admin/avaliacao/designacoes?avaliador_id='.$ana->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.avaliador', 'Ana Souza');

        $this->getJson('/api/v1/admin/avaliacao/designacoes?situacao=em_andamento')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.projeto', 'Horta vertical');

        // A busca alcança tanto o título do projeto quanto o nome do avaliador.
        $this->getJson('/api/v1/admin/avaliacao/designacoes?q=bruno')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.projeto', 'Horta vertical');
    }

    public function test_admin_retira_a_designacao_e_o_projeto_vai_para_outro_avaliador(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $sub = Subarea::create(['area_id' => $area->id, 'nome' => 'Sub 1']);
        $ana = $this->avaliador($area->id, null, ['name' => 'Ana Souza']);
        $bruno = $this->avaliador($area->id, $sub->id, ['name' => 'Bruno Lima']);
        $projeto = $this->projeto($area->id, 'Robô seguidor');

        $designacao = $this->designar($projeto, $ana);
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/avaliacao/designacoes/retirar', [
            'avaliacao_ids' => [$designacao->id],
        ])->assertOk()->assertJsonPath('data.redesignadas', 1);

        $this->assertDatabaseMissing('avaliacoes', ['id' => $designacao->id]);
        $this->assertDatabaseHas('avaliacoes', [
            'projeto_id' => $projeto->id, 'avaliador_id' => $bruno->id, 'status' => 'designada',
        ]);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoDesignacaoRetirada)->firstOrFail();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame('Ana Souza', $registro->detalhes['de']);
        $this->assertSame('Bruno Lima', $registro->detalhes['para']);
    }

    public function test_em_avaliacao_pode_ser_retirada_e_o_rascunho_vai_junto(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($area->id);
        $bruno = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id);

        $emAndamento = $this->designar($projeto, $ana, StatusAvaliacao::EmAndamento);
        $emAndamento->update(['respostas' => ['q1' => 8]]);
        $this->admin();

        $this->postJson('/api/v1/admin/avaliacao/designacoes/retirar', [
            'avaliacao_ids' => [$emAndamento->id],
        ])->assertOk()->assertJsonPath('data.retiradas', 1);

        $this->assertDatabaseMissing('avaliacoes', ['id' => $emAndamento->id]);
        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $projeto->id, 'avaliador_id' => $bruno->id]);
    }

    public function test_avaliacao_concluida_nao_sai_do_avaliador(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id);

        $concluida = $this->designar($projeto, $ana, StatusAvaliacao::Concluida);
        $this->admin();

        $this->postJson('/api/v1/admin/avaliacao/designacoes/retirar', [
            'avaliacao_ids' => [$concluida->id],
        ])->assertStatus(422)->assertJsonValidationErrors('avaliacao_ids');

        $this->assertDatabaseHas('avaliacoes', ['id' => $concluida->id]);
    }

    public function test_sem_avaliador_elegivel_o_projeto_fica_sem_designacao_e_a_tela_avisa(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($area->id, null, ['name' => 'Ana Souza']);
        $projeto = $this->projeto($area->id, 'Robô seguidor');

        $designacao = $this->designar($projeto, $ana);
        $this->admin();

        $this->postJson('/api/v1/admin/avaliacao/designacoes/retirar', [
            'avaliacao_ids' => [$designacao->id],
        ])->assertOk()
            ->assertJsonPath('data.redesignadas', 0)
            ->assertJsonPath('data.sem_avaliador', ['Robô seguidor']);

        $this->assertSame(0, Avaliacao::where('projeto_id', $projeto->id)->count());
    }

    public function test_quem_nao_e_admin_nao_ve_a_tabela(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/avaliacao/designacoes')->assertForbidden();
    }
}
