<?php

namespace Tests\Feature;

use App\Enums\PublicoMala;
use App\Enums\Role;
use App\Models\Edicao;
use App\Models\Feedback;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Services\AvisoService;
use App\Services\FeedbackService;
use App\Services\PublicoUsuariosService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 173 — público "orientadores de projetos finalistas": o pedaço da
 * equipe finalista que tem conta, e por isso o único que avisos e feedback
 * conseguem alcançar.
 */
class PublicoOrientadoresFinalistasTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
        $this->edicao = Edicao::where('padrao', true)->firstOrFail();
    }

    private function orientadorCom(string $nome): array
    {
        $user = User::factory()->create(['role' => Role::Orientador->value, 'name' => $nome]);
        $projeto = Projeto::factory()->submetido()->create(['user_id' => $user->id, 'edicao_id' => $this->edicao->id]);

        return [$user, $projeto];
    }

    private function lista(array $projetos, array $over = []): ListaFinal
    {
        $lista = ListaFinal::create(array_merge([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => ListaFinal::TIPO_FINAL,
            'vigente' => true, 'rascunho' => false, 'versao' => 1,
        ], $over));
        $lista->projetos()->attach(collect($projetos)->mapWithKeys(fn ($p) => [$p->id => ['manual' => false]])->all());

        return $lista;
    }

    private function servico(): PublicoUsuariosService
    {
        return app(PublicoUsuariosService::class);
    }

    public function test_alcanca_so_orientador_com_projeto_na_lista_final_ativa(): void
    {
        [$finalista, $projeto] = $this->orientadorCom('Finalista');
        [$naoFinalista] = $this->orientadorCom('Não finalista');
        User::factory()->avaliador()->create(['name' => 'Avaliador']);

        $this->lista([$projeto]);

        $this->assertSame(['Finalista'], $this->servico()->query(PublicoMala::OrientadoresFinalistas)->pluck('name')->all());
        $this->assertTrue($this->servico()->alcanca($finalista, [PublicoMala::OrientadoresFinalistas]));
        $this->assertFalse($this->servico()->alcanca($naoFinalista, [PublicoMala::OrientadoresFinalistas]));
    }

    public function test_sem_lista_final_ativa_o_publico_fica_vazio(): void
    {
        [, $projeto] = $this->orientadorCom('Orientador');

        // Preliminar, final inativa e a lista de ensaio não definem finalista.
        $this->lista([$projeto], ['tipo' => ListaFinal::TIPO_PRELIMINAR, 'vigente' => false]);
        $this->lista([$projeto], ['vigente' => false]);
        $this->lista([$projeto], ['demo' => true]);

        $this->assertSame(0, $this->servico()->query(PublicoMala::OrientadoresFinalistas)->count());
    }

    public function test_conta_inativa_ou_demo_fica_de_fora(): void
    {
        [$inativo, $p1] = $this->orientadorCom('Inativo');
        [$demo, $p2] = $this->orientadorCom('Demo');
        $this->lista([$p1, $p2]);

        $inativo->update(['is_active' => false]);
        $demo->update(['is_demo' => true]);

        $this->assertSame(0, $this->servico()->query(PublicoMala::OrientadoresFinalistas)->count());
    }

    public function test_publico_aparece_na_mala_direta_nos_avisos_e_no_feedback(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $valores = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data.publicos'), 'value');

        $this->assertContains('orientadores_finalistas', $valores('/api/v1/admin/mala-direta/opcoes'));
        $this->assertContains('orientadores_finalistas', $valores('/api/v1/admin/avisos/opcoes'));
        $this->assertContains('orientadores_finalistas', $valores('/api/v1/admin/feedbacks/opcoes'));
    }

    public function test_aviso_aos_finalistas_aparece_so_para_quem_e_finalista(): void
    {
        [$finalista, $projeto] = $this->orientadorCom('Finalista');
        [$naoFinalista] = $this->orientadorCom('Não finalista');
        $this->lista([$projeto]);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/avisos', [
            'titulo' => 'Credenciamento', 'mensagem' => 'Tragam os documentos.',
            'publicos' => ['orientadores_finalistas'],
        ])->assertCreated();

        $servico = app(AvisoService::class);
        $this->assertNotNull($servico->paraUsuario($finalista));
        $this->assertNull($servico->paraUsuario($naoFinalista));
    }

    public function test_feedback_convida_so_os_orientadores_finalistas(): void
    {
        Queue::fake();
        [, $projeto] = $this->orientadorCom('Finalista');
        $this->orientadorCom('Não finalista');
        $this->lista([$projeto]);

        app(FeedbackService::class)->criar([
            'titulo' => 'Como foi a feira?',
            'publicos' => ['orientadores_finalistas'],
            'perguntas' => [[
                'tipo' => 'alternativa', 'enunciado' => 'Como foi?', 'modelo' => 'satisfacao', 'obrigatoria' => true,
            ]],
        ]);

        $this->assertSame(['Finalista'], Feedback::firstOrFail()->destinatarios()->pluck('nome')->all());
    }
}
