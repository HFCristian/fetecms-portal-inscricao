<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comitê especial → Acompanhamento das avaliações: cada avaliador da comissão
 * com o que está avaliando, e o mapa do evento só com as avaliações do comitê.
 */
class ComiteAcompanhamentoTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/comite/acompanhamento';

    private Edicao $edicao;

    private ListaFinal $lista;

    private Area $agr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);
    }

    private function finalista(string $titulo, int $estande, string $turno = 'A', ?string $codigo = null): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id,
        ]);
        $this->lista->projetos()->attach($projeto->id, ['codigo' => $codigo]);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno, 'numero' => $estande]);

        return $projeto;
    }

    private function avaliador(string $nome, bool $comissao = true, bool $demo = false): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome, 'is_demo' => $demo]);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'presencial' => true, 'comissao_especial' => $comissao, 'area_id' => $this->agr->id,
        ]);

        return $user;
    }

    private function avaliacao(User $avaliador, Projeto $projeto, string $status, array $extra = []): AvaliacaoPresencial
    {
        return AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::from($status), 'vaga' => 'comite',
            'iniciada_em' => $status === 'designada' ? null : now()->subMinutes(20),
            'concluida_em' => $status === 'concluida' ? now()->subMinutes(5) : null,
            'nota' => $status === 'concluida' ? 8.5 : null,
            ...$extra,
        ]);
    }

    public function test_lista_cada_avaliador_da_comissao_com_o_que_esta_avaliando(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42, codigo: 'FET.AGR-001');
        $horta = $this->finalista('Horta vertical', 43);
        $ana = $this->avaliador('Ana Comissão');
        $bruno = $this->avaliador('Bruno Comissão');
        $this->avaliador('Carla Livre');
        $comum = $this->avaliador('Davi Comum', comissao: false);

        $this->avaliacao($ana, $abelhas, 'em_andamento');
        $this->avaliacao($ana, $horta, 'concluida');
        $this->avaliacao($bruno, $horta, 'designada');
        $this->avaliacao($comum, $abelhas, 'em_andamento');

        $avaliadores = collect($this->getJson(self::URL)->assertOk()->json('data.avaliadores'))->keyBy('nome');

        // Só a comissão — inclusive quem ainda não fez nada.
        $this->assertSame(['Ana Comissão', 'Bruno Comissão', 'Carla Livre'], $avaliadores->keys()->all());

        $this->assertSame('avaliando', $avaliadores['Ana Comissão']['situacao']);
        $this->assertSame('Abelhas nativas', $avaliadores['Ana Comissão']['em_avaliacao'][0]['titulo']);
        $this->assertSame('FET.AGR-001', $avaliadores['Ana Comissão']['em_avaliacao'][0]['codigo']);
        $this->assertSame(42, $avaliadores['Ana Comissão']['em_avaliacao'][0]['estande']);
        $this->assertSame('A', $avaliadores['Ana Comissão']['em_avaliacao'][0]['turno']);
        $this->assertCount(1, $avaliadores['Ana Comissão']['concluidas']);

        $this->assertSame('designado', $avaliadores['Bruno Comissão']['situacao']);
        $this->assertSame('livre', $avaliadores['Carla Livre']['situacao']);
    }

    public function test_o_mapa_so_enxerga_as_avaliacoes_do_comite(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $horta = $this->finalista('Horta vertical', 43);
        $solo = $this->finalista('Solo vivo', 44);
        $this->finalista('Da tarde', 42, 'B');
        $ana = $this->avaliador('Ana Comissão');
        $bruno = $this->avaliador('Bruno Comissão');
        $comum = $this->avaliador('Davi Comum', comissao: false);

        $this->avaliacao($ana, $abelhas, 'concluida');
        $this->avaliacao($bruno, $abelhas, 'em_andamento');
        $this->avaliacao($ana, $horta, 'concluida');
        // A avaliação de quem não é do comitê não pinta nada.
        $this->avaliacao($comum, $solo, 'concluida');

        $mapa = $this->getJson(self::URL.'?turno=A')->assertOk()->json('data.mapa');
        $estandes = $mapa['estandes'];

        $this->assertSame('em_avaliacao', $estandes['42']['situacao']);
        $this->assertSame(1, $estandes['42']['concluidas']);
        $this->assertSame(1, $estandes['42']['em_avaliacao']);
        $this->assertEqualsCanonicalizing(['Ana Comissão', 'Bruno Comissão'], array_column($estandes['42']['avaliacoes'], 'avaliador'));
        $this->assertSame('avaliado', $estandes['43']['situacao']);
        $this->assertSame('pendente', $estandes['44']['situacao']);
        $this->assertSame(0, $estandes['44']['concluidas']);
        $this->assertNotEmpty($mapa['layout']);

        // O turno B mostra o outro projeto do mesmo estande.
        $this->getJson(self::URL.'?turno=B')
            ->assertJsonPath('data.turno', 'B')
            ->assertJsonPath('data.mapa.estandes.42.titulo', 'Da tarde')
            ->assertJsonPath('data.mapa.estandes.42.situacao', 'pendente');
    }

    public function test_avaliacao_aberta_com_o_prazo_vencido_nao_conta_como_em_curso(): void
    {
        $ontem = now()->subDay()->toDateString();
        $this->edicao->update([
            'evento_de' => $ontem,
            'evento_ate' => now()->toDateString(),
            'agenda_presencial' => [$ontem => ['A' => ['inicio' => '08:00', 'fim' => '12:00', 'avaliacao_inicio' => '', 'avaliacao_fim' => '']]],
        ]);
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $ana = $this->avaliador('Ana Comissão');
        $this->avaliacao($ana, $abelhas, 'em_andamento', ['dia' => $ontem, 'turno' => 'A']);

        $dados = $this->getJson(self::URL.'?turno=A')->assertOk()->json('data');

        $this->assertSame('livre', $dados['avaliadores'][0]['situacao']);
        $this->assertSame(1, $dados['avaliadores'][0]['expiradas']);
        $this->assertSame('pendente', $dados['mapa']['estandes']['42']['situacao']);
    }

    public function test_conta_de_ensaio_fica_de_fora(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $demo = $this->avaliador('Ensaio', demo: true);
        $this->avaliacao($demo, $abelhas, 'em_andamento');

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data.avaliadores')
            ->assertJsonPath('data.mapa.estandes.42.situacao', 'pendente');
    }

    /** Uma avaliação online, como a designação do comitê (ou a fila comum) a cria. */
    private function online(User $avaliador, Projeto $projeto, string $status, bool $doComite = true): Avaliacao
    {
        return Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::from($status), 'designacao_manual' => $doComite,
            'designada_pelo_comite' => $doComite,
            'iniciada_em' => $status === 'designada' ? null : now()->subHour(),
            'concluida_em' => $status === 'concluida' ? now()->subMinutes(10) : null,
            'nota' => $status === 'concluida' ? 7.5 : null,
        ]);
    }

    public function test_mostra_as_designacoes_online_feitas_pelo_comite(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42, codigo: 'FET.AGR-001');
        $horta = $this->finalista('Horta vertical', 43);
        $solo = $this->finalista('Solo vivo', 44);
        $ana = $this->avaliador('Ana Comissão');
        $bruno = $this->avaliador('Bruno Comissão');

        $this->online($ana, $abelhas, 'designada');
        $this->online($ana, $horta, 'concluida');
        // A fila online comum do avaliador não é trabalho do comitê.
        $this->online($bruno, $solo, 'designada', doComite: false);

        $avaliadores = collect($this->getJson(self::URL)->assertOk()->json('data.avaliadores'))->keyBy('nome');

        $ana = $avaliadores['Ana Comissão'];
        $this->assertSame('designado', $ana['situacao']);
        $this->assertSame(['Abelhas nativas', 'Horta vertical'], array_column($ana['designacoes_comite'], 'titulo'));
        $this->assertSame(['designada', 'concluida'], array_column($ana['designacoes_comite'], 'situacao'));
        $this->assertSame('online', $ana['designacoes_comite'][0]['origem']);
        $this->assertSame('FET.AGR-001', $ana['designacoes_comite'][0]['codigo']);
        $this->assertSame(42, $ana['designacoes_comite'][0]['estande']);
        $this->assertNotNull($ana['ultima_concluida_em']);
        // A nota não sai nesta tela.
        $this->assertArrayNotHasKey('nota', $ana['designacoes_comite'][1]);

        $this->assertSame('livre', $avaliadores['Bruno Comissão']['situacao']);
        $this->assertSame([], $avaliadores['Bruno Comissão']['designacoes_comite']);
    }

    public function test_a_designacao_online_do_comite_pinta_o_mapa(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $horta = $this->finalista('Horta vertical', 43);
        $solo = $this->finalista('Solo vivo', 44);
        $ana = $this->avaliador('Ana Comissão');

        $this->online($ana, $abelhas, 'em_andamento');
        $this->online($ana, $horta, 'concluida');
        $this->online($ana, $solo, 'designada', doComite: false);

        $estandes = $this->getJson(self::URL.'?turno=A')->assertOk()->json('data.mapa.estandes');

        // Aberta online é "a fazer", não "avaliando agora" — isso é do estande.
        $this->assertSame('designado', $estandes['42']['situacao']);
        $this->assertSame(1, $estandes['42']['designadas_online']);
        $this->assertSame('iniciada', $estandes['42']['avaliacoes'][0]['situacao']);
        $this->assertSame('online', $estandes['42']['avaliacoes'][0]['origem']);

        $this->assertSame('avaliado', $estandes['43']['situacao']);
        $this->assertSame(1, $estandes['43']['concluidas']);
        $this->assertSame(1, $estandes['43']['concluidas_online']);

        $this->assertSame('pendente', $estandes['44']['situacao']);
    }

    public function test_so_a_tela_do_comite_marca_a_designacao_como_dele(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $horta = $this->finalista('Horta vertical', 43);
        $ana = $this->avaliador('Ana Comissão');

        $this->postJson('/api/v1/admin/comite/designacoes', [
            'projeto_ids' => [$abelhas->id], 'avaliador_ids' => [$ana->id],
        ])->assertOk()->assertJsonPath('data.designadas', 1);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/avaliacao/designacoes/designar', [
            'projeto_ids' => [$horta->id], 'avaliador_ids' => [$ana->id],
        ])->assertOk()->assertJsonPath('data.designadas', 1);

        $this->assertTrue(Avaliacao::where('projeto_id', $abelhas->id)->sole()->designada_pelo_comite);
        $this->assertFalse(Avaliacao::where('projeto_id', $horta->id)->sole()->designada_pelo_comite);
    }

    public function test_o_comite_assume_a_designacao_que_o_avaliador_ja_tinha(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $ana = $this->avaliador('Ana Comissão');
        $existente = $this->online($ana, $abelhas, 'designada', doComite: false);

        $this->postJson('/api/v1/admin/comite/designacoes', [
            'projeto_ids' => [$abelhas->id], 'avaliador_ids' => [$ana->id],
        ])->assertOk();

        $this->assertTrue($existente->fresh()->designada_pelo_comite);
    }

    public function test_a_migration_marca_o_passado_por_aproximacao(): void
    {
        $this->lista->update(['gerada_em' => now()->subDay()]);
        $finalista = $this->finalista('Abelhas nativas', 42);
        $deFora = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id]);
        $ana = $this->avaliador('Ana Comissão');
        $comum = $this->avaliador('Davi Comum', comissao: false);

        $migration = require database_path('migrations/2026_10_18_140000_designada_pelo_comite_nas_avaliacoes.php');
        $migration->down();

        $criar = fn (User $u, Projeto $p, bool $manual, $quando) => tap(Avaliacao::create([
            'projeto_id' => $p->id, 'avaliador_id' => $u->id, 'status' => StatusAvaliacao::Designada, 'designacao_manual' => $manual,
        ]))->forceFill(['created_at' => $quando])->saveQuietly();

        $criar($ana, $finalista, true, now());                 // o comitê, depois da lista: marca
        $outro = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id]);
        $this->lista->projetos()->attach($outro->id);
        $criar($ana, $outro, true, now()->subDays(3));         // antes da lista: não
        $criar($ana, $deFora, true, now());                    // fora da lista: não
        $criar($comum, $finalista, true, now());               // fora da comissão: não

        $migration->up();

        $this->assertSame(
            [$finalista->id],
            Avaliacao::where('designada_pelo_comite', true)->pluck('projeto_id')->all(),
        );
    }

    public function test_designacao_de_projeto_ja_aberto_ou_avaliado_nao_conta_como_a_fazer(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $horta = $this->finalista('Horta vertical', 43);
        $ana = $this->avaliador('Ana Comissão');

        // Designado online e já avaliado no estande; designado no estande e já iniciado online.
        $this->online($ana, $abelhas, 'designada');
        $this->avaliacao($ana, $abelhas, 'concluida');
        $this->avaliacao($ana, $horta, 'designada');
        $this->online($ana, $horta, 'concluida');

        $ana = $this->getJson(self::URL)->assertOk()->json('data.avaliadores.0');

        $this->assertSame('livre', $ana['situacao']);
        $this->assertSame([], $ana['designadas']);
    }

    private function urlRetirar(User $avaliador): string
    {
        return self::URL.'/avaliadores/'.$avaliador->id.'/retirar';
    }

    public function test_retira_do_avaliador_as_designacoes_ainda_nao_abertas(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $horta = $this->finalista('Horta vertical', 43);
        $ana = $this->avaliador('Ana Comissão');
        $online = $this->online($ana, $abelhas, 'designada');
        $estande = $this->avaliacao($ana, $horta, 'designada');

        $this->postJson($this->urlRetirar($ana), ['online_ids' => [$online->id], 'estande_ids' => [$estande->id]])
            ->assertOk()
            ->assertJsonPath('data.retiradas', 2)
            ->assertJsonPath('data.online', 1)
            ->assertJsonPath('data.estande', 1)
            ->assertJsonPath('meta.message', '2 designação(ões) retirada(s) de Ana Comissão.');

        $this->assertNull(Avaliacao::find($online->id));
        $this->assertNull(AvaliacaoPresencial::find($estande->id));
        // Sem reposição: o comitê escolheu quem avalia, ninguém é sorteado no lugar.
        $this->assertSame(0, Avaliacao::count());
        // A retirada online entra na trilha, como na tela de Designações.
        $this->assertDatabaseHas('registros_atividade', [
            'tipo' => 'avaliacao_designacao_retirada', 'projeto_id' => $abelhas->id,
        ]);
    }

    public function test_aberta_enviada_ou_de_fora_do_comite_nao_sai(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $horta = $this->finalista('Horta vertical', 43);
        $solo = $this->finalista('Solo vivo', 44);
        $ana = $this->avaliador('Ana Comissão');
        $bruno = $this->avaliador('Bruno Comissão');

        $aberta = $this->online($ana, $abelhas, 'em_andamento');
        $daFila = $this->online($ana, $horta, 'designada', doComite: false);
        $enviada = $this->avaliacao($ana, $solo, 'concluida');
        $emCurso = $this->avaliacao($ana, $abelhas, 'em_andamento');
        $deOutro = $this->avaliacao($bruno, $horta, 'designada');

        $this->postJson($this->urlRetirar($ana), [
            'online_ids' => [$aberta->id, $daFila->id],
            'estande_ids' => [$enviada->id, $emCurso->id, $deOutro->id],
        ])->assertStatus(422)->assertJsonValidationErrors('designacoes');

        $this->assertSame(2, Avaliacao::count());
        $this->assertSame(3, AvaliacaoPresencial::count());
    }

    public function test_o_que_nao_pode_sair_e_contado_sem_derrubar_o_resto(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $horta = $this->finalista('Horta vertical', 43);
        $ana = $this->avaliador('Ana Comissão');
        $designada = $this->avaliacao($ana, $abelhas, 'designada');
        $emCurso = $this->avaliacao($ana, $horta, 'em_andamento');

        $this->postJson($this->urlRetirar($ana), ['estande_ids' => [$designada->id, $emCurso->id]])
            ->assertOk()
            ->assertJsonPath('data.retiradas', 1)
            ->assertJsonPath('data.ignoradas', 1);

        $this->assertNotNull(AvaliacaoPresencial::find($emCurso->id));
    }

    public function test_retirar_pede_avaliador_da_comissao_e_alguma_designacao(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $comum = $this->avaliador('Davi Comum', comissao: false);
        $designada = $this->avaliacao($comum, $abelhas, 'designada');

        $this->postJson($this->urlRetirar($comum), ['estande_ids' => [$designada->id]])
            ->assertStatus(422)->assertJsonValidationErrors('avaliador');
        $this->postJson($this->urlRetirar($this->avaliador('Ana Comissão')), [])
            ->assertStatus(422)->assertJsonValidationErrors(['online_ids', 'estande_ids']);
    }

    public function test_quem_nao_tem_a_aba_comite_nao_retira(): void
    {
        $ana = $this->avaliador('Ana Comissão');
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->postJson($this->urlRetirar($ana), ['estande_ids' => [1]])->assertForbidden();
    }

    public function test_turno_invalido_e_recusado(): void
    {
        $this->getJson(self::URL.'?turno=C')->assertStatus(422)->assertJsonValidationErrors('turno');
    }

    public function test_quem_nao_tem_a_aba_comite_nao_entra(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson(self::URL)->assertForbidden();
    }
}
