<?php

namespace Tests\Feature;

use App\Enums\GrupoCorrelato;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use App\Services\AvaliacaoFluxoService;
use App\Services\FilaAvaliadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reposição da fila do avaliador: concluída uma avaliação, entra outro projeto
 * seguindo a prioridade área+subárea → área → área correlata → sorteio.
 */
class FilaAvaliadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    private function fila(): FilaAvaliadorService
    {
        return app(FilaAvaliadorService::class);
    }

    private function avaliador(int $areaId, ?int $subareaId = null, array $over = []): User
    {
        $user = User::factory()->avaliador()->create($over);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $areaId, 'subarea_id' => $subareaId,
        ]);

        return $user->fresh();
    }

    private function projeto(int $areaId, ?int $subareaId = null, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'subarea_id' => $subareaId, 'titulo' => $titulo,
        ]);
    }

    private function designar(Projeto $projeto, User $avaliador, string $status = 'designada'): Avaliacao
    {
        return Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id, 'status' => $status,
        ]);
    }

    /**
     * Sprint 95 — a fila deixou de ser "o primeiro que chegar leva 6".
     *
     * 5 projetos × teto de 5 avaliadores = 25 designações possíveis; 10
     * avaliadores na área dividem isso em 2 cada. Antes, os cinco primeiros
     * saíam com 5 projetos e os cinco últimos com nenhum, porque o bolo já
     * tinha acabado quando chegou a vez deles.
     */
    public function test_a_fila_reparte_o_trabalho_escasso_em_vez_de_encher_os_primeiros(): void
    {
        $area = Area::create(['nome' => 'Área A']);

        foreach (range(1, 5) as $i) {
            $this->projeto($area->id, null, "P{$i}");
        }

        $avaliadores = collect(range(1, 10))->map(fn () => $this->avaliador($area->id));

        $cargas = $avaliadores->map(fn (User $a) => $this->fila()->repor($a));

        $this->assertSame([2], $cargas->unique()->values()->all());
        $this->assertSame(20, Avaliacao::count());
    }

    /** Com trabalho de sobra a cota some: a fila volta a valer o mínimo por avaliador. */
    public function test_com_projeto_de_sobra_a_fila_vai_ate_o_minimo_por_avaliador(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 4]);
        $area = Area::create(['nome' => 'Área A']);

        foreach (range(1, 30) as $i) {
            $this->projeto($area->id, null, "P{$i}");
        }

        $a = $this->avaliador($area->id);
        $b = $this->avaliador($area->id);

        $this->assertSame(4, $this->fila()->repor($a));
        $this->assertSame(4, $this->fila()->repor($b));
    }

    public function test_repoe_a_fila_ate_o_minimo_por_avaliador(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 3]);
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        foreach (range(1, 5) as $i) {
            $this->projeto($area->id, null, "P{$i}");
        }

        $this->assertSame(3, $this->fila()->repor($avaliador));
        $this->assertSame(3, Avaliacao::where('avaliador_id', $avaliador->id)->count());

        // Já cheia, não repõe de novo.
        $this->assertSame(0, $this->fila()->repor($avaliador));
    }

    public function test_prioridade_1_area_e_subarea_do_avaliador(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1]);
        $area = Area::create(['nome' => 'Área A']);
        $sub = Subarea::create(['area_id' => $area->id, 'nome' => 'Sub 1']);
        $outraSub = Subarea::create(['area_id' => $area->id, 'nome' => 'Sub 2']);

        $avaliador = $this->avaliador($area->id, $sub->id);
        $daSubarea = $this->projeto($area->id, $sub->id, 'Da subárea');
        $this->projeto($area->id, $outraSub->id, 'Só da área');

        $this->fila()->repor($avaliador);

        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $daSubarea->id]);
    }

    public function test_prioridade_3_cai_para_area_correlata(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1]);
        $saude = Area::create(['nome' => 'Ciências da Saúde', 'grupo_correlato' => GrupoCorrelato::Vida]);
        $agrarias = Area::create(['nome' => 'Ciências Agrárias', 'grupo_correlato' => GrupoCorrelato::Vida]);
        $exatas = Area::create(['nome' => 'Ciências Exatas', 'grupo_correlato' => GrupoCorrelato::ExatasEngenharias]);

        $avaliador = $this->avaliador($saude->id);
        $irmao = $this->projeto($agrarias->id, null, 'Da área irmã');
        $this->projeto($exatas->id, null, 'De outro grupo');

        $this->fila()->repor($avaliador);

        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $irmao->id]);
        $this->assertSame(1, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_dentro_da_faixa_escolhe_o_menos_avaliado(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1]);
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $cheio = $this->projeto($area->id, null, 'Já avaliado');
        $vazio = $this->projeto($area->id, null, 'Sem avaliação');
        $this->designar($cheio, $this->avaliador($area->id), 'concluida');
        $this->designar($cheio, $this->avaliador($area->id), 'em_andamento');

        $this->fila()->repor($avaliador);

        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $vazio->id]);
    }

    public function test_prioridade_4_sorteia_quando_todos_alcancaram_o_minimo(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1, 'avaliacoes_min_por_projeto' => 1]);
        $area = Area::create(['nome' => 'Área A']);
        $outra = Area::create(['nome' => 'Área sem correlação']);

        $avaliador = $this->avaliador($area->id);

        // O único projeto da área do avaliador já bateu o mínimo.
        $daArea = $this->projeto($area->id, null, 'Da área, completo');
        $this->designar($daArea, $this->avaliador($area->id), 'concluida');

        $foraDeTudo = $this->projeto($outra->id, null, 'Fora da área e do grupo');

        $this->fila()->repor($avaliador);

        // Sem faixa 1–3 disponível, o sorteio alcança qualquer projeto submetido.
        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $foraDeTudo->id]);
    }

    public function test_nao_repete_projeto_que_o_avaliador_ja_tem(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 2]);
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $jaConcluido = $this->projeto($area->id, null, 'Já concluído');
        $this->designar($jaConcluido, $avaliador, 'concluida');
        $novo = $this->projeto($area->id, null, 'Novo');

        $criadas = $this->fila()->repor($avaliador);

        $this->assertSame(1, $criadas);
        $this->assertSame(1, Avaliacao::where('avaliador_id', $avaliador->id)->where('projeto_id', $jaConcluido->id)->count());
        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $novo->id]);
    }

    public function test_respeita_o_teto_de_avaliadores_por_projeto(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1]);
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $lotado = $this->projeto($area->id, null, 'Lotado');
        foreach (range(1, Avaliacao::TETO_POR_PROJETO) as $i) {
            $this->designar($lotado, $this->avaliador($area->id));
        }

        $this->assertSame(0, $this->fila()->repor($avaliador));
        $this->assertSame(0, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_avaliador_bloqueado_pelo_admin_nao_recebe_projeto(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 3]);
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $avaliador->avaliadorProfile->update(['limite_avaliacoes' => 1]);

        $this->designar($this->projeto($area->id, null, 'Feito'), $avaliador, 'concluida');
        $this->projeto($area->id, null, 'Disponível');

        $this->assertSame(0, $this->fila()->repor($avaliador->fresh()));
    }

    public function test_avaliador_demo_fica_fora_da_reposicao(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $demo = $this->avaliador($area->id, null, ['is_demo' => true]);
        $this->projeto($area->id, null, 'Disponível');

        $this->assertSame(0, $this->fila()->repor($demo));
    }

    public function test_concluir_uma_avaliacao_traz_outro_projeto(): void
    {
        Edicao::atual()->update([
            'avaliacoes_min_por_avaliador' => 1,
            'avaliacao_liberada_em' => now()->subDay(),
        ]);

        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $atual = $this->projeto($area->id, null, 'Em avaliação');
        $proximo = $this->projeto($area->id, null, 'Próximo da fila');
        $avaliacao = $this->designar($atual, $avaliador, 'em_andamento');

        app(AvaliacaoFluxoService::class)->concluir($avaliacao, [
            'respostas' => [],
            'area_correta' => true,
            'subarea_correta' => true,
        ]);

        $this->assertSame(StatusAvaliacao::Concluida, $avaliacao->fresh()->status);
        $this->assertDatabaseHas('avaliacoes', [
            'avaliador_id' => $avaliador->id,
            'projeto_id' => $proximo->id,
            'status' => 'designada',
        ]);
    }

    public function test_roletar_troca_os_designados_por_outros(): void
    {
        Edicao::atual()->update([
            'avaliacoes_min_por_avaliador' => 2,
            'avaliacao_liberada_em' => now()->subDay(),
        ]);
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $antigos = [
            $this->projeto($area->id, null, 'Antigo 1'),
            $this->projeto($area->id, null, 'Antigo 2'),
        ];
        foreach ($antigos as $projeto) {
            $this->designar($projeto, $avaliador);
        }
        // Alternativas suficientes para a fila trocar por completo.
        $this->projeto($area->id, null, 'Novo 1');
        $this->projeto($area->id, null, 'Novo 2');

        $resultado = $this->fila()->roletar($avaliador);

        $this->assertSame(2, $resultado['trocados']);
        $this->assertSame(2, $resultado['recebidos']);
        foreach ($antigos as $projeto) {
            $this->assertDatabaseMissing('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $projeto->id]);
        }
        $this->assertSame(2, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_roletar_nao_mexe_no_que_esta_em_avaliacao_nem_no_designado_pelo_admin(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 3]);
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $emAndamento = $this->projeto($area->id, null, 'Em andamento');
        $doAdmin = $this->projeto($area->id, null, 'Designado pelo admin');
        $sorteavel = $this->projeto($area->id, null, 'Sorteável');
        $this->designar($emAndamento, $avaliador, 'em_andamento');
        $this->designar($doAdmin, $avaliador)->update(['designacao_manual' => true]);
        $this->designar($sorteavel, $avaliador);
        $this->projeto($area->id, null, 'Alternativa');

        $resultado = $this->fila()->roletar($avaliador);

        $this->assertSame(1, $resultado['trocados']);
        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $emAndamento->id]);
        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $doAdmin->id]);
        $this->assertDatabaseMissing('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $sorteavel->id]);
    }

    public function test_roletar_sem_alternativa_devolve_os_mesmos_projetos(): void
    {
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 1]);
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $unico = $this->projeto($area->id, null, 'Único da área');
        $this->designar($unico, $avaliador);

        $resultado = $this->fila()->roletar($avaliador);

        // Sem para onde ir, a fila volta como estava — nunca fica vazia.
        $this->assertSame(1, $resultado['trocados']);
        $this->assertSame(1, $resultado['recebidos']);
        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $avaliador->id, 'projeto_id' => $unico->id]);
    }

    public function test_endpoint_de_roletar_exige_periodo_aberto(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $this->designar($this->projeto($area->id, null, 'P'), $avaliador);

        Sanctum::actingAs($avaliador);

        // Avaliação ainda não liberada: 403.
        $this->postJson('/api/v1/avaliacao/roletar')->assertForbidden();

        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);
        $this->projeto($area->id, null, 'Alternativa');

        $this->postJson('/api/v1/avaliacao/roletar')
            ->assertOk()
            ->assertJsonPath('data.trocados', 1);
    }

    public function test_designacao_do_admin_nasce_marcada_como_manual(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id, null, 'P');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/designar", [
            'tipo' => 'avaliador', 'alvo_id' => $avaliador->id,
        ])->assertOk();

        $this->assertDatabaseHas('avaliacoes', [
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id, 'designacao_manual' => true,
        ]);
    }
}
