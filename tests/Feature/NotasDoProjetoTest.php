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
use App\Support\Rubrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 139 — Verificar disparidade → **Ver notas**: as notas de todos os
 * avaliadores de um projeto lado a lado, com o nome de cada um.
 *
 * A lista de disparidade diz que a maior e a menor nota estão longe; esta
 * consulta diz em **que seção** elas se afastaram e **quem** deu cada uma.
 */
class NotasDoProjetoTest extends TestCase
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
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function projeto(string $titulo = 'Robô seguidor'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'area_id' => $this->area->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);
    }

    /** Avaliação concluída com a mesma resposta em todas as perguntas. */
    private function concluida(Projeto $projeto, int $escala, string $avaliador): Avaliacao
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
            'avaliador_id' => User::factory()->avaliador()->create(['name' => $avaliador])->id,
            'status' => StatusAvaliacao::Concluida,
            'respostas' => $respostas,
            'nota' => Rubrica::nota($respostas),
            'comentario_projeto' => "Parecer de {$avaliador}.",
            'concluida_em' => now(),
        ]);
    }

    private function verNotas(Projeto $projeto)
    {
        return $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/notas");
    }

    public function test_traz_cada_avaliador_com_a_nota_dele_e_os_pontos_por_secao(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $alta = $this->concluida($projeto, 10, 'Ana Souza');
        $baixa = $this->concluida($projeto, 4, 'Bruno Lima');

        $dados = $this->verNotas($projeto)->assertOk()->json('data');

        $this->assertSame('Robô seguidor', $dados['projeto']['titulo']);
        $this->assertCount(2, $dados['avaliadores']);

        // Da maior nota para a menor: a comparação começa pelos extremos.
        $this->assertSame('Ana Souza', $dados['avaliadores'][0]['avaliador']);
        $this->assertSame('Bruno Lima', $dados['avaliadores'][1]['avaliador']);
        $this->assertEquals(round((float) $alta->nota, 2), $dados['avaliadores'][0]['nota']);
        $this->assertEquals(round((float) $baixa->nota, 2), $dados['avaliadores'][1]['nota']);

        // O parecer escrito vai junto do número, porque é o que o explica.
        $this->assertSame('Parecer de Ana Souza.', $dados['avaliadores'][0]['recomendacao_projeto']);

        // O cabeçalho das linhas é a rubrica, igual para todos — é o que torna
        // a comparação legítima —, e a soma das seções fecha com a nota.
        $chaves = array_column($dados['secoes'], 'chave');
        $this->assertSame(array_column(Rubrica::secoesPontuadas(), 'chave'), $chaves);

        $soma = round(array_sum($dados['avaliadores'][0]['secoes']), 1);
        $this->assertEquals(round((float) $alta->nota, 1), $soma);

        // A amplitude é a mesma que a lista de disparidade mostra.
        $this->assertEquals(round((float) $alta->nota - (float) $baixa->nota, 2), $dados['amplitude']);
    }

    public function test_rascunho_e_designada_ficam_de_fora(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $this->concluida($projeto, 8, 'Ana Souza');

        Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create(['name' => 'Carla Dias'])->id,
            'status' => StatusAvaliacao::EmAndamento,
            'respostas' => ['titulo_claro' => 10],
        ]);

        $dados = $this->verNotas($projeto)->assertOk()->json('data');

        $this->assertCount(1, $dados['avaliadores']);
        $this->assertSame('Ana Souza', $dados['avaliadores'][0]['avaliador']);
        // Com uma nota só não há distância para medir.
        $this->assertNull($dados['amplitude']);
    }

    /** Abrir a comparação é ler N notas: cada uma vira uma linha na trilha. */
    public function test_cada_nota_aberta_vira_um_registro(): void
    {
        $admin = $this->admin();
        $projeto = $this->projeto();
        $this->concluida($projeto, 10, 'Ana Souza');
        $this->concluida($projeto, 4, 'Bruno Lima');

        $this->verNotas($projeto)->assertOk();

        $registros = RegistroAtividade::where('tipo', TipoRegistro::NotasVisualizadas)->get();

        $this->assertCount(2, $registros);
        $this->assertSame([$admin->id, $admin->id], $registros->pluck('user_id')->all());
        $this->assertEqualsCanonicalizing(
            ['Ana Souza', 'Bruno Lima'],
            $registros->pluck('detalhes.avaliador')->all(),
        );
    }

    public function test_quem_nao_e_admin_nao_ve_as_notas(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->verNotas($this->projeto())->assertForbidden();
    }
}
