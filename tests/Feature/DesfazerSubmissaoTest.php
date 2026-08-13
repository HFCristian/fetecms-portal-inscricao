<?php

namespace Tests\Feature;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Desfazer a submissão: o orientador pode cancelar (volta a rascunho) ou excluir
 * a inscrição, mas só enquanto nenhuma avaliação foi iniciada e o período de
 * avaliação não começou.
 */
class DesfazerSubmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function orientadorComProjetoSubmetido(): array
    {
        $user = User::factory()->create(['role' => Role::Orientador]);
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $user->id,
            'edicao_id' => Edicao::atual()->id,
        ]);

        Sanctum::actingAs($user);

        return [$user, $projeto];
    }

    private function designarAvaliacao(Projeto $projeto, StatusAvaliacao $status): Avaliacao
    {
        return Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->create(['role' => Role::Avaliador])->id,
            'status' => $status,
        ]);
    }

    public function test_orientador_cancela_submissao_e_projeto_volta_para_rascunho(): void
    {
        [, $projeto] = $this->orientadorComProjetoSubmetido();

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")
            ->assertOk()
            ->assertJsonPath('data.status', ProjetoStatus::Rascunho->value);

        $projeto->refresh();
        $this->assertSame(ProjetoStatus::Rascunho, $projeto->status);
        $this->assertNull($projeto->submitted_at);
    }

    public function test_cancelamento_remove_designacoes_pendentes(): void
    {
        [, $projeto] = $this->orientadorComProjetoSubmetido();
        $this->designarAvaliacao($projeto, StatusAvaliacao::Designada);

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")->assertOk();

        $this->assertSame(0, $projeto->avaliacoes()->count());
    }

    public function test_orientador_exclui_inscricao_submetida(): void
    {
        [, $projeto] = $this->orientadorComProjetoSubmetido();

        $this->deleteJson("/api/v1/projetos/{$projeto->id}")->assertOk();

        $this->assertSoftDeleted('projetos', ['id' => $projeto->id]);
    }

    public function test_nao_desfaz_quando_ja_existe_avaliacao_iniciada(): void
    {
        [, $projeto] = $this->orientadorComProjetoSubmetido();
        $this->designarAvaliacao($projeto, StatusAvaliacao::EmAndamento);

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUBMISSAO_BLOQUEADA')
            ->assertJsonPath('motivos.0.code', 'AVALIACAO_INICIADA');

        $this->deleteJson("/api/v1/projetos/{$projeto->id}")->assertStatus(422);

        $this->assertSame(ProjetoStatus::Submetido, $projeto->fresh()->status);
        $this->assertNotSoftDeleted('projetos', ['id' => $projeto->id]);
    }

    public function test_nao_desfaz_quando_avaliacao_concluida(): void
    {
        [, $projeto] = $this->orientadorComProjetoSubmetido();
        $this->designarAvaliacao($projeto, StatusAvaliacao::Concluida);

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")
            ->assertStatus(422)
            ->assertJsonPath('motivos.0.code', 'AVALIACAO_INICIADA');
    }

    public function test_nao_desfaz_depois_que_o_periodo_de_avaliacao_comecou(): void
    {
        [, $projeto] = $this->orientadorComProjetoSubmetido();
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")
            ->assertStatus(422)
            ->assertJsonPath('motivos.0.code', 'AVALIACAO_LIBERADA');

        $this->assertSame(ProjetoStatus::Submetido, $projeto->fresh()->status);
    }

    public function test_desfaz_enquanto_a_liberacao_esta_agendada_para_o_futuro(): void
    {
        [, $projeto] = $this->orientadorComProjetoSubmetido();
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->addWeek()]);

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")->assertOk();
    }

    public function test_orientador_nao_desfaz_submissao_de_outro(): void
    {
        $dono = User::factory()->create(['role' => Role::Orientador]);
        $projeto = Projeto::factory()->submetido()->create(['user_id' => $dono->id]);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador]));

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")->assertForbidden();
        $this->deleteJson("/api/v1/projetos/{$projeto->id}")->assertForbidden();
    }

    public function test_admin_desfaz_mesmo_com_avaliacao_iniciada(): void
    {
        $dono = User::factory()->create(['role' => Role::Orientador]);
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $dono->id,
            'edicao_id' => Edicao::atual()->id,
        ]);
        $this->designarAvaliacao($projeto, StatusAvaliacao::EmAndamento);
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);

        $admin = User::factory()->create(['role' => Role::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")->assertOk();

        // A avaliação já iniciada continua de pé — só as pendentes são limpas.
        $this->assertSame(1, $projeto->avaliacoes()->count());

        $registro = RegistroAtividade::where('tipo', TipoRegistro::Cancelamento)->first();
        $this->assertSame($admin->email, $registro->autor_email);
        $this->assertSame($dono->email, $registro->dono_email);
        $this->assertTrue($registro->porTerceiro());
    }

    public function test_cancelar_rascunho_e_idempotente(): void
    {
        $user = User::factory()->create(['role' => Role::Orientador]);
        $projeto = Projeto::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/cancelar-submissao")
            ->assertOk()
            ->assertJsonPath('meta.message', 'Este projeto já está em rascunho.');

        $this->assertSame(0, RegistroAtividade::count());
    }

    public function test_excluir_rascunho_nao_vira_registro(): void
    {
        $user = User::factory()->create(['role' => Role::Orientador]);
        $projeto = Projeto::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/projetos/{$projeto->id}")->assertOk();

        $this->assertSoftDeleted('projetos', ['id' => $projeto->id]);
        $this->assertSame(0, RegistroAtividade::count());
    }

    public function test_listagem_marca_quais_submissoes_podem_ser_desfeitas(): void
    {
        [$user, $projeto] = $this->orientadorComProjetoSubmetido();
        $bloqueado = Projeto::factory()->submetido()->create([
            'user_id' => $user->id,
            'edicao_id' => Edicao::atual()->id,
        ]);
        $this->designarAvaliacao($bloqueado, StatusAvaliacao::EmAndamento);

        $resposta = $this->getJson('/api/v1/projetos')->assertOk()->json('data');
        $porId = collect($resposta)->keyBy('id');

        $this->assertTrue($porId[$projeto->id]['pode_desfazer']);
        $this->assertFalse($porId[$bloqueado->id]['pode_desfazer']);
    }
}
