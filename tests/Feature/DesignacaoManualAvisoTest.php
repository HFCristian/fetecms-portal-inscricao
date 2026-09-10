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
use App\Services\SessaoAvaliadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 113 — designar à mão avisa quem **não pôde receber** o projeto.
 *
 * Quem já avaliou aquele trabalho não o avalia de novo, e designar uma área
 * inteira quase sempre alcança alguém assim. O nome sumir em silêncio faria o
 * admin achar que a cobertura que ele pediu aconteceu.
 */
class DesignacaoManualAvisoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function avaliador(int $areaId, string $nome): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);

        return $user;
    }

    private function projeto(int $areaId): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'categoria' => Categoria::Fetecms, 'titulo' => 'Projeto',
        ]);
    }

    private function designarPara(Projeto $projeto, User $avaliador): TestResponse
    {
        return $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/designar", [
            'tipo' => 'avaliador',
            'alvo_id' => $avaliador->id,
        ]);
    }

    public function test_avisa_quando_o_avaliador_ja_avaliou_o_projeto(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($area->id, 'Ana Souza');
        $projeto = $this->projeto($area->id);

        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $ana->id,
            'status' => StatusAvaliacao::Concluida, 'nota' => 9, 'concluida_em' => now(),
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $resposta = $this->designarPara($projeto, $ana)
            ->assertOk()
            ->assertJsonPath('data.designadas', 0)
            ->assertJsonPath('meta.ja_avaliaram', ['Ana Souza']);

        $this->assertStringContainsString('Ana Souza não pôde receber', $resposta->json('meta.message'));
        $this->assertStringContainsString('já avaliou este projeto', $resposta->json('meta.message'));
    }

    public function test_designa_os_elegiveis_e_lista_quem_ficou_de_fora(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $projeto = $this->projeto($area->id);

        $ana = $this->avaliador($area->id, 'Ana Souza');
        $bruno = $this->avaliador($area->id, 'Bruno Lima');
        $carla = $this->avaliador($area->id, 'Carla Dias');
        $davi = $this->avaliador($area->id, 'Davi Melo');

        foreach ([$ana, $bruno] as $jaAvaliou) {
            Avaliacao::create([
                'projeto_id' => $projeto->id, 'avaliador_id' => $jaAvaliou->id,
                'status' => StatusAvaliacao::Concluida, 'nota' => 8, 'concluida_em' => now(),
            ]);
        }

        Sanctum::actingAs(User::factory()->admin()->create());

        // A área inteira: dois já avaliaram, dois recebem.
        $resposta = $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/designar", [
            'tipo' => 'area', 'alvo_id' => $area->id,
        ])->assertOk();

        $this->assertSame(2, $resposta->json('data.designadas'));
        $this->assertSame(['Ana Souza', 'Bruno Lima'], $resposta->json('meta.ja_avaliaram'));
        $this->assertStringContainsString('Ana Souza e Bruno Lima não puderam receber', $resposta->json('meta.message'));

        foreach ([$carla, $davi] as $recebeu) {
            $this->assertDatabaseHas('avaliacoes', [
                'projeto_id' => $projeto->id, 'avaliador_id' => $recebeu->id, 'designacao_manual' => true,
            ]);
        }
    }

    public function test_quem_ja_tem_o_projeto_na_fila_entra_noutra_lista(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($area->id, 'Ana Souza');
        $projeto = $this->projeto($area->id);

        // Designada pelo algoritmo: nada muda, mas ela passa a ser protegida.
        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $ana->id,
            'status' => StatusAvaliacao::Designada,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $resposta = $this->designarPara($projeto, $ana)
            ->assertOk()
            ->assertJsonPath('data.designadas', 0)
            ->assertJsonPath('data.ja_tem', ['Ana Souza'])
            ->assertJsonPath('meta.ja_avaliaram', []);

        $this->assertStringContainsString('já estava com este projeto na fila', $resposta->json('meta.message'));

        // O admin escolhendo aquela pessoa vira designação manual: a partir de
        // agora nenhuma rotina automática a desfaz.
        $this->assertDatabaseHas('avaliacoes', [
            'projeto_id' => $projeto->id, 'avaliador_id' => $ana->id, 'designacao_manual' => true,
        ]);
    }

    public function test_designar_revive_a_avaliacao_devolvida_pelo_prazo(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($area->id, 'Ana Souza');
        $projeto = $this->projeto($area->id);
        Edicao::atual()->update(['dias_avaliacao_aberta' => 1]);

        $avaliacao = Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $ana->id,
            'status' => StatusAvaliacao::EmAndamento,
            'respostas' => ['q1' => 4], 'atividade_em' => now()->subDays(3),
        ]);

        app(SessaoAvaliadorService::class)->varrer();
        $this->assertNotNull(Avaliacao::comDevolvidas()->find($avaliacao->id)->devolvida_em);

        Sanctum::actingAs(User::factory()->admin()->create());

        $resposta = $this->designarPara($projeto, $ana)
            ->assertOk()
            ->assertJsonPath('data.retomadas', ['Ana Souza']);

        $this->assertStringContainsString('voltaram para Ana Souza', $resposta->json('meta.message'));

        // Uma linha só (a chave única é por projeto+avaliador) e o rascunho intacto.
        $this->assertSame(1, Avaliacao::comDevolvidas()
            ->where('projeto_id', $projeto->id)->where('avaliador_id', $ana->id)->count());

        $viva = $avaliacao->fresh();
        $this->assertNull($viva->devolvida_em);
        $this->assertTrue($viva->designacao_manual);
        $this->assertSame(['q1' => 4], $viva->respostas);
    }

    public function test_designacao_normal_continua_relatando_o_total(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($area->id, 'Ana Souza');
        $projeto = $this->projeto($area->id);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->designarPara($projeto, $ana)
            ->assertOk()
            ->assertJsonPath('data.designadas', 1)
            ->assertJsonPath('meta.message', '1 designação criada.');
    }
}
