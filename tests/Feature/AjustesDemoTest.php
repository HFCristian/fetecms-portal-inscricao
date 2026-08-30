<?php

namespace Tests\Feature;

use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Projeto;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Services\AjustesOrientadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 83 — o projeto-exemplo da aba Ajustes do orientador demo, e o
 * isolamento que impede esse projeto de mentira de entrar nos números reais.
 */
class AjustesDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function orientadorDemo(): User
    {
        return User::where('email', 'orientador.demo@fetecms.test')->firstOrFail();
    }

    public function test_comando_monta_o_exemplo_com_sugestoes_e_recomendacoes(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();

        $orientador = $this->orientadorDemo();
        $this->assertTrue($orientador->is_demo);

        $projeto = Projeto::where('user_id', $orientador->id)->firstOrFail();
        $this->assertSame(ProjetoStatus::Submetido, $projeto->status);

        $avaliacao = Avaliacao::where('projeto_id', $projeto->id)->firstOrFail();
        $this->assertSame(StatusAvaliacao::Concluida, $avaliacao->status);
        // O par que a aba lê: "está errada" + "sugiro esta".
        $this->assertFalse($avaliacao->area_correta);
        $this->assertNotNull($avaliacao->area_sugerida_id);
        $this->assertNotSame($projeto->area_id, $avaliacao->area_sugerida_id);
        // A nota bate com as respostas gravadas, e não é um número solto.
        $this->assertSame($avaliacao->notaCalculada(), (float) $avaliacao->nota);

        $ajustes = app(AjustesOrientadorService::class);
        $linhas = $ajustes->projetos($orientador);

        $this->assertCount(1, $linhas);
        $this->assertSame(2, $linhas[0]['sugestoes']);   // área + subárea
        $this->assertSame(2, $linhas[0]['pendentes']);
        $this->assertSame(2, $linhas[0]['recomendacoes']); // vídeo + projeto
    }

    /** Rodar de novo não duplica nada — é o caminho de quem repete em produção. */
    public function test_comando_e_idempotente(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();
        $this->artisan('demo:ajustes')->assertSuccessful();

        $this->assertSame(1, Projeto::withoutGlobalScopes()->count());
        $this->assertSame(1, Avaliacao::count());
        $this->assertSame(1, User::where('email', 'orientador.demo@fetecms.test')->count());
    }

    /** O modo de teste abre a aba mesmo sem período definido pela organização. */
    public function test_orientador_demo_ve_a_aba_fora_do_periodo(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();
        $orientador = $this->orientadorDemo();
        $ajustes = app(AjustesOrientadorService::class);

        // Sem data de início, a aba fica fechada para todo mundo…
        $this->assertFalse($ajustes->janela($orientador)['aberta']);
        // …menos para a conta demo que liga o modo de teste.
        $this->assertTrue($ajustes->janela($orientador, true)['aberta']);
        $this->assertTrue($ajustes->janela($orientador, true)['modo_teste']);
    }

    /** Orientador comum não tem modo de teste, nem pedindo. */
    public function test_orientador_comum_nao_burla_o_periodo(): void
    {
        $comum = User::factory()->create(['is_demo' => false]);

        $janela = app(AjustesOrientadorService::class)->janela($comum, true);

        $this->assertFalse($janela['aberta']);
        $this->assertFalse($janela['modo_teste']);
    }

    /**
     * O ponto que justifica o `is_demo`: o projeto-exemplo não pode entrar na
     * conta de camisetas que a organização vai encomendar.
     */
    public function test_projeto_demo_fica_fora_dos_numeros_e_do_ranking(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();

        $m = app(AdminDashboardService::class)->metricas();

        $this->assertSame(0, $m['projetos_total']);
        $this->assertSame(0, $m['projetos_submetidos']);
        $this->assertSame(0, $m['orientadores']);
        $this->assertSame(0, array_sum(array_column($m['projetos_categoria'], 'total')));

        $this->assertSame([], Projeto::semDemo()->pluck('id')->all());
    }

    /** Mas o projeto continua existindo — para o dono e para as listagens. */
    public function test_projeto_demo_continua_visivel_para_o_dono(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();

        $this->assertSame(1, Projeto::where('user_id', $this->orientadorDemo()->id)->count());
    }
}
