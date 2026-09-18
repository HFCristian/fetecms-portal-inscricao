<?php

namespace Tests\Feature;

use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\AdminAvaliacaoService;
use App\Services\AvaliadorService;
use App\Services\PareceresOrientadorService;
use App\Services\VerificacaoDisparidadeService;
use App\Support\Rubrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 141 — a nota **desconsiderada**.
 *
 * Ela continua na tabela, com o motivo e quem a descartou, e some de tudo que
 * classifica: média, ranking, lista final, disparidade, pareceres e a cobertura
 * do projeto. O certificado do avaliador, não — quem descartou foi a
 * organização, e o trabalho aconteceu.
 */
class DesconsiderarNotaTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::create(['nome' => 'Ciências Exatas', 'sigla' => 'EXA']);
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function admin(): User
    {
        Sanctum::actingAs($admin = User::factory()->admin()->create());

        return $admin;
    }

    private function projeto(string $titulo = 'Secador solar'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'area_id' => $this->area->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);
    }

    private function avaliar(Projeto $projeto, int $escala, string $nome): Avaliacao
    {
        $respostas = Rubrica::normalizar(
            collect(Rubrica::perguntas())
                ->mapWithKeys(fn (array $p) => [
                    $p['chave'] => $p['tipo'] === Rubrica::TIPO_SIM_NAO ? $escala >= 6 : $escala,
                ])
                ->all()
        );

        return Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create(['name' => $nome])->id,
            'status' => StatusAvaliacao::Concluida,
            'respostas' => $respostas,
            'nota' => Rubrica::nota($respostas),
            'concluida_em' => now(),
        ]);
    }

    private function desconsiderar(Avaliacao $a, string $motivo = 'Avaliou o projeto errado.')
    {
        return $this->postJson(
            "/api/v1/admin/avaliacao/avaliacoes/{$a->id}/desconsiderar",
            ['justificativa' => $motivo],
        );
    }

    // --- O ato -------------------------------------------------------------

    public function test_desconsiderar_guarda_quem_quando_e_por_que_sem_apagar_a_nota(): void
    {
        $admin = $this->admin();
        $projeto = $this->projeto();
        $alta = $this->avaliar($projeto, 10, 'Ana Souza');
        $this->avaliar($projeto, 4, 'Bruno Lima');

        $dados = $this->desconsiderar($alta, 'Avaliou o projeto errado.')->assertOk()->json('data');

        $alta->refresh();
        $this->assertTrue($alta->foiDesconsiderada());
        $this->assertSame($admin->id, $alta->desconsiderada_por);
        $this->assertSame('Avaliou o projeto errado.', $alta->desconsiderada_motivo);
        // A avaliação inteira continua ali: as respostas são a prova.
        $this->assertNotEmpty($alta->respostas);

        // A tela continua mostrando a nota, agora marcada.
        $coluna = collect($dados['avaliadores'])->firstWhere('avaliador', 'Ana Souza');
        $this->assertTrue($coluna['desconsiderada']);
        $this->assertSame('Avaliou o projeto errado.', $coluna['desconsiderada_motivo']);
        $this->assertSame($admin->name, $coluna['desconsiderada_por']);

        // A média do diálogo já é só do que conta.
        $this->assertSame(1, $dados['consideradas']);
        $this->assertSame(1, $dados['desconsideradas']);
        $this->assertEquals(round((float) $projeto->avaliacoes()->where('id', '!=', $alta->id)->first()->nota, 2), $dados['media']);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::NotaDesconsiderada)->firstOrFail();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame('Ana Souza', $registro->detalhes['avaliador']);
        $this->assertSame('Avaliou o projeto errado.', $registro->detalhes['justificativa']);
    }

    public function test_justificativa_e_obrigatoria(): void
    {
        $this->admin();
        $avaliacao = $this->avaliar($this->projeto(), 10, 'Ana Souza');

        $this->postJson("/api/v1/admin/avaliacao/avaliacoes/{$avaliacao->id}/desconsiderar", ['justificativa' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('justificativa');

        $this->assertFalse($avaliacao->fresh()->foiDesconsiderada());
    }

    public function test_avaliacao_sem_nota_nao_pode_ser_desconsiderada(): void
    {
        $this->admin();
        $projeto = $this->projeto();

        $emAndamento = Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create()->id,
            'status' => StatusAvaliacao::EmAndamento,
        ]);

        $this->desconsiderar($emAndamento)->assertStatus(422)->assertJsonValidationErrors('avaliacao');
    }

    public function test_reconsiderar_devolve_a_nota_para_a_conta_e_tambem_fica_registrado(): void
    {
        $this->admin();
        $avaliacao = $this->avaliar($this->projeto(), 10, 'Ana Souza');

        $this->desconsiderar($avaliacao)->assertOk();

        $this->postJson(
            "/api/v1/admin/avaliacao/avaliacoes/{$avaliacao->id}/reconsiderar",
            ['justificativa' => 'Era o projeto certo, afinal.'],
        )->assertOk();

        $avaliacao->refresh();
        $this->assertFalse($avaliacao->foiDesconsiderada());
        $this->assertNull($avaliacao->desconsiderada_por);
        $this->assertNull($avaliacao->desconsiderada_motivo);

        $this->assertSame(1, RegistroAtividade::where('tipo', TipoRegistro::NotaReconsiderada)->count());
    }

    /** Desconsiderar duas vezes não faz sentido, e a resposta diz isso. */
    public function test_nota_ja_desconsiderada_nao_e_desconsiderada_de_novo(): void
    {
        $this->admin();
        $avaliacao = $this->avaliar($this->projeto(), 10, 'Ana Souza');

        $this->desconsiderar($avaliacao)->assertOk();
        $this->desconsiderar($avaliacao)->assertStatus(422)->assertJsonValidationErrors('avaliacao');
    }

    // --- O alcance ---------------------------------------------------------

    public function test_a_nota_sai_do_ranking_da_lista_final_e_da_disparidade(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $alta = $this->avaliar($projeto, 10, 'Ana Souza');
        $baixa = $this->avaliar($projeto, 2, 'Bruno Lima');

        $ranking = app(AdminAvaliacaoService::class);
        $disparidade = app(VerificacaoDisparidadeService::class);

        $antes = collect($ranking->rankingProjetos()['projetos'] ?? $ranking->rankingProjetos())
            ->firstWhere('titulo', $projeto->titulo);
        $this->assertEquals(
            round(((float) $alta->nota + (float) $baixa->nota) / 2, 2),
            $antes['media'],
        );
        $this->assertNotEmpty($disparidade->calcular(1.0));

        $this->desconsiderar($alta)->assertOk();

        $depois = collect($ranking->rankingProjetos()['projetos'] ?? $ranking->rankingProjetos())
            ->firstWhere('titulo', $projeto->titulo);
        // A média passa a ser só a que sobrou.
        $this->assertEquals(round((float) $baixa->nota, 2), $depois['media']);
        $this->assertSame(1, $depois['avaliacoes']);

        // E com uma nota só não há mais distância para medir: o projeto sai da
        // lista de disparidade.
        $this->assertSame([], $disparidade->calcular(1.0));
    }

    /**
     * A vaga volta a abrir: o projeto tinha o número máximo de avaliações e
     * passa a caber mais uma — é o que deixa o substituto entrar.
     */
    public function test_o_projeto_volta_a_precisar_daquele_parecer(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $primeira = $this->avaliar($projeto, 10, 'Ana Souza');

        $contar = fn () => Avaliacao::where('projeto_id', $projeto->id)
            ->considerada()
            ->where('status', StatusAvaliacao::Concluida->value)
            ->count();

        $this->assertSame(1, $contar());
        $this->desconsiderar($primeira)->assertOk();
        $this->assertSame(0, $contar());
    }

    /** O orientador lê a média do que conta — não a do que foi descartado. */
    public function test_o_parecer_do_orientador_deixa_de_mostrar_a_nota_descartada(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $alta = $this->avaliar($projeto, 10, 'Ana Souza');
        $baixa = $this->avaliar($projeto, 2, 'Bruno Lima');

        $this->desconsiderar($alta)->assertOk();

        $detalhe = app(PareceresOrientadorService::class)->detalhe($projeto->fresh());

        $this->assertSame(1, $detalhe['avaliacoes']);
        $this->assertEquals(round((float) $baixa->nota, 2), $detalhe['media']);
    }

    /** O certificado e o ranking do avaliador não são punição do admin. */
    public function test_o_certificado_do_avaliador_continua_contando(): void
    {
        $this->admin();
        $avaliacao = $this->avaliar($this->projeto(), 10, 'Ana Souza');
        $avaliador = $avaliacao->avaliador;

        $antes = app(AvaliadorService::class)->estatisticas($avaliador);
        $this->desconsiderar($avaliacao)->assertOk();
        $depois = app(AvaliadorService::class)->estatisticas($avaliador);

        $this->assertSame($antes['avaliacoes_concluidas'], $depois['avaliacoes_concluidas']);
        $this->assertSame($antes['certificado_minutos'], $depois['certificado_minutos']);
        $this->assertSame($antes['posicao'], $depois['posicao']);
    }

    public function test_quem_nao_e_admin_nao_desconsidera_nota(): void
    {
        $avaliacao = $this->avaliar($this->projeto(), 10, 'Ana Souza');
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->desconsiderar($avaliacao)->assertForbidden();
        $this->assertFalse($avaliacao->fresh()->foiDesconsiderada());
    }
}
