<?php

namespace Tests\Feature;

use App\Enums\ModeloEmail;
use App\Enums\StatusAvaliacao;
use App\Mail\MensagemTransacional;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 121 — Avaliação online → Designações: **designar em massa**.
 *
 * Vários projetos para vários avaliadores de uma vez (o cruzamento de tudo com
 * tudo), pulando quem já avaliou aquele projeto, e os dois e-mails que fecham a
 * operação: o do avaliador, com o que chegou para ele, e o do admin, com o
 * resumo.
 */
class DesignacaoEmMassaTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function projeto(string $titulo): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'area_id' => $this->area->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);
    }

    private function avaliador(string $nome): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $this->area->id]);

        return $user->fresh();
    }

    private function designar(array $projetos, array $avaliadores)
    {
        return $this->postJson('/api/v1/admin/avaliacao/designacoes/designar', [
            'projeto_ids' => collect($projetos)->pluck('id')->all(),
            'avaliador_ids' => collect($avaliadores)->pluck('id')->all(),
        ]);
    }

    // --- O cruzamento -----------------------------------------------------

    public function test_designa_cada_projeto_para_cada_avaliador_escolhido(): void
    {
        $this->admin();
        $projetos = [$this->projeto('Alfa'), $this->projeto('Beta'), $this->projeto('Gama')];
        $avaliadores = [$this->avaliador('Ana'), $this->avaliador('Bruno')];

        $dados = $this->designar($projetos, $avaliadores)->assertOk()->json('data');

        // 3 × 2 = 6 designações.
        $this->assertSame(6, $dados['designadas']);
        $this->assertSame(6, Avaliacao::count());
        $this->assertCount(2, $dados['avaliadores']);
        $this->assertSame(['Alfa', 'Beta', 'Gama'], $dados['avaliadores'][0]['projetos']);
    }

    /** Designação manual: passa por cima dos limites e se protege das devoluções. */
    public function test_as_designacoes_nascem_manuais(): void
    {
        $this->admin();
        $projeto = $this->projeto('Alfa');

        $this->designar([$projeto], [$this->avaliador('Ana')])->assertOk();

        $avaliacao = Avaliacao::firstOrFail();
        $this->assertTrue($avaliacao->designacao_manual);
        $this->assertSame(StatusAvaliacao::Designada, $avaliacao->status);
    }

    // --- Quem fica de fora ------------------------------------------------

    public function test_quem_ja_avaliou_o_projeto_e_pulado_e_o_resto_continua(): void
    {
        $this->admin();
        $projeto = $this->projeto('Alfa');
        $outro = $this->projeto('Beta');
        $jaAvaliou = $this->avaliador('Ana');
        $novo = $this->avaliador('Bruno');

        Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $jaAvaliou->id,
            'status' => StatusAvaliacao::Concluida,
            'nota' => 8.5,
            'concluida_em' => now(),
        ]);

        $dados = $this->designar([$projeto, $outro], [$jaAvaliou, $novo])->assertOk()->json('data');

        // 4 pares, 1 pulado: Ana não recebe o Alfa de volta, mas recebe o Beta.
        $this->assertSame(3, $dados['designadas']);
        $this->assertCount(1, $dados['ignoradas']);
        $this->assertSame('Alfa', $dados['ignoradas'][0]['projeto']);
        $this->assertSame('Ana', $dados['ignoradas'][0]['avaliador']);
        $this->assertSame('já avaliou este projeto', $dados['ignoradas'][0]['motivo']);
        $this->assertStringContainsString('não foram feitas', $dados['problemas']);
    }

    public function test_quem_ja_esta_com_o_projeto_nao_recebe_duas_vezes(): void
    {
        $this->admin();
        $projeto = $this->projeto('Alfa');
        $avaliador = $this->avaliador('Ana');

        Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada,
        ]);

        $dados = $this->designar([$projeto], [$avaliador])->assertOk()->json('data');

        $this->assertSame(0, $dados['designadas']);
        $this->assertSame('já está com este projeto', $dados['ignoradas'][0]['motivo']);
        $this->assertSame(1, Avaliacao::count());
        // A partir de agora a designação manual a protege das devoluções.
        $this->assertTrue(Avaliacao::firstOrFail()->designacao_manual);
    }

    /** Avaliação devolvida pelo prazo revive com o rascunho, sem violar a chave única. */
    public function test_avaliacao_devolvida_pelo_prazo_e_retomada(): void
    {
        $this->admin();
        $projeto = $this->projeto('Alfa');
        $avaliador = $this->avaliador('Ana');

        Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::EmAndamento,
            'respostas' => ['q1' => 8],
            'devolvida_em' => now()->subDay(),
        ]);

        $dados = $this->designar([$projeto], [$avaliador])->assertOk()->json('data');

        $this->assertSame(1, $dados['retomadas']);
        $avaliacao = Avaliacao::firstOrFail();
        $this->assertNull($avaliacao->devolvida_em);
        $this->assertSame(['q1' => 8], $avaliacao->respostas, 'O rascunho volta junto.');
    }

    // --- Os e-mails -------------------------------------------------------

    public function test_cada_avaliador_recebe_um_email_com_a_lista_do_que_chegou(): void
    {
        $this->admin();
        $projetos = [$this->projeto('Alfa'), $this->projeto('Beta')];
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');

        $this->designar($projetos, [$ana, $bruno])->assertOk();

        // Um e-mail por pessoa, não um por projeto.
        Mail::assertQueued(MensagemTransacional::class, function (MensagemTransacional $m) use ($ana) {
            return $m->hasTo($ana->email)
                && $m->modelo === ModeloEmail::ProjetosDesignados
                && str_contains($m->corpo, 'Alfa')
                && str_contains($m->corpo, 'Beta');
        });

        Mail::assertQueued(
            MensagemTransacional::class,
            fn (MensagemTransacional $m) => $m->modelo === ModeloEmail::ProjetosDesignados && $m->hasTo($bruno->email),
        );

        $this->assertSame(2, $this->quantos(ModeloEmail::ProjetosDesignados));
    }

    public function test_o_admin_recebe_o_resumo_dizendo_que_deu_tudo_certo(): void
    {
        $admin = $this->admin();
        $this->designar([$this->projeto('Alfa')], [$this->avaliador('Ana')])->assertOk();

        Mail::assertQueued(MensagemTransacional::class, function (MensagemTransacional $m) use ($admin) {
            return $m->hasTo($admin->email)
                && $m->modelo === ModeloEmail::DesignacaoConcluida
                && str_contains($m->corpo, 'Todas as designações foram realizadas');
        });
    }

    public function test_o_resumo_do_admin_lista_o_que_nao_pode_ser_designado(): void
    {
        $admin = $this->admin();
        $projeto = $this->projeto('Alfa');
        $avaliador = $this->avaliador('Ana');

        Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Concluida,
            'nota' => 9.0,
            'concluida_em' => now(),
        ]);

        $this->designar([$projeto], [$avaliador])->assertOk();

        Mail::assertQueued(MensagemTransacional::class, function (MensagemTransacional $m) use ($admin) {
            return $m->hasTo($admin->email)
                && $m->modelo === ModeloEmail::DesignacaoConcluida
                && str_contains($m->corpo, 'Alfa → Ana: já avaliou este projeto');
        });

        // Sem designação nova, ninguém é avisado à toa.
        $this->assertSame(0, $this->quantos(ModeloEmail::ProjetosDesignados));
    }

    // --- As travas e as opções --------------------------------------------

    public function test_recusa_sem_projeto_ou_sem_avaliador(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/avaliacao/designacoes/designar', [
            'projeto_ids' => [], 'avaliador_ids' => [1],
        ])->assertStatus(422)->assertJsonValidationErrors('projeto_ids');

        // Ids que não existem também não passam: a mensagem vem do serviço.
        $this->postJson('/api/v1/admin/avaliacao/designacoes/designar', [
            'projeto_ids' => [999], 'avaliador_ids' => [998],
        ])->assertStatus(422)->assertJsonValidationErrors('projeto_ids');
    }

    public function test_avaliador_inativo_nao_recebe_designacao(): void
    {
        $this->admin();
        $projeto = $this->projeto('Alfa');
        $inativo = $this->avaliador('Ana');
        $inativo->update(['is_active' => false]);

        $this->postJson('/api/v1/admin/avaliacao/designacoes/designar', [
            'projeto_ids' => [$projeto->id], 'avaliador_ids' => [$inativo->id],
        ])->assertStatus(422)->assertJsonValidationErrors('avaliador_ids');
    }

    public function test_as_opcoes_trazem_projetos_e_avaliadores_com_busca(): void
    {
        $this->admin();
        $this->projeto('Bioplástico de mandioca');
        $this->projeto('Sensor de nível');
        $this->avaliador('Ana Souza');
        $this->avaliador('Bruno Lima');

        $tudo = $this->getJson('/api/v1/admin/avaliacao/designacoes/opcoes')->assertOk()->json('data');
        $this->assertCount(2, $tudo['projetos']);
        $this->assertCount(2, $tudo['avaliadores']);

        $filtrado = $this->getJson('/api/v1/admin/avaliacao/designacoes/opcoes?projeto=mandioca&avaliador=ana')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $filtrado['projetos']);
        $this->assertSame('Bioplástico de mandioca', $filtrado['projetos'][0]['titulo']);
        $this->assertCount(1, $filtrado['avaliadores']);
        $this->assertSame('Ana Souza', $filtrado['avaliadores'][0]['nome']);
    }

    /** Projeto de conta demo não entra nem na lista de designação. */
    public function test_as_opcoes_nao_trazem_projeto_de_demonstracao(): void
    {
        $this->admin();
        $demo = User::factory()->create(['is_demo' => true]);
        Projeto::factory()->submetido()->create([
            'user_id' => $demo->id,
            'titulo' => 'Projeto de ensaio',
            'edicao_id' => Edicao::atual()?->id,
        ]);

        $dados = $this->getJson('/api/v1/admin/avaliacao/designacoes/opcoes')->assertOk()->json('data');

        $this->assertSame([], $dados['projetos']);
    }

    private function quantos(ModeloEmail $modelo): int
    {
        return Mail::queued(
            MensagemTransacional::class,
            fn (MensagemTransacional $m) => $m->modelo === $modelo,
        )->count();
    }
}
