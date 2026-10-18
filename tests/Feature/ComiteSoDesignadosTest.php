<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorLiberacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\ChecagemEstande;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\PedidoAvaliacaoComite;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 234 — com a regra "avaliar apenas projetos designados" ligada, o
 * avaliador da comissão especial só inicia no estande o que é dele; o resto
 * passa por um pedido de permissão que a organização decide na aba Comitê.
 */
class ComiteSoDesignadosTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    private int $estande = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 10, 20, 9, 0));

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => '2026-10-20 07:00', 'evento_ate' => '2026-10-21 18:00',
            'agenda_presencial' => self::agendaPresencial(['A' => ['inicio' => '08:00', 'fim' => '12:00'], 'B' => ['inicio' => '13:30', 'fim' => '17:30']], '2026-10-20', '2026-10-21'),
            'presencial_comite_so_designados' => true,
        ]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);
    }

    private function avaliador(string $nome, bool $comissao = true): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'presencial' => true, 'comissao_especial' => $comissao]);
        AvaliadorLiberacaoPresencial::create(['edicao_id' => $this->edicao->id, 'user_id' => $user->id]);

        return $user;
    }

    private function finalista(string $titulo, string $turno = 'A'): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
            'edicao_id' => $this->edicao->id,
            'titulo' => $titulo,
        ]);
        $this->lista->projetos()->attach($projeto->id);
        TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno]);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno, 'numero' => ++$this->estande]);
        Credenciamento::create(['projeto_id' => $projeto->id, 'lista_final_id' => $this->lista->id, 'finalizado_em' => now()]);
        ChecagemEstande::create(['projeto_id' => $projeto->id, 'lista_final_id' => $this->lista->id, 'verificado_em' => now()]);

        return $projeto;
    }

    private function iniciar(Projeto $projeto): TestResponse
    {
        return $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$projeto->id}");
    }

    private function adminDoComite(): User
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_comite_nao_inicia_projeto_fora_das_designacoes(): void
    {
        $projeto = $this->finalista('Abelhas nativas');
        Sanctum::actingAs($this->avaliador('Ana Comissão'));

        $this->iniciar($projeto)->assertStatus(422)->assertJsonValidationErrors('permissao');
        $this->assertSame(0, AvaliacaoPresencial::count());
    }

    public function test_a_regra_nao_alcanca_quem_nao_e_do_comite_nem_vale_desligada(): void
    {
        $projeto = $this->finalista('Abelhas nativas');
        $outro = $this->finalista('Horta vertical');

        Sanctum::actingAs($this->avaliador('Davi Comum', comissao: false));
        $this->iniciar($projeto)->assertOk();

        $this->edicao->update(['presencial_comite_so_designados' => false]);
        Sanctum::actingAs($this->avaliador('Ana Comissão'));
        $this->iniciar($outro)->assertOk();
    }

    public function test_designacao_do_comite_ou_no_estande_libera_o_inicio(): void
    {
        $online = $this->finalista('Abelhas nativas');
        $noEstande = $this->finalista('Horta vertical');
        $ana = $this->avaliador('Ana Comissão');
        Avaliacao::create([
            'projeto_id' => $online->id, 'avaliador_id' => $ana->id, 'status' => StatusAvaliacao::Designada,
            'designacao_manual' => true, 'designada_pelo_comite' => true,
        ]);
        AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $noEstande->id, 'avaliador_id' => $ana->id,
            'status' => StatusAvaliacao::Designada, 'dia' => '2026-10-20', 'turno' => 'A', 'vaga' => 'comite', 'designacao_manual' => true,
        ]);
        Sanctum::actingAs($ana);

        $this->iniciar($online)->assertOk()->assertJsonPath('data.status', 'em_andamento');
        $this->iniciar($noEstande)->assertOk();
    }

    public function test_o_mapa_e_a_lista_dizem_o_que_precisa_de_permissao(): void
    {
        $livre = $this->finalista('Abelhas nativas');
        $pedido = $this->finalista('Horta vertical');
        $designado = $this->finalista('Solo vivo');
        $ana = $this->avaliador('Ana Comissão');
        Avaliacao::create([
            'projeto_id' => $designado->id, 'avaliador_id' => $ana->id, 'status' => StatusAvaliacao::Designada,
            'designacao_manual' => true, 'designada_pelo_comite' => true,
        ]);
        PedidoAvaliacaoComite::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $pedido->id, 'avaliador_id' => $ana->id, 'status' => 'pendente',
        ]);
        Sanctum::actingAs($ana);

        $painel = $this->getJson('/api/v1/avaliador/presencial/avaliacoes')->assertOk()->json('data');

        $this->assertTrue($painel['so_designados']);
        $this->assertTrue($painel['mapa']['so_designados']);
        $situacoes = collect($painel['mapa']['fora_da_planta'])->concat(
            collect($painel['mapa']['estandes'])->flatMap(fn ($turnos) => array_values($turnos)),
        )->pluck('situacao', 'titulo');
        $this->assertSame('pedir_permissao', $situacoes['Abelhas nativas']);
        $this->assertSame('permissao_pendente', $situacoes['Horta vertical']);
        $this->assertSame('disponivel', $situacoes['Solo vivo']);

        $permissoes = collect($painel['disponiveis'])->pluck('permissao', 'titulo');
        $this->assertSame('necessaria', $permissoes['Abelhas nativas']);
        $this->assertSame('pendente', $permissoes['Horta vertical']);
        $this->assertNull($permissoes['Solo vivo']);
        $this->assertSame(['Horta vertical'], array_column($painel['pedidos'], 'titulo'));
    }

    public function test_pede_permissao_e_a_organizacao_aprova(): void
    {
        $projeto = $this->finalista('Abelhas nativas');
        $ana = $this->avaliador('Ana Comissão');
        Sanctum::actingAs($ana);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$projeto->id}/permissao", ['justificativa' => 'Sou da área.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pendente');
        // Pedir de novo com um pendente é recusado.
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$projeto->id}/permissao")
            ->assertStatus(422)->assertJsonValidationErrors('permissao');
        $this->iniciar($projeto)->assertStatus(422)->assertJsonValidationErrors('permissao');

        $this->adminDoComite();
        $lista = $this->getJson('/api/v1/admin/comite/permissoes?situacao=pendente')
            ->assertOk()
            ->assertJsonPath('meta.pendentes', 1)
            ->assertJsonPath('meta.ativa', true)
            ->json('data');
        $this->assertSame('Ana Comissão', $lista[0]['avaliador']['nome']);
        $this->assertSame('Abelhas nativas', $lista[0]['projeto']['titulo']);
        $this->assertSame('Sou da área.', $lista[0]['justificativa']);

        $this->postJson("/api/v1/admin/comite/permissoes/{$lista[0]['id']}/decisao", ['decisao' => 'aprovar'])
            ->assertOk()
            ->assertJsonPath('data.status', 'aprovado');

        Sanctum::actingAs($ana);
        $this->iniciar($projeto)->assertOk();
    }

    public function test_recusado_pode_pedir_de_novo_e_ve_a_resposta(): void
    {
        $projeto = $this->finalista('Abelhas nativas');
        $ana = $this->avaliador('Ana Comissão');
        $pedido = PedidoAvaliacaoComite::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $ana->id, 'status' => 'pendente',
        ]);

        $this->adminDoComite();
        $this->postJson("/api/v1/admin/comite/permissoes/{$pedido->id}/decisao", ['decisao' => 'recusar', 'resposta' => 'Já tem avaliador do comitê.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'recusado')
            ->assertJsonPath('data.resposta', 'Já tem avaliador do comitê.');

        Sanctum::actingAs($ana);
        $painel = $this->getJson('/api/v1/avaliador/presencial/avaliacoes')->json('data');
        $this->assertSame('recusada', $painel['disponiveis'][0]['permissao']);
        $this->assertSame('Já tem avaliador do comitê.', $painel['disponiveis'][0]['permissao_resposta']);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$projeto->id}/permissao")->assertCreated();
        $this->assertSame('pendente', $pedido->fresh()->status->value);
        $this->assertNull($pedido->fresh()->resposta);
    }

    public function test_sem_a_regra_ou_fora_do_comite_nao_ha_o_que_pedir(): void
    {
        $projeto = $this->finalista('Abelhas nativas');
        Sanctum::actingAs($this->avaliador('Davi Comum', comissao: false));

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$projeto->id}/permissao")
            ->assertStatus(422)->assertJsonValidationErrors('permissao');
    }

    public function test_quem_nao_tem_a_aba_comite_nao_decide(): void
    {
        $projeto = $this->finalista('Abelhas nativas');
        $pedido = PedidoAvaliacaoComite::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id,
            'avaliador_id' => $this->avaliador('Ana Comissão')->id, 'status' => 'pendente',
        ]);
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/comite/permissoes')->assertForbidden();
        $this->postJson("/api/v1/admin/comite/permissoes/{$pedido->id}/decisao", ['decisao' => 'aprovar'])->assertForbidden();
    }

    public function test_parametrizacao_liga_e_desliga_a_regra_com_registro(): void
    {
        $this->edicao->update(['presencial_comite_so_designados' => false]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/parametrizacao/presencial')
            ->assertOk()
            ->assertJsonPath('data.comite_so_designados', false);

        $this->putJson('/api/v1/admin/parametrizacao/presencial', ['comite_so_designados' => true])
            ->assertOk()
            ->assertJsonPath('data.comite_so_designados', true);

        $this->assertTrue($this->edicao->fresh()->presencial_comite_so_designados);
        $this->assertDatabaseHas('registros_atividade', ['tipo' => 'presencial_comite_so_designados']);

        $this->putJson('/api/v1/admin/parametrizacao/presencial', [])->assertStatus(422);
    }
}
