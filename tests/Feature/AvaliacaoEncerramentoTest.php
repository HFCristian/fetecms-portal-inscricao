<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Rubrica;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Encerramento do período de avaliação: passada a data, o avaliador ainda lê os
 * projetos designados e o que já respondeu, mas não inicia, não salva rascunho
 * e não envia.
 */
class AvaliacaoEncerramentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    /** Preenchimento válido para concluir (rubrica inteira + conferência da área). */
    private function preenchimento(): array
    {
        $respostas = [];
        foreach (Rubrica::perguntas() as $pergunta) {
            $respostas[$pergunta['chave']] = $pergunta['tipo'] === Rubrica::TIPO_SIM_NAO ? true : 10;
        }

        return ['respostas' => $respostas, 'area_correta' => true];
    }

    /** Avaliação designada, com o período já liberado e encerrado. */
    private function avaliacaoEncerrada(bool $demo = false): Avaliacao
    {
        Edicao::atual()->update([
            'avaliacao_liberada_em' => now()->subDays(10),
            'avaliacao_encerrada_em' => now()->subDay(),
        ]);

        $area = Area::create(['nome' => 'Área A']);
        $av = User::factory()->avaliador()->create(['is_demo' => $demo]);
        AvaliadorProfile::factory()->create(['user_id' => $av->id, 'area_id' => $area->id]);
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'area_id' => $area->id, 'titulo' => 'Projeto X',
        ]);

        Sanctum::actingAs($av);

        return Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $av->id, 'status' => 'designada',
        ]);
    }

    public function test_encerrada_o_avaliador_ainda_ve_os_projetos(): void
    {
        $this->avaliacaoEncerrada();

        $this->getJson('/api/v1/avaliacao')
            ->assertOk()
            ->assertJsonPath('data.liberada', true)
            ->assertJsonPath('data.encerrada', true)
            ->assertJsonPath('data.pode_ver', true)
            ->assertJsonPath('data.pode_avaliar', false)
            ->assertJsonPath('data.encerrada_em_label', fn ($v) => $v !== null)
            ->assertJsonCount(1, 'data.projetos');
    }

    public function test_encerrada_o_projeto_continua_abrindo_em_leitura(): void
    {
        $avaliacao = $this->avaliacaoEncerrada();

        $this->getJson("/api/v1/avaliacao/{$avaliacao->id}")
            ->assertOk()
            ->assertJsonPath('data.pode_avaliar', false)
            ->assertJsonPath('data.projeto.titulo', 'Projeto X');
    }

    public function test_encerrada_nao_inicia_nem_salva_rascunho(): void
    {
        $avaliacao = $this->avaliacaoEncerrada();

        $this->postJson("/api/v1/avaliacao/{$avaliacao->id}/iniciar")->assertStatus(403);
        $this->postJson("/api/v1/avaliacao/{$avaliacao->id}/rascunho", ['respostas' => []])->assertStatus(403);

        $this->assertSame('designada', $avaliacao->fresh()->status->value);
    }

    public function test_avaliacao_iniciada_antes_nao_pode_ser_enviada_depois(): void
    {
        $avaliacao = $this->avaliacaoEncerrada();
        $avaliacao->update(['status' => 'em_andamento']); // já estava em andamento quando encerrou

        $this->postJson("/api/v1/avaliacao/{$avaliacao->id}/concluir", $this->preenchimento())
            ->assertStatus(403);

        $this->assertSame('em_andamento', $avaliacao->fresh()->status->value);
    }

    public function test_antes_do_encerramento_avalia_normalmente(): void
    {
        $avaliacao = $this->avaliacaoEncerrada();
        Edicao::atual()->update(['avaliacao_encerrada_em' => now()->addDay()]);

        $this->postJson("/api/v1/avaliacao/{$avaliacao->id}/iniciar")->assertOk();
        $this->assertSame('em_andamento', $avaliacao->fresh()->status->value);
    }

    public function test_avaliador_demo_em_modo_teste_ignora_o_encerramento(): void
    {
        $avaliacao = $this->avaliacaoEncerrada(demo: true);

        $this->postJson("/api/v1/avaliacao/{$avaliacao->id}/iniciar?teste=1")->assertOk();
    }

    public function test_admin_define_e_remove_o_encerramento(): void
    {
        Edicao::atual()->update(['avaliacao_liberada_em' => '2026-09-01 08:00']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/encerramento', ['encerrada_em' => '2026-09-30T18:00'])
            ->assertOk()
            ->assertJsonPath('data.encerrada_em_label', '30/09/2026 18:00');

        $this->getJson('/api/v1/admin/avaliacao/config')
            ->assertOk()
            ->assertJsonPath('data.encerrada_em_input', '2026-09-30T18:00');

        $this->patchJson('/api/v1/admin/avaliacao/encerramento', ['encerrada_em' => null])
            ->assertOk()
            ->assertJsonPath('data.encerrada_em_input', null);
    }

    public function test_encerramento_precisa_ser_depois_da_liberacao(): void
    {
        Edicao::atual()->update(['avaliacao_liberada_em' => '2026-09-10 08:00']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/encerramento', ['encerrada_em' => '2026-09-01T08:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('encerrada_em');
    }

    public function test_liberacao_precisa_ser_antes_do_encerramento(): void
    {
        Edicao::atual()->update(['avaliacao_encerrada_em' => '2026-09-10 08:00']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/config', ['liberada_em' => '2026-09-20T08:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('liberada_em');
    }
}
