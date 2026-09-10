<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Services\DistribuicaoService;
use App\Services\FilaAvaliadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 111 — a **designação manual do admin** não é desfeita por rotina
 * automática nenhuma, e a **avaliação já aberta** também não.
 *
 * A regra mora num lugar só ({@see Avaliacao::scopeDevolvivel()}) para que
 * qualquer devolução — o rodízio do avaliador, a redistribuição em massa, a
 * devolução no fim da sessão — herde a mesma proteção em vez de repeti-la.
 * Estes testes são a rede: se alguém abrir um caminho novo de devolução sem
 * passar por ali, um deles cai.
 */
class DesignacaoManualProtegidaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function avaliador(int $areaId): User
    {
        $user = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);

        return $user;
    }

    private function projeto(int $areaId, string $titulo): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'categoria' => Categoria::Fetecms, 'titulo' => $titulo,
        ]);
    }

    private function designar(Projeto $projeto, User $avaliador, StatusAvaliacao $status, bool $manual = false): Avaliacao
    {
        return Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
            'status' => $status,
            'designacao_manual' => $manual,
            'nota' => $status === StatusAvaliacao::Concluida ? 8 : null,
            'concluida_em' => $status === StatusAvaliacao::Concluida ? now() : null,
        ]);
    }

    public function test_redistribuir_preserva_a_designacao_manual(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $manual = $this->projeto($area->id, 'Designado à mão');
        $automatico = $this->projeto($area->id, 'Designado pelo algoritmo');
        // Alternativas para o rodízio ter para onde ir.
        $this->projeto($area->id, 'Outro 1');
        $this->projeto($area->id, 'Outro 2');

        $daMao = $this->designar($manual, $avaliador, StatusAvaliacao::Designada, manual: true);
        $doAlgoritmo = $this->designar($automatico, $avaliador, StatusAvaliacao::Designada);

        $relatorio = app(DistribuicaoService::class)->redistribuir();

        $this->assertDatabaseHas('avaliacoes', ['id' => $daMao->id, 'projeto_id' => $manual->id]);
        $this->assertDatabaseMissing('avaliacoes', ['id' => $doAlgoritmo->id]);
        $this->assertSame(1, $relatorio['manuais_preservadas']);
    }

    public function test_redistribuir_preserva_avaliacao_em_andamento_e_concluida(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $aberto = $this->projeto($area->id, 'Em avaliação');
        $pronto = $this->projeto($area->id, 'Concluído');
        $this->projeto($area->id, 'Alternativa');

        $emAndamento = $this->designar($aberto, $avaliador, StatusAvaliacao::EmAndamento);
        $emAndamento->update(['respostas' => ['q1' => 8], 'rascunho_em' => now()]);
        $concluida = $this->designar($pronto, $avaliador, StatusAvaliacao::Concluida);

        $relatorio = app(DistribuicaoService::class)->redistribuir();

        $this->assertDatabaseHas('avaliacoes', [
            'id' => $emAndamento->id, 'status' => StatusAvaliacao::EmAndamento->value,
        ]);
        $this->assertDatabaseHas('avaliacoes', [
            'id' => $concluida->id, 'status' => StatusAvaliacao::Concluida->value,
        ]);
        // O rascunho continua onde estava.
        $this->assertSame(['q1' => 8], $emAndamento->fresh()->respostas);
        $this->assertSame(1, $relatorio['em_avaliacao_preservadas']);
    }

    public function test_sortear_outros_projetos_nao_alcanca_a_designacao_manual(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $manual = $this->projeto($area->id, 'Designado à mão');
        $this->projeto($area->id, 'Alternativa 1');
        $this->projeto($area->id, 'Alternativa 2');

        $daMao = $this->designar($manual, $avaliador, StatusAvaliacao::Designada, manual: true);
        $automatica = $this->designar(
            $this->projeto($area->id, 'Do algoritmo'), $avaliador, StatusAvaliacao::Designada,
        );

        app(FilaAvaliadorService::class)->roletar($avaliador);

        $this->assertDatabaseHas('avaliacoes', ['id' => $daMao->id]);
        $this->assertDatabaseMissing('avaliacoes', ['id' => $automatica->id]);
    }

    public function test_distribuir_nao_duplica_projeto_ja_designado_a_mao(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id, 'Único');

        $this->designar($projeto, $avaliador, StatusAvaliacao::Designada, manual: true);

        app(DistribuicaoService::class)->distribuir();

        $this->assertSame(1, Avaliacao::where('projeto_id', $projeto->id)
            ->where('avaliador_id', $avaliador->id)
            ->count());
    }

    public function test_escopo_devolvivel_separa_o_que_pode_voltar_ao_bolo(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $automatica = $this->designar($this->projeto($area->id, 'A'), $avaliador, StatusAvaliacao::Designada);
        $this->designar($this->projeto($area->id, 'B'), $avaliador, StatusAvaliacao::Designada, manual: true);
        $this->designar($this->projeto($area->id, 'C'), $avaliador, StatusAvaliacao::EmAndamento);
        $this->designar($this->projeto($area->id, 'D'), $avaliador, StatusAvaliacao::Concluida);

        $this->assertSame([$automatica->id], Avaliacao::devolvivel()->pluck('id')->all());
        $this->assertSame(3, Avaliacao::protegida()->count());
    }

    public function test_designacao_manual_sobrevive_a_varias_redistribuicoes(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 2]);

        $manual = $this->projeto($area->id, 'Designado à mão');
        for ($i = 0; $i < 5; $i++) {
            $this->projeto($area->id, "Outro {$i}");
        }

        $daMao = $this->designar($manual, $avaliador, StatusAvaliacao::Designada, manual: true);

        for ($i = 0; $i < 3; $i++) {
            app(DistribuicaoService::class)->redistribuir();
        }

        $this->assertDatabaseHas('avaliacoes', [
            'id' => $daMao->id,
            'projeto_id' => $manual->id,
            'avaliador_id' => $avaliador->id,
            'designacao_manual' => true,
        ]);
    }
}
