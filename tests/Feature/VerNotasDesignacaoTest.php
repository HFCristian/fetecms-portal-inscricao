<?php

namespace Tests\Feature;

use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Support\Rubrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 125 — Avaliação online → Designações: **Ver notas**.
 *
 * O detalhe da nota que um avaliador deu a um projeto, seção por seção e com a
 * soma geral — e o registro de **quem consultou**, que é o que protege o sigilo
 * de um parecer anônimo para o orientador.
 */
class VerNotasDesignacaoTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function projeto(string $titulo = 'Bioplástico de mandioca'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'area_id' => $this->area->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);
    }

    /**
     * Uma avaliação concluída com todas as perguntas respondidas: a escala em
     * `$escala` e "Sim" nas de sim/não.
     */
    private function concluida(Projeto $projeto, int $escala = 8, string $avaliador = 'Ana Souza'): Avaliacao
    {
        $respostas = Rubrica::normalizar(
            collect(Rubrica::perguntas())
                ->mapWithKeys(fn (array $p) => [
                    $p['chave'] => $p['tipo'] === Rubrica::TIPO_SIM_NAO ? true : $escala,
                ])
                ->all()
        );

        return Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create(['name' => $avaliador])->id,
            'status' => StatusAvaliacao::Concluida,
            'respostas' => $respostas,
            'nota' => Rubrica::nota($respostas),
            'comentario_video' => 'Melhorar o áudio da narração.',
            'comentario_projeto' => 'Detalhar a metodologia.',
            'concluida_em' => now(),
        ]);
    }

    private function verNotas(Avaliacao $avaliacao)
    {
        return $this->postJson("/api/v1/admin/avaliacao/designacoes/{$avaliacao->id}/notas");
    }

    // --- O detalhe --------------------------------------------------------

    public function test_mostra_a_nota_secao_por_secao_com_a_soma_geral(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->concluida($projeto);

        $dados = $this->verNotas($avaliacao)->assertOk()->json('data');

        $this->assertSame($projeto->titulo, $dados['projeto']['titulo']);
        $this->assertSame('Ana Souza', $dados['avaliador']);
        $this->assertEquals(10.0, $dados['nota_maxima']);

        // Uma entrada por seção pontuada da rubrica oficial.
        $this->assertCount(count(Rubrica::secoesPontuadas()), $dados['secoes']);

        // A soma das seções fecha com a nota — é a promessa da tela.
        $soma = round(array_sum(array_column($dados['secoes'], 'pontos')), 1);
        $this->assertSame(round((float) $avaliacao->nota, 1), $soma);
        $this->assertSame(round((float) $avaliacao->nota, 2), $dados['nota']);

        // O parecer escrito acompanha o número, que é o que o explica.
        $this->assertSame('Melhorar o áudio da narração.', $dados['recomendacao_video']);
        $this->assertSame('Detalhar a metodologia.', $dados['recomendacao_projeto']);
    }

    /** Cada pergunta vem com o rótulo da escala, como o avaliador a leu. */
    public function test_cada_pergunta_traz_a_resposta_em_palavras_e_os_pontos(): void
    {
        $this->admin();
        $avaliacao = $this->concluida($this->projeto(), escala: 8);

        $secoes = $this->verNotas($avaliacao)->assertOk()->json('data.secoes');
        $perguntas = collect($secoes)->flatMap(fn (array $s) => $s['perguntas']);

        $this->assertCount(count(Rubrica::perguntas()), $perguntas);

        $escala = $perguntas->firstWhere('tipo', Rubrica::TIPO_ESCALA);
        $this->assertSame('8 — Bom', $escala['resposta']);
        $this->assertTrue($escala['respondida']);
        // 8 de 10 da escala: oito décimos do peso da pergunta.
        $this->assertSame(round($escala['peso'] * 0.8, 2), $escala['pontos']);

        $simNao = $perguntas->firstWhere('tipo', Rubrica::TIPO_SIM_NAO);
        if ($simNao !== null) {
            $this->assertSame('Sim', $simNao['resposta']);
            $this->assertSame(round((float) $simNao['peso'], 2), $simNao['pontos']);
        }
    }

    /** Pergunta em branco é dita como tal, em vez de virar um zero silencioso. */
    public function test_pergunta_sem_resposta_aparece_como_nao_respondida(): void
    {
        $this->admin();
        $avaliacao = $this->concluida($this->projeto());

        $chave = Rubrica::perguntas()[0]['chave'];
        $respostas = $avaliacao->respostas;
        unset($respostas[$chave]);
        $avaliacao->update(['respostas' => $respostas]);

        $secoes = $this->verNotas($avaliacao)->assertOk()->json('data.secoes');
        $pergunta = collect($secoes)->flatMap(fn (array $s) => $s['perguntas'])->firstWhere('chave', $chave);

        $this->assertFalse($pergunta['respondida']);
        $this->assertNull($pergunta['resposta']);
        $this->assertEquals(0.0, $pergunta['pontos']);
    }

    public function test_avaliacao_nao_concluida_nao_tem_nota_para_mostrar(): void
    {
        $this->admin();
        $projeto = $this->projeto();

        $designada = Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create()->id,
            'status' => StatusAvaliacao::Designada,
        ]);

        $this->verNotas($designada)
            ->assertStatus(422)
            ->assertJsonValidationErrors('avaliacao');

        // E nada é registrado: não houve nota para ver.
        $this->assertSame(0, RegistroAtividade::where('tipo', TipoRegistro::NotasVisualizadas)->count());
    }

    // --- O registro da consulta -------------------------------------------

    public function test_ver_a_nota_fica_registrado_com_quem_viu_e_quanto(): void
    {
        $admin = $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->concluida($projeto);

        $this->verNotas($avaliacao)->assertOk();

        $registro = RegistroAtividade::where('tipo', TipoRegistro::NotasVisualizadas)->firstOrFail();

        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($projeto->id, $registro->projeto_id);
        $this->assertSame($projeto->titulo, $registro->projeto_titulo);
        $this->assertSame('Ana Souza', $registro->detalhes['avaliador']);
        $this->assertSame(number_format((float) $avaliacao->nota, 2, ',', ''), $registro->detalhes['nota']);
    }

    /** Cada abertura é uma consulta: o registro conta acessos, não avaliações. */
    public function test_cada_consulta_gera_um_registro(): void
    {
        $this->admin();
        $avaliacao = $this->concluida($this->projeto());

        $this->verNotas($avaliacao)->assertOk();
        $this->verNotas($avaliacao)->assertOk();

        $this->assertSame(2, RegistroAtividade::where('tipo', TipoRegistro::NotasVisualizadas)->count());
    }

    public function test_o_registro_cai_na_secao_notas_e_se_descreve(): void
    {
        $this->admin();
        $this->verNotas($this->concluida($this->projeto()))->assertOk();

        $this->assertSame(TipoRegistro::SECAO_NOTAS, TipoRegistro::NotasVisualizadas->secao());
        $this->assertContains(TipoRegistro::SECAO_NOTAS, TipoRegistro::secoes());

        $linha = $this->getJson('/api/v1/admin/registros?secao=notas')->assertOk()->json('data.0');

        $this->assertSame('notas_visualizadas', $linha['tipo']);
        $this->assertStringContainsString('viu a nota de Ana Souza', $linha['detalhes_texto']);
        $this->assertStringContainsString('nota', $linha['detalhes_texto']);

        // A seção nova não contamina as outras.
        $this->assertSame([], $this->getJson('/api/v1/admin/registros?secao=avaliacao')->json('data'));
    }

    public function test_admin_sem_a_aba_avaliacao_recebe_403(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::atual()?->id]);
        $avaliacao = $this->concluida($this->projeto());
        Sanctum::actingAs($admin->fresh());

        $this->postJson("/api/v1/admin/avaliacao/designacoes/{$avaliacao->id}/notas")->assertForbidden();
    }
}
