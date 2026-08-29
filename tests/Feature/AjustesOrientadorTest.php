<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\ProjetoAjuste;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Aba "Ajustes" do orientador: as sugestões de reclassificação dos avaliadores,
 * aceitas ou recusadas durante o período de ajustes.
 */
class AjustesOrientadorTest extends TestCase
{
    use RefreshDatabase;

    private User $orientador;

    private Area $exatas;

    private Area $agrarias;

    private Projeto $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exatas = Area::create(['nome' => 'Ciências Exatas e da Terra']);
        $this->agrarias = Area::create(['nome' => 'Ciências Agrárias']);
        $this->orientador = User::factory()->create(['role' => Role::Orientador->value]);
        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => $this->orientador->id,
            'titulo' => 'Bioplástico de mandioca',
            'area_id' => $this->exatas->id,
        ]);

        Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'inscricoes_abertas' => true,
            'ajustes_de' => now()->subDay(),
            'ajustes_ate' => now()->addDays(5),
        ]);
    }

    /** Avaliação concluída sugerindo a troca de área. */
    private function sugestao(?Area $area = null, array $over = []): Avaliacao
    {
        return Avaliacao::create(array_merge([
            'projeto_id' => $this->projeto->id,
            'avaliador_id' => User::factory()->create(['role' => Role::Avaliador->value])->id,
            'status' => StatusAvaliacao::Concluida->value,
            'nota' => 8,
            'concluida_em' => now(),
            'area_correta' => false,
            'area_sugerida_id' => ($area ?? $this->agrarias)->id,
        ], $over));
    }

    public function test_lista_os_projetos_submetidos_com_as_sugestoes(): void
    {
        $this->sugestao();
        Sanctum::actingAs($this->orientador);

        $this->getJson('/api/v1/ajustes')
            ->assertOk()
            ->assertJsonPath('data.janela.aberta', true)
            ->assertJsonPath('data.projetos.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('data.projetos.0.sugestoes', 1)
            ->assertJsonPath('data.projetos.0.pendentes', 1)
            ->assertJsonPath('data.projetos.0.aceitas', 0);
    }

    public function test_detalhe_traz_sugestoes_e_recomendacoes_sem_identificar_o_avaliador(): void
    {
        $this->sugestao(null, [
            'comentario_video' => 'Melhore o áudio da apresentação.',
            'comentario_projeto' => 'Aprofunde a fundamentação teórica.',
        ]);
        Sanctum::actingAs($this->orientador);

        $resposta = $this->getJson("/api/v1/ajustes/projetos/{$this->projeto->id}")->assertOk();

        $resposta->assertJsonPath('data.sugestoes.0.tipo', 'area')
            ->assertJsonPath('data.sugestoes.0.atual', 'Ciências Exatas e da Terra')
            ->assertJsonPath('data.sugestoes.0.sugerido', 'Ciências Agrárias')
            ->assertJsonPath('data.sugestoes.0.aceito', false)
            ->assertJsonPath('data.sugestoes.0.avaliador', 'Avaliador 1')
            ->assertJsonCount(2, 'data.recomendacoes');

        // O nome do avaliador não aparece em lugar nenhum.
        $this->assertStringNotContainsString(
            User::find(Avaliacao::first()->avaliador_id)->name,
            $resposta->getContent(),
        );
    }

    public function test_aceitar_troca_a_area_na_hora_e_a_sugestao_continua_visivel(): void
    {
        $avaliacao = $this->sugestao();
        Sanctum::actingAs($this->orientador);

        $this->postJson("/api/v1/ajustes/projetos/{$this->projeto->id}/decidir", [
            'avaliacao_id' => $avaliacao->id,
            'tipo' => 'area',
            'aceito' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.area', 'Ciências Agrárias')
            // Aceita, mas ainda na lista: ele pode mudar de ideia.
            ->assertJsonCount(1, 'data.sugestoes')
            ->assertJsonPath('data.sugestoes.0.aceito', true);

        $this->assertSame($this->agrarias->id, $this->projeto->fresh()->area_id);

        // A troca entra na trilha de registros como correção de projeto.
        $this->assertDatabaseHas('registros_atividade', [
            'tipo' => TipoRegistro::ProjetoArea->value,
            'projeto_id' => $this->projeto->id,
            'autor_email' => $this->orientador->email,
        ]);
    }

    public function test_desmarcar_devolve_a_area_anterior(): void
    {
        $avaliacao = $this->sugestao();
        Sanctum::actingAs($this->orientador);

        $decidir = fn (bool $aceito) => $this->postJson("/api/v1/ajustes/projetos/{$this->projeto->id}/decidir", [
            'avaliacao_id' => $avaliacao->id, 'tipo' => 'area', 'aceito' => $aceito,
        ]);

        $decidir(true)->assertOk();
        $decidir(false)->assertOk()->assertJsonPath('data.area', 'Ciências Exatas e da Terra');

        $this->assertSame($this->exatas->id, $this->projeto->fresh()->area_id);
        $this->assertFalse(ProjetoAjuste::first()->aceito);
    }

    public function test_aceitar_outra_sugestao_desliga_a_anterior(): void
    {
        $biologicas = Area::create(['nome' => 'Ciências Biológicas']);
        $primeira = $this->sugestao();
        $segunda = $this->sugestao($biologicas);
        Sanctum::actingAs($this->orientador);

        foreach ([$primeira, $segunda] as $avaliacao) {
            $this->postJson("/api/v1/ajustes/projetos/{$this->projeto->id}/decidir", [
                'avaliacao_id' => $avaliacao->id, 'tipo' => 'area', 'aceito' => true,
            ])->assertOk();
        }

        $detalhe = $this->getJson("/api/v1/ajustes/projetos/{$this->projeto->id}")->json('data');

        $this->assertSame('Ciências Biológicas', $detalhe['area']);
        // Só a que está valendo aparece marcada.
        $aceitas = array_filter($detalhe['sugestoes'], fn ($s) => $s['aceito']);
        $this->assertCount(1, $aceitas);
        $this->assertSame($biologicas->id, reset($aceitas)['sugerido_id']);
    }

    public function test_fora_do_periodo_a_aba_abre_vazia_e_nada_pode_ser_decidido(): void
    {
        $avaliacao = $this->sugestao();
        Edicao::atual()->update(['ajustes_de' => now()->addDays(3), 'ajustes_ate' => now()->addDays(9)]);
        Sanctum::actingAs($this->orientador);

        $this->getJson('/api/v1/ajustes')
            ->assertOk()
            ->assertJsonPath('data.janela.aberta', false)
            ->assertJsonPath('data.janela.iniciada', false)
            ->assertJsonCount(0, 'data.projetos');

        $this->getJson("/api/v1/ajustes/projetos/{$this->projeto->id}")->assertStatus(422);

        $this->postJson("/api/v1/ajustes/projetos/{$this->projeto->id}/decidir", [
            'avaliacao_id' => $avaliacao->id, 'tipo' => 'area', 'aceito' => true,
        ])->assertStatus(422);

        $this->assertSame($this->exatas->id, $this->projeto->fresh()->area_id);
    }

    public function test_orientador_demo_ve_a_aba_em_modo_teste(): void
    {
        $this->sugestao();
        Edicao::atual()->update(['ajustes_de' => null, 'ajustes_ate' => null]);
        $this->orientador->update(['is_demo' => true]);
        Sanctum::actingAs($this->orientador);

        // Sem modo teste, a janela continua fechada — nem para o demo.
        $this->getJson('/api/v1/ajustes')->assertJsonPath('data.janela.aberta', false);

        $this->getJson('/api/v1/ajustes?teste=1')
            ->assertOk()
            ->assertJsonPath('data.janela.aberta', true)
            ->assertJsonPath('data.janela.modo_teste', true)
            ->assertJsonCount(1, 'data.projetos');
    }

    public function test_orientador_nao_ve_o_projeto_de_outro(): void
    {
        $this->sugestao();
        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador->value]));

        $this->getJson("/api/v1/ajustes/projetos/{$this->projeto->id}")->assertForbidden();
    }

    public function test_avaliador_nao_acessa_a_aba(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Avaliador->value]));

        $this->getJson('/api/v1/ajustes')->assertForbidden();
    }
}
