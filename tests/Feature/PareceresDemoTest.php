<?php

namespace Tests\Feature;

use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Projeto;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Services\AjustesOrientadorService;
use App\Services\PareceresOrientadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O projeto-exemplo do pós-avaliação (`demo:pareceres`): um projeto submetido
 * que chega ao orientador com a aba **Ajustes e Pareceres** cheia — sugestões
 * para decidir, níveis e recomendações para ler —, conferível antes do prazo
 * oficial pelo modo de teste.
 */
class PareceresDemoTest extends TestCase
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

    private function projeto(): Projeto
    {
        return Projeto::where('user_id', $this->orientadorDemo()->id)->firstOrFail();
    }

    public function test_comando_monta_projeto_submetido_com_tres_avaliacoes_concluidas(): void
    {
        $this->artisan('demo:pareceres')->assertSuccessful();

        $this->assertTrue($this->orientadorDemo()->is_demo);

        $projeto = $this->projeto();
        $this->assertSame(ProjetoStatus::Submetido, $projeto->status);

        $avaliacoes = Avaliacao::where('projeto_id', $projeto->id)->orderBy('id')->get();
        $this->assertCount(3, $avaliacoes);

        foreach ($avaliacoes as $avaliacao) {
            $this->assertSame(StatusAvaliacao::Concluida, $avaliacao->status);
            // A nota bate com as respostas gravadas, e não é um número solto.
            $this->assertSame($avaliacao->notaCalculada(), (float) $avaliacao->nota);
            $this->assertCount(17, $avaliacao->respostas, 'A rubrica inteira respondida.');
        }
    }

    /**
     * A razão de ser do comando: se todas as seções saíssem no mesmo nível, o
     * parecer não ensinaria nada a quem está conferindo a tela.
     */
    public function test_pareceres_saem_com_pontos_fortes_medios_e_fracos(): void
    {
        $this->artisan('demo:pareceres')->assertSuccessful();

        $detalhe = app(AjustesOrientadorService::class)->detalhe($this->projeto());

        $niveis = array_count_values(array_column($detalhe['secoes'], 'nivel'));

        $this->assertArrayHasKey(PareceresOrientadorService::NIVEL_FORTE, $niveis);
        $this->assertArrayHasKey(PareceresOrientadorService::NIVEL_MEDIO, $niveis);
        $this->assertArrayHasKey(PareceresOrientadorService::NIVEL_FRACO, $niveis);
        $this->assertArrayNotHasKey('', $niveis, 'Nenhuma seção fica "não avaliada".');

        // As três avaliações contam — mas o que elas deram não chega ao
        // orientador, nem por seção nem no total.
        $this->assertSame(3, $detalhe['avaliacoes']);
        $this->assertArrayNotHasKey('media', $detalhe);
        $this->assertArrayNotHasKey('nota_maxima', $detalhe);

        // Vídeo e projeto de cada avaliador, sempre sem nome.
        $this->assertCount(6, $detalhe['recomendacoes']);
        $this->assertSame(
            ['Avaliador 1', 'Avaliador 2', 'Avaliador 3'],
            array_values(array_unique(array_column($detalhe['recomendacoes'], 'avaliador'))),
        );
    }

    /** O mesmo projeto abastece a outra metade da aba: três sugestões por decidir. */
    public function test_ajustes_saem_com_tres_sugestoes_pendentes(): void
    {
        $this->artisan('demo:pareceres')->assertSuccessful();

        $ajustes = app(AjustesOrientadorService::class);
        $linhas = $ajustes->projetos($this->orientadorDemo());

        $this->assertCount(1, $linhas);
        $this->assertSame(3, $linhas[0]['sugestoes']);
        $this->assertSame(3, $linhas[0]['pendentes']);
        $this->assertSame(6, $linhas[0]['recomendacoes']);

        $detalhe = $ajustes->detalhe($this->projeto());
        $this->assertSame(['area', 'subarea', 'area'], array_column($detalhe['sugestoes'], 'tipo'));

        // As duas sugestões de área apontam para lugares diferentes: aceitar uma
        // desliga a outra, que é o caso difícil do ensaio.
        $areas = array_column(array_filter(
            $detalhe['sugestoes'],
            fn (array $s) => $s['tipo'] === 'area',
        ), 'sugerido_id');
        $this->assertCount(2, array_unique($areas));
    }

    /** Rodar de novo não duplica nada — é o caminho de quem repete em produção. */
    public function test_comando_e_idempotente(): void
    {
        $this->artisan('demo:pareceres')->assertSuccessful();
        $this->artisan('demo:pareceres')->assertSuccessful();

        $this->assertSame(1, Projeto::withoutGlobalScopes()->count());
        $this->assertSame(3, Avaliacao::count());
        $this->assertSame(1, User::where('email', 'orientador@fetecms.test')->count());
        $this->assertSame(1, User::where('email', 'avaliador.demo3@fetecms.test')->count());
    }

    /** Convive com o `demo:ajustes`: dois projetos, as mesmas contas. */
    public function test_convive_com_o_exemplo_de_ajustes(): void
    {
        $this->artisan('demo:ajustes')->assertSuccessful();
        $this->artisan('demo:pareceres')->assertSuccessful();

        $this->assertSame(2, Projeto::withoutGlobalScopes()->count());
        $this->assertSame(4, User::where('is_demo', true)->count(), 'Um orientador e três avaliadores.');
        $this->assertSame(6, Avaliacao::count());
    }

    /** O que permite conferir a tela antes do período oficial. */
    public function test_orientador_demo_abre_a_aba_fora_do_periodo(): void
    {
        $this->artisan('demo:pareceres')->assertSuccessful();
        $orientador = $this->orientadorDemo();
        $ajustes = app(AjustesOrientadorService::class);

        // Sem data de início, a aba fica fechada para todo mundo…
        $this->assertFalse($ajustes->janela($orientador)['aberta']);
        // …menos para a conta demo que liga o modo de teste.
        $this->assertTrue($ajustes->janela($orientador, true)['aberta']);
    }

    /** O projeto de mentira não entra na conta que a organização vai usar. */
    public function test_projeto_demo_fica_fora_dos_numeros(): void
    {
        $this->artisan('demo:pareceres')->assertSuccessful();

        $m = app(AdminDashboardService::class)->metricas();

        $this->assertSame(0, $m['projetos_total']);
        $this->assertSame(0, $m['projetos_submetidos']);
        $this->assertSame(0, $m['orientadores']);
        $this->assertSame([], Projeto::semDemo()->pluck('id')->all());
    }
}
