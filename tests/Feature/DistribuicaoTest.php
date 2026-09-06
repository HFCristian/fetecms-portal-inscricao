<?php

namespace Tests\Feature;

use App\Enums\GrupoCorrelato;
use App\Enums\StatusDistribuicao;
use App\Jobs\ProcessarDistribuicao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Distribuicao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use App\Services\DistribuicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DistribuicaoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As rodadas de distribuição pertencem a uma edição.
     *
     * Estes cenários foram escritos quando o alvo da distribuição era o mínimo
     * por projeto (3). Hoje o alvo é o campo próprio *designações por projeto*,
     * que em branco segue o **máximo** (5) — por isso ele vem fixado em 3 aqui:
     * o que estes testes verificam é a mecânica (rodadas iguais, preferência de
     * subárea, área irmã), não o tamanho do alvo.
     */
    private function edicaoPadrao(): Edicao
    {
        $edicao = Edicao::firstOrCreate(
            ['ano' => 2026],
            ['nome' => 'XVI FETECMS', 'inscricoes_abertas' => true, 'padrao' => true],
        );

        if ($edicao->designacoes_por_projeto === null) {
            $edicao->update(['designacoes_por_projeto' => 3]);
        }

        return $edicao;
    }

    private function avaliador(int $areaId, ?int $subareaId = null, array $over = []): User
    {
        $user = User::factory()->avaliador()->create($over);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $areaId, 'subarea_id' => $subareaId,
        ]);

        return $user;
    }

    private function projetoSubmetido(int $areaId, ?int $subareaId = null, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'subarea_id' => $subareaId, 'titulo' => $titulo,
        ]);
    }

    private function distribuir(): array
    {
        return app(DistribuicaoService::class)->distribuir();
    }

    /**
     * Sprint 95 — rodadas iguais: ninguém recebe o 2º projeto antes de todos os
     * elegíveis terem o 1º.
     *
     * Os quatro projetos são da subárea do avaliador A, e a preferência por
     * subárea vinha ANTES da carga: A levava os quatro e B ficava a zero. O
     * teto de rodada corta isso — a preferência continua valendo, mas só entre
     * quem ainda não recebeu o projeto daquela rodada.
     */
    public function test_reparte_em_rodadas_iguais_mesmo_com_preferencia_de_subarea(): void
    {
        $this->edicaoPadrao()->update([
            'avaliacoes_min_por_projeto' => 1, 'designacoes_por_projeto' => 1,
        ]);

        $area = Area::create(['nome' => 'Área A']);
        $sub = Subarea::create(['area_id' => $area->id, 'nome' => 'Subárea 1']);

        $especialista = $this->avaliador($area->id, $sub->id);
        $generalista = $this->avaliador($area->id);

        foreach (range(1, 4) as $i) {
            $this->projetoSubmetido($area->id, $sub->id, "P{$i}");
        }

        $this->distribuir();

        $this->assertSame(2, Avaliacao::where('avaliador_id', $especialista->id)->count());
        $this->assertSame(2, Avaliacao::where('avaliador_id', $generalista->id)->count());
    }

    /** A carga fica no máximo 1 acima da menor dentro da mesma área. */
    public function test_a_carga_nao_passa_de_um_projeto_de_diferenca_entre_avaliadores_da_area(): void
    {
        $this->edicaoPadrao()->update([
            'avaliacoes_min_por_projeto' => 2, 'designacoes_por_projeto' => 2,
        ]);

        $area = Area::create(['nome' => 'Área A']);
        $avaliadores = collect(range(1, 5))->map(fn () => $this->avaliador($area->id));

        foreach (range(1, 7) as $i) {
            $this->projetoSubmetido($area->id, null, "P{$i}");
        }

        $this->distribuir();

        $cargas = $avaliadores->map(fn (User $a) => Avaliacao::where('avaliador_id', $a->id)->count());

        $this->assertSame(14, $cargas->sum());
        $this->assertLessThanOrEqual(1, $cargas->max() - $cargas->min());
    }

    public function test_distribui_ate_o_alvo_ignora_demo_e_e_idempotente(): void
    {
        $this->edicaoPadrao();
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $demo = $this->avaliador($a->id, null, ['is_demo' => true]);

        $p = $this->projetoSubmetido($a->id);

        $r = $this->distribuir();

        $this->assertSame(3, $r['designadas_criadas']);
        $this->assertSame(3, Avaliacao::where('projeto_id', $p->id)->count());
        $this->assertSame(0, Avaliacao::where('avaliador_id', $demo->id)->count());
        $this->assertSame([], $r['sub_cobertos']);

        // Rodar de novo não adiciona nada.
        $this->assertSame(0, $this->distribuir()['designadas_criadas']);
        $this->assertSame(3, Avaliacao::where('projeto_id', $p->id)->count());
    }

    public function test_prefere_avaliadores_da_mesma_subarea(): void
    {
        $this->edicaoPadrao();
        $a = Area::create(['nome' => 'Área A']);
        $s1 = Subarea::create(['area_id' => $a->id, 'nome' => 'Sub 1']);
        $s2 = Subarea::create(['area_id' => $a->id, 'nome' => 'Sub 2']);

        $sub1a = $this->avaliador($a->id, $s1->id);
        $sub1b = $this->avaliador($a->id, $s1->id);
        $this->avaliador($a->id, $s2->id);
        $this->avaliador($a->id, $s2->id);

        $p = $this->projetoSubmetido($a->id, $s1->id);

        $this->distribuir();

        // Os dois da subárea do projeto entram (preferência).
        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $sub1a->id]);
        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $sub1b->id]);
        $this->assertSame(3, Avaliacao::where('projeto_id', $p->id)->count());
    }

    public function test_relata_projetos_sub_cobertos(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id); // apenas 1 avaliador na área

        $p = $this->projetoSubmetido($a->id, null, 'Projeto sub');

        $r = $this->distribuir();

        $this->assertSame(1, Avaliacao::where('projeto_id', $p->id)->count());
        $this->assertCount(1, $r['sub_cobertos']);
        $this->assertSame($p->id, $r['sub_cobertos'][0]['projeto_id']);
        $this->assertSame(2, $r['sub_cobertos'][0]['faltam']);
    }

    public function test_respeita_o_limite_individual(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $limitado = $this->avaliador($a->id);
        $limitado->avaliadorProfile->update(['limite_avaliacoes' => 1]);
        $this->avaliador($a->id);
        $this->avaliador($a->id);

        $this->projetoSubmetido($a->id, null, 'P1');
        $this->projetoSubmetido($a->id, null, 'P2');

        $this->distribuir();

        $this->assertLessThanOrEqual(1, Avaliacao::where('avaliador_id', $limitado->id)->count());
    }

    public function test_cai_para_area_irma_quando_a_propria_area_nao_tem_avaliador(): void
    {
        $agrarias = Area::create(['nome' => 'Ciências Agrárias', 'grupo_correlato' => GrupoCorrelato::Vida]);
        $saude = Area::create(['nome' => 'Ciências da Saúde', 'grupo_correlato' => GrupoCorrelato::Vida]);
        $exatas = Area::create(['nome' => 'Ciências Exatas', 'grupo_correlato' => GrupoCorrelato::ExatasEngenharias]);

        // Nenhum avaliador de Agrárias: os três da área irmã assumem.
        $irmaos = [$this->avaliador($saude->id), $this->avaliador($saude->id), $this->avaliador($saude->id)];
        $forasteiro = $this->avaliador($exatas->id);

        $p = $this->projetoSubmetido($agrarias->id);

        $r = $this->distribuir();

        $this->assertSame(3, $r['designadas_criadas']);
        $this->assertSame([], $r['sub_cobertos']);
        foreach ($irmaos as $irmao) {
            $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $irmao->id]);
        }
        // Área de outro grupo nunca é chamada.
        $this->assertDatabaseMissing('avaliacoes', ['avaliador_id' => $forasteiro->id]);
    }

    public function test_a_propria_area_vem_antes_da_irma(): void
    {
        $this->edicaoPadrao();
        $engenharias = Area::create(['nome' => 'Engenharias', 'grupo_correlato' => GrupoCorrelato::ExatasEngenharias]);
        $exatas = Area::create(['nome' => 'Ciências Exatas', 'grupo_correlato' => GrupoCorrelato::ExatasEngenharias]);

        $proprios = [$this->avaliador($engenharias->id), $this->avaliador($engenharias->id), $this->avaliador($engenharias->id)];
        $irmao = $this->avaliador($exatas->id);

        $p = $this->projetoSubmetido($engenharias->id);

        $this->distribuir();

        foreach ($proprios as $proprio) {
            $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $proprio->id]);
        }
        $this->assertDatabaseMissing('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $irmao->id]);
    }

    public function test_area_sem_grupo_nao_tem_irma(): void
    {
        $solta = Area::create(['nome' => 'Área solta']);
        $outra = Area::create(['nome' => 'Ciências da Saúde', 'grupo_correlato' => GrupoCorrelato::Vida]);

        $this->avaliador($outra->id);
        $p = $this->projetoSubmetido($solta->id, null, 'Projeto solto');

        $r = $this->distribuir();

        $this->assertSame(0, Avaliacao::where('projeto_id', $p->id)->count());
        $this->assertCount(1, $r['sub_cobertos']);
        $this->assertSame(3, $r['sub_cobertos'][0]['faltam']);
    }

    public function test_completa_com_a_irma_quando_a_propria_area_se_esgota(): void
    {
        $biologicas = Area::create(['nome' => 'Ciências Biológicas', 'grupo_correlato' => GrupoCorrelato::Vida]);
        $saude = Area::create(['nome' => 'Ciências da Saúde', 'grupo_correlato' => GrupoCorrelato::Vida]);

        $proprio = $this->avaliador($biologicas->id);
        $irmao = $this->avaliador($saude->id);
        $this->avaliador($saude->id);

        $p = $this->projetoSubmetido($biologicas->id);

        $r = $this->distribuir();

        $this->assertSame(3, $r['designadas_criadas']);
        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $proprio->id]);
        $this->assertDatabaseHas('avaliacoes', ['projeto_id' => $p->id, 'avaliador_id' => $irmao->id]);
    }

    /**
     * A distribuição vai para a fila e a tela acompanha o progresso, em vez de
     * segurar a requisição até o fim (que, com a base cheia, é uma espera cega
     * e um bom candidato a timeout).
     */
    public function test_endpoint_de_distribuicao_enfileira_e_reporta_progresso(): void
    {
        $this->edicaoPadrao();
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $this->avaliador($a->id);
        $this->projetoSubmetido($a->id);

        Sanctum::actingAs(User::factory()->admin()->create());

        // Em teste a fila é síncrona; o fake segura o job para dar tempo de
        // conferir que a requisição volta ANTES de o trabalho acontecer.
        Queue::fake();

        $resposta = $this->postJson('/api/v1/admin/avaliacao/distribuir')
            ->assertStatus(202)
            ->assertJsonPath('data.tipo', Distribuicao::TIPO_DISTRIBUIR)
            ->assertJsonPath('data.status', 'pendente')
            ->assertJsonPath('data.finalizada', false);

        $id = $resposta->json('data.id');

        // Nada foi designado ainda: quem trabalha é a fila.
        $this->assertSame(0, Avaliacao::count());
        Queue::assertPushed(ProcessarDistribuicao::class);

        (new ProcessarDistribuicao($id))->handle(app(DistribuicaoService::class));

        $this->getJson("/api/v1/admin/avaliacao/distribuicoes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'concluida')
            ->assertJsonPath('data.finalizada', true)
            ->assertJsonPath('data.percentual', 100)
            ->assertJsonPath('data.relatorio.designadas_criadas', 3);

        $this->assertSame(3, Avaliacao::count());
    }

    /** A tela abre já mostrando a última rodada, se houver. */
    public function test_ultima_rodada_alimenta_a_tela_ao_abrir(): void
    {
        $this->edicaoPadrao();
        Sanctum::actingAs(User::factory()->admin()->create());

        Queue::fake();

        $this->getJson('/api/v1/admin/avaliacao/distribuicoes/ultima')
            ->assertOk()
            ->assertJsonPath('data', null);

        $id = $this->postJson('/api/v1/admin/avaliacao/distribuir')->json('data.id');

        $this->getJson('/api/v1/admin/avaliacao/distribuicoes/ultima')
            ->assertOk()
            ->assertJsonPath('data.id', $id);
    }

    /**
     * Duas rodadas ao mesmo tempo competiriam pelas mesmas vagas, e o relatório
     * de nenhuma delas faria sentido.
     */
    public function test_nao_deixa_duas_rodadas_ao_mesmo_tempo(): void
    {
        $this->edicaoPadrao();
        Sanctum::actingAs(User::factory()->admin()->create());
        // Sem o fake, a fila síncrona concluiria a primeira rodada na hora e a
        // segunda seria aceita — o que este teste justamente quer impedir.
        Queue::fake();

        $id = $this->postJson('/api/v1/admin/avaliacao/distribuir')->assertStatus(202)->json('data.id');

        $this->postJson('/api/v1/admin/avaliacao/redistribuir')
            ->assertStatus(422)
            ->assertJsonValidationErrors('distribuicao');

        // Terminada a primeira, a próxima é aceita.
        (new ProcessarDistribuicao($id))->handle(app(DistribuicaoService::class));
        $this->postJson('/api/v1/admin/avaliacao/distribuir')->assertStatus(202);
    }

    /** A barra precisa de um denominador: o job grava total e processados. */
    public function test_progresso_conta_os_projetos_da_rodada(): void
    {
        $this->edicaoPadrao();
        Queue::fake();
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id);
        $this->projetoSubmetido($a->id, null, 'Um');
        $this->projetoSubmetido($a->id, null, 'Dois');

        $rodada = app(DistribuicaoService::class)
            ->enfileirar(Distribuicao::TIPO_DISTRIBUIR, User::factory()->admin()->create());

        (new ProcessarDistribuicao($rodada->id))->handle(app(DistribuicaoService::class));

        $rodada->refresh();
        $this->assertSame(2, $rodada->total);
        $this->assertSame(2, $rodada->processados);
        $this->assertSame(100, $rodada->percentual());
    }

    /** Uma falha no meio não deixa a tela girando para sempre. */
    public function test_falha_marca_a_rodada_e_guarda_o_motivo(): void
    {
        $this->edicaoPadrao();
        // Sem o fake, a fila síncrona já concluiria a rodada aqui e o job
        // abaixo sairia cedo, sem nunca chamar o serviço que falha.
        Queue::fake();
        $rodada = app(DistribuicaoService::class)->enfileirar(Distribuicao::TIPO_DISTRIBUIR);

        $servico = \Mockery::mock(DistribuicaoService::class);
        $servico->shouldReceive('distribuir')->once()->andThrow(new \RuntimeException('banco fora do ar'));

        (new ProcessarDistribuicao($rodada->id))->handle($servico);

        $rodada->refresh();
        $this->assertSame(StatusDistribuicao::Falha->value, $rodada->status->value);
        $this->assertTrue($rodada->status->finalizada());
        $this->assertSame('banco fora do ar', $rodada->erro);
    }

    public function test_distribuir_e_so_para_admin(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->postJson('/api/v1/admin/avaliacao/distribuir')->assertStatus(403);
    }
}
