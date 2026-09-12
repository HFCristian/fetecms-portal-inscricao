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
        return User::where('email', 'orientador@fetecms.test')->firstOrFail();
    }

    public function test_comando_monta_o_exemplo_com_tres_sugestoes(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();

        $orientador = $this->orientadorDemo();
        $this->assertTrue($orientador->is_demo);

        $projeto = Projeto::where('user_id', $orientador->id)->firstOrFail();
        $this->assertSame(ProjetoStatus::Submetido, $projeto->status);

        $avaliacoes = Avaliacao::where('projeto_id', $projeto->id)->orderBy('id')->get();
        $this->assertCount(3, $avaliacoes, 'Três avaliadores, como na feira de verdade.');

        foreach ($avaliacoes as $avaliacao) {
            $this->assertSame(StatusAvaliacao::Concluida, $avaliacao->status);
            // A nota bate com as respostas gravadas, e não é um número solto.
            $this->assertSame($avaliacao->notaCalculada(), (float) $avaliacao->nota);
        }

        // Cada avaliador deixa UMA sugestão: área, subárea e outra área.
        $this->assertFalse($avaliacoes[0]->area_correta);
        $this->assertNotSame($projeto->area_id, $avaliacoes[0]->area_sugerida_id);
        $this->assertTrue($avaliacoes[0]->subarea_correta);

        $this->assertTrue($avaliacoes[1]->area_correta);
        $this->assertFalse($avaliacoes[1]->subarea_correta);
        $this->assertNotNull($avaliacoes[1]->subarea_sugerida_id);

        $this->assertFalse($avaliacoes[2]->area_correta);
        $this->assertNotNull($avaliacoes[2]->area_sugerida_id);

        $ajustes = app(AjustesOrientadorService::class);
        $linhas = $ajustes->projetos($orientador);

        $this->assertCount(1, $linhas);
        $this->assertSame(3, $linhas[0]['sugestoes']);      // uma por avaliador
        $this->assertSame(3, $linhas[0]['pendentes']);
        $this->assertSame(6, $linhas[0]['recomendacoes']);  // vídeo + projeto, de cada um
    }

    /** O orientador vê três vozes anônimas, e duas delas disputam a área. */
    public function test_as_sugestoes_sao_anonimas_e_uma_disputa_a_outra(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();

        $projeto = Projeto::where('user_id', $this->orientadorDemo()->id)->firstOrFail();
        $detalhe = app(AjustesOrientadorService::class)->detalhe($projeto);

        $autores = array_column($detalhe['sugestoes'], 'avaliador');
        $this->assertSame(['Avaliador 1', 'Avaliador 2', 'Avaliador 3'], $autores);

        $tipos = array_column($detalhe['sugestoes'], 'tipo');
        $this->assertSame(['area', 'subarea', 'area'], $tipos);

        // As duas sugestões de área apontam para lugares diferentes: aceitar uma
        // é o que desliga a outra, e é esse caso que o ensaio precisa mostrar.
        $areas = array_column(array_filter(
            $detalhe['sugestoes'],
            fn (array $s) => $s['tipo'] === 'area',
        ), 'sugerido_id');
        $this->assertCount(2, array_unique($areas));
    }

    /** Rodar de novo não duplica nada — é o caminho de quem repete em produção. */
    public function test_comando_e_idempotente(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();
        $this->artisan('demo:ajustes')->assertSuccessful();

        $this->assertSame(1, Projeto::withoutGlobalScopes()->count());
        $this->assertSame(3, Avaliacao::count());
        $this->assertSame(1, User::where('email', 'orientador@fetecms.test')->count());
        $this->assertSame(1, User::where('email', 'avaliador.demo2@fetecms.test')->count());
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
