<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\ChecagemEstande;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprints 169–170 e 221 — turnos da avaliação presencial e a distribuição por
 * turno (só projeto credenciado e checado), que alcança todos que confirmaram
 * o presencial (a cabine saiu na Sprint 221).
 */
class DistribuicaoPresencialTest extends TestCase
{
    use RefreshDatabase;

    private const DIA = '2026-10-20';

    private Edicao $edicao;

    private ListaFinal $lista;

    private User $admin;

    private Area $agr;

    private Area $bio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 10, 20, 9, 0));

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => '2026-10-20 07:00', 'evento_ate' => '2026-10-21 18:00',
            'horarios_turnos' => ['A' => ['inicio' => '08:00', 'fim' => '12:00'], 'B' => ['inicio' => '13:30', 'fim' => '17:30']],
            'presencial_fila_avaliador' => 2,
            // Duas vagas comuns e nenhuma da comissão: o teto de 2 dos testes de antes.
            'presencial_comuns_por_projeto' => 2,
            'presencial_comite_por_projeto' => 0,
        ]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->bio = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function avaliador(string $nome, ?Area $area = null, bool $presencial = true, bool $demo = false, bool $comissao = false): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome, 'is_demo' => $demo]);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'presencial' => $presencial, 'area_id' => ($area ?? $this->agr)->id, 'comissao_especial' => $comissao,
        ]);

        return $user;
    }

    /** Finalista do turno, já credenciado e checado (a menos que se diga o contrário). */
    private function finalista(string $titulo, ?Area $area = null, string $turno = 'A', bool $credenciado = true, bool $checado = true, ?int $estande = null): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'area_id' => ($area ?? $this->agr)->id,
        ]);
        $this->lista->projetos()->attach($projeto->id);
        TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno]);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno, 'numero' => $estande ?? 100 + $projeto->id]);

        if ($credenciado) {
            Credenciamento::create(['projeto_id' => $projeto->id, 'lista_final_id' => $this->lista->id, 'iniciado_em' => now(), 'finalizado_em' => now()]);
        }

        if ($checado) {
            ChecagemEstande::create(['projeto_id' => $projeto->id, 'lista_final_id' => $this->lista->id, 'verificado_em' => now(), 'demo' => false]);
        }

        return $projeto;
    }

    private function distribuir(string $turno = 'A')
    {
        return $this->postJson('/api/v1/admin/presencial/distribuicao/distribuir', ['dia' => self::DIA, 'turno' => $turno]);
    }

    // --- Turnos e agenda ---------------------------------------------------

    public function test_salva_os_horarios_e_monta_a_agenda_dos_dias_do_evento(): void
    {
        $this->patchJson('/api/v1/admin/presencial/distribuicao', [
            'horarios' => ['A' => ['inicio' => '07:30', 'fim' => '11:30'], 'B' => ['inicio' => '13:00', 'fim' => '17:00']],
            'fila_avaliador' => 4,
            'comuns_por_projeto' => 3,
            'comite_por_projeto' => 1,
            'peso_comite' => 2.5,
        ])->assertOk()
            ->assertJsonPath('data.horarios.A.inicio', '07:30')
            ->assertJsonPath('data.fila_avaliador', 4)
            ->assertJsonPath('data.comuns_por_projeto', 3)
            ->assertJsonPath('data.comite_por_projeto', 1)
            ->assertJsonPath('data.peso_comite', 2.5)
            ->assertJsonPath('data.por_projeto', 4)
            // Dois dias × dois turnos.
            ->assertJsonCount(4, 'data.ocorrencias')
            // 09:00 do primeiro dia: o turno A está acontecendo.
            ->assertJsonPath('data.foco.chave', '2026-10-20|A')
            ->assertJsonPath('data.foco.situacao', 'em_andamento')
            ->assertJsonPath('data.foco.prazo_label', '12:00');
    }

    public function test_turno_que_termina_antes_de_comecar_e_recusado(): void
    {
        $this->patchJson('/api/v1/admin/presencial/distribuicao', [
            'horarios' => ['A' => ['inicio' => '12:00', 'fim' => '08:00']],
        ])->assertStatus(422)->assertJsonValidationErrors('horarios.A.fim');
    }

    public function test_na_margem_de_30_minutos_o_turno_ainda_aceita_escrita(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 20, 12, 20));

        $this->getJson('/api/v1/admin/presencial/distribuicao')
            ->assertOk()
            ->assertJsonPath('data.foco.chave', '2026-10-20|A')
            ->assertJsonPath('data.foco.situacao', 'margem')
            ->assertJsonPath('data.foco.aberta', true);

        $this->travelTo(now()->setDateTime(2026, 10, 20, 12, 31));

        // Encerrado o A, o foco vai para o próximo turno.
        $this->getJson('/api/v1/admin/presencial/distribuicao')
            ->assertJsonPath('data.foco.chave', '2026-10-20|B')
            ->assertJsonPath('data.foco.situacao', 'futuro');
    }

    // --- Distribuição ------------------------------------------------------

    public function test_distribui_so_projetos_credenciados_e_checados_do_turno(): void
    {
        $ana = $this->avaliador('Ana');
        $pronto = $this->finalista('Abelhas');
        $this->finalista('Bioplástico', credenciado: false);
        $this->finalista('Compostagem', checado: false);
        $this->finalista('Drone da tarde', turno: 'B');

        $this->distribuir()
            ->assertOk()
            ->assertJsonPath('meta.resultado.designadas', 1)
            ->assertJsonPath('data.resumo.projetos', 3)
            ->assertJsonPath('data.resumo.prontos', 1)
            ->assertJsonPath('data.resumo.sem_credenciamento', 1)
            ->assertJsonPath('data.resumo.sem_checagem', 1);

        $avaliacao = AvaliacaoPresencial::sole();
        $this->assertSame($pronto->id, $avaliacao->projeto_id);
        $this->assertSame(self::DIA, $avaliacao->dia->toDateString());
        $this->assertSame(Turno::A, $avaliacao->turno);
        $this->assertSame(StatusAvaliacao::Designada, $avaliacao->status);
        $this->assertFalse($avaliacao->designacao_manual);
    }

    public function test_alcanca_todos_que_confirmaram_o_presencial_sem_cabine(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $this->avaliador('Carla', presencial: false);
        $this->finalista('Abelhas');
        $this->finalista('Bioplástico', estande: 2);

        $this->distribuir()->assertOk();

        $this->assertEqualsCanonicalizing(
            [$ana->id, $bruno->id],
            AvaliacaoPresencial::distinct()->pluck('avaliador_id')->all(),
        );
    }

    public function test_sem_avaliador_confirmado_a_distribuicao_e_recusada(): void
    {
        $this->avaliador('Ana', presencial: false);
        $this->finalista('Abelhas');

        $this->distribuir()->assertStatus(422)->assertJsonValidationErrors('avaliadores');
    }

    public function test_rodadas_iguais_teto_por_projeto_e_prioridade_de_area(): void
    {
        // Fila 2 por avaliador, 2 avaliações por projeto.
        $ana = $this->avaliador('Ana', $this->bio);
        $bruno = $this->avaliador('Bruno');
        $carla = $this->avaliador('Carla');
        $bio = $this->finalista('Células', $this->bio, estande: 3);
        $this->finalista('Abelhas', estande: 1);

        $this->distribuir()->assertOk();

        // Ana (biológicas) recebe primeiro o projeto da área dela.
        $this->assertTrue(AvaliacaoPresencial::where('avaliador_id', $ana->id)->where('projeto_id', $bio->id)->exists());
        // Dois projetos × teto 2 = 4 vagas para três avaliadores com fila 2: ninguém fica com 0.
        foreach ([$ana, $bruno, $carla] as $u) {
            $this->assertGreaterThanOrEqual(1, AvaliacaoPresencial::where('avaliador_id', $u->id)->count());
        }
        $this->assertSame(4, AvaliacaoPresencial::count());
        $this->assertSame(2, AvaliacaoPresencial::where('projeto_id', $bio->id)->count());
    }

    public function test_redistribuir_preserva_o_que_ja_foi_aberto_e_o_manual(): void
    {
        $ana = $this->avaliador('Ana');
        $a = $this->finalista('Abelhas');
        $b = $this->finalista('Bioplástico', estande: 2);
        $this->distribuir()->assertOk();

        AvaliacaoPresencial::where('projeto_id', $a->id)->update(['status' => StatusAvaliacao::EmAndamento->value]);

        $this->postJson('/api/v1/admin/presencial/distribuicao/redistribuir', ['dia' => self::DIA, 'turno' => 'A'])
            ->assertOk()
            ->assertJsonPath('meta.resultado.devolvidas', 1);

        $this->assertSame(StatusAvaliacao::EmAndamento, AvaliacaoPresencial::where('projeto_id', $a->id)->sole()->status);
        $this->assertTrue(AvaliacaoPresencial::where('projeto_id', $b->id)->where('avaliador_id', $ana->id)->exists());
    }

    public function test_designacao_vencida_libera_a_vaga_e_e_reaproveitada(): void
    {
        $ana = $this->avaliador('Ana');
        $projeto = $this->finalista('Abelhas');
        AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $ana->id,
            'dia' => '2026-10-20', 'turno' => 'A', 'status' => StatusAvaliacao::EmAndamento, 'respostas' => ['clareza' => 8],
        ]);

        // No dia seguinte o turno A de ontem já venceu.
        $this->travelTo(now()->setDateTime(2026, 10, 21, 9, 0));

        $this->postJson('/api/v1/admin/presencial/distribuicao/distribuir', ['dia' => '2026-10-21', 'turno' => 'A'])
            ->assertOk()
            ->assertJsonPath('meta.resultado.designadas', 1);

        // A mesma linha, agora no turno de hoje, com o rascunho de ontem.
        $avaliacao = AvaliacaoPresencial::sole();
        $this->assertSame('2026-10-21', $avaliacao->dia->toDateString());
        $this->assertSame(['clareza' => 8], $avaliacao->respostas);
    }

    public function test_turno_encerrado_nao_distribui(): void
    {
        $this->avaliador('Ana');
        $this->finalista('Abelhas');
        $this->travelTo(now()->setDateTime(2026, 10, 20, 12, 31));

        $this->distribuir()->assertStatus(422)->assertJsonValidationErrors('turno');
    }

    // --- Designação manual -------------------------------------------------

    public function test_designacao_manual_passa_por_cima_do_teto_mas_nao_do_credenciamento(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $carla = $this->avaliador('Carla');
        $semBalcao = $this->avaliador('Diego', presencial: false);
        $pronto = $this->finalista('Abelhas');
        $naoCredenciado = $this->finalista('Bioplástico', credenciado: false, estande: 2);
        $this->distribuir()->assertOk(); // Ana e Bruno (teto 2) ficam com Abelhas.

        $resposta = $this->postJson('/api/v1/admin/presencial/avaliacoes/designar', [
            'dia' => self::DIA, 'turno' => 'A',
            'projeto_ids' => [$pronto->id, $naoCredenciado->id],
            'avaliador_ids' => [$carla->id, $semBalcao->id],
        ])->assertOk();

        $this->assertSame(1, $resposta->json('meta.resultado.designadas'));
        $this->assertSame(3, AvaliacaoPresencial::where('projeto_id', $pronto->id)->count());
        $this->assertTrue(AvaliacaoPresencial::where('avaliador_id', $carla->id)->sole()->designacao_manual);
        $ignoradas = implode(' | ', $resposta->json('meta.resultado.ignoradas'));
        $this->assertStringContainsString('Bioplástico: ainda não foi credenciado e checado.', $ignoradas);
        $this->assertStringContainsString('Diego: não confirmou a avaliação presencial.', $ignoradas);
    }

    public function test_retira_designacao_aberta_mas_nao_a_enviada(): void
    {
        $ana = $this->avaliador('Ana');
        $this->finalista('Abelhas');
        $this->distribuir()->assertOk();
        $avaliacao = AvaliacaoPresencial::sole();

        $avaliacao->update(['status' => StatusAvaliacao::Concluida, 'nota' => 8]);
        $this->deleteJson("/api/v1/admin/presencial/avaliacoes/{$avaliacao->id}")->assertStatus(422);

        $avaliacao->update(['status' => StatusAvaliacao::Designada, 'nota' => null]);
        $this->deleteJson("/api/v1/admin/presencial/avaliacoes/{$avaliacao->id}")->assertOk();
        $this->assertSame(0, AvaliacaoPresencial::count());
    }

    // --- Vagas: comuns e da comissão especial (Sprint 222) ------------------

    public function test_vagas_zeradas_nos_dois_tipos_sao_recusadas(): void
    {
        $this->patchJson('/api/v1/admin/presencial/distribuicao', ['comuns_por_projeto' => 0, 'comite_por_projeto' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('comuns_por_projeto');

        // Zero num tipo só é resposta: o projeto fica só com a comissão.
        $this->patchJson('/api/v1/admin/presencial/distribuicao', ['comuns_por_projeto' => 0, 'comite_por_projeto' => 2])
            ->assertOk()->assertJsonPath('data.comuns_por_projeto', 0)->assertJsonPath('data.por_projeto', 2);

        // Em branco volta ao padrão (2 comuns + 1 da comissão, peso 2).
        $this->patchJson('/api/v1/admin/presencial/distribuicao', [])
            ->assertOk()
            ->assertJsonPath('data.comuns_por_projeto', 2)
            ->assertJsonPath('data.comite_por_projeto', 1)
            ->assertJsonPath('data.por_projeto', 3);
    }

    public function test_a_distribuicao_respeita_as_vagas_comuns_e_da_comissao(): void
    {
        $this->edicao->update(['presencial_comite_por_projeto' => 1]);
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $carla = $this->avaliador('Carla');
        $diana = $this->avaliador('Diana', comissao: true);
        $projeto = $this->finalista('Abelhas');

        $this->distribuir()
            ->assertOk()
            ->assertJsonPath('meta.resultado.designadas', 3)
            ->assertJsonPath('data.projetos.0.comuns', 2)
            ->assertJsonPath('data.projetos.0.comite', 1);

        // As duas comuns para os primeiros, nada para o terceiro comum, e a da
        // comissão para quem é dela.
        $this->assertSame('comum', AvaliacaoPresencial::where('avaliador_id', $ana->id)->sole()->vaga->value);
        $this->assertSame('comum', AvaliacaoPresencial::where('avaliador_id', $bruno->id)->sole()->vaga->value);
        $this->assertFalse(AvaliacaoPresencial::where('avaliador_id', $carla->id)->exists());
        $this->assertSame('comite', AvaliacaoPresencial::where('avaliador_id', $diana->id)->where('projeto_id', $projeto->id)->sole()->vaga->value);
    }

    public function test_designacao_manual_passa_do_teto_com_a_vaga_do_tipo_do_avaliador(): void
    {
        $this->edicao->update(['presencial_comuns_por_projeto' => 1, 'presencial_comite_por_projeto' => 1]);
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $diana = $this->avaliador('Diana', comissao: true);
        $edson = $this->avaliador('Edson', comissao: true);
        $projeto = $this->finalista('Abelhas');

        $this->postJson('/api/v1/admin/presencial/avaliacoes/designar', [
            'dia' => self::DIA, 'turno' => 'A',
            'projeto_ids' => [$projeto->id],
            'avaliador_ids' => [$ana->id, $bruno->id, $diana->id, $edson->id],
        ])->assertOk()
            ->assertJsonPath('meta.resultado.designadas', 4)
            ->assertJsonPath('data.designacoes.0.vaga', 'comum');

        $vagas = AvaliacaoPresencial::pluck('vaga', 'avaliador_id')->map->value->all();
        $this->assertSame(['comum', 'comum', 'comite', 'comite'], [$vagas[$ana->id], $vagas[$bruno->id], $vagas[$diana->id], $vagas[$edson->id]]);
    }

    public function test_modo_de_teste_usa_a_lista_e_os_avaliadores_demo(): void
    {
        $demoAdmin = User::factory()->admin()->create(['is_demo' => true]);
        Sanctum::actingAs($demoAdmin);
        $listaDemo = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Demo', 'tipo' => 'final', 'vigente' => true, 'demo' => true, 'versao' => 1,
        ]);
        $projetoDemo = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id]);
        $listaDemo->projetos()->attach($projetoDemo->id);
        TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projetoDemo->id, 'turno' => 'A']);
        Credenciamento::create(['projeto_id' => $projetoDemo->id, 'lista_final_id' => $listaDemo->id, 'finalizado_em' => now()]);
        ChecagemEstande::create(['projeto_id' => $projetoDemo->id, 'lista_final_id' => $listaDemo->id, 'verificado_em' => now(), 'demo' => true]);
        $this->finalista('Projeto de verdade');
        $demo = $this->avaliador('Avaliador Demo', demo: true);
        $this->avaliador('Avaliador de verdade');

        $this->postJson('/api/v1/admin/presencial/distribuicao/distribuir', ['dia' => self::DIA, 'turno' => 'A', 'teste' => 1])
            ->assertOk()
            ->assertJsonPath('data.modo_teste', true)
            ->assertJsonPath('meta.resultado.designadas', 1);

        $avaliacao = AvaliacaoPresencial::sole();
        $this->assertSame($projetoDemo->id, $avaliacao->projeto_id);
        $this->assertSame($demo->id, $avaliacao->avaliador_id);
    }
}
