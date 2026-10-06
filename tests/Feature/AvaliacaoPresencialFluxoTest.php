<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoDocumento;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\AvaliadorTurnoPresencial;
use App\Models\ChecagemEstande;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ItemChecagemEstande;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\RubricaPresencial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Avaliação presencial — lado do avaliador (Sprints 135 e 171): ele avalia no
 * estande durante um turno em que foi ativado na cabine, com a rubrica
 * presencial, o checklist dos itens da checagem e nota separada da online.
 */
class AvaliacaoPresencialFluxoTest extends TestCase
{
    use RefreshDatabase;

    private const DIA = '2026-10-20';

    private Edicao $edicao;

    private ListaFinal $lista;

    private User $avaliador;

    private Projeto $projeto;

    private ItemChecagemEstande $banner;

    private int $estande = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 10, 20, 9, 0));

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => '2026-10-20 07:00', 'evento_ate' => '2026-10-21 18:00',
            'horarios_turnos' => ['A' => ['inicio' => '08:00', 'fim' => '12:00'], 'B' => ['inicio' => '13:30', 'fim' => '17:30']],
            'presencial_fila_avaliador' => 3,
        ]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);
        $this->banner = ItemChecagemEstande::create(['nome' => 'Banner montado', 'ordem' => 1, 'ativo' => true]);

        $this->avaliador = $this->avaliadorPresencial('Ana Avaliadora');
        $this->projeto = $this->finalista('Bioplástico de mandioca');
    }

    private function avaliadorPresencial(string $nome, bool $aceitou = true, bool $ativado = true): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'presencial' => $aceitou]);

        if ($ativado && $aceitou) {
            $this->ativar($user);
        }

        return $user;
    }

    private function ativar(User $user, string $dia = self::DIA, string $turno = 'A'): void
    {
        AvaliadorTurnoPresencial::create(['edicao_id' => $this->edicao->id, 'user_id' => $user->id, 'dia' => $dia, 'turno' => $turno]);
    }

    private function finalista(string $titulo, string $turno = 'A', bool $pronto = true): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
            'edicao_id' => $this->edicao->id,
            'titulo' => $titulo,
        ]);
        $this->lista->projetos()->attach($projeto->id);
        TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno]);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno, 'numero' => ++$this->estande]);

        if ($pronto) {
            Credenciamento::create(['projeto_id' => $projeto->id, 'lista_final_id' => $this->lista->id, 'finalizado_em' => now()]);
            ChecagemEstande::create(['projeto_id' => $projeto->id, 'lista_final_id' => $this->lista->id, 'verificado_em' => now()]);
        }

        return $projeto;
    }

    /** @return array<string, int> */
    private function respostasCheias(int $ponto = 10): array
    {
        return array_fill_keys(RubricaPresencial::chaves(), $ponto);
    }

    /** @return array<string, mixed> */
    private function envio(int $ponto = 10): array
    {
        return ['respostas' => $this->respostasCheias($ponto), 'itens' => [$this->banner->id => 'presente']];
    }

    private function abrir(Projeto $projeto): int
    {
        return $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$projeto->id}")->assertOk()->json('data.id');
    }

    // --- A aba ---------------------------------------------------------------

    public function test_quem_nao_aceitou_avaliar_presencialmente_nao_ve_estande(): void
    {
        Sanctum::actingAs($this->avaliadorPresencial('Bruno', aceitou: false));

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.aceitou', false)
            ->assertJsonPath('data.motivo_fechado', 'Marque que quer avaliar presencialmente (aba Presencial) para receber os projetos do dia.')
            ->assertJsonCount(0, 'data.disponiveis');
    }

    public function test_sem_ativacao_no_turno_a_aba_manda_procurar_a_cabine(): void
    {
        Sanctum::actingAs($this->avaliadorPresencial('Bruno', ativado: false));

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.motivo_fechado', 'O Turno A (matutino) está acontecendo: passe na cabine da avaliação para a organização ativar a sua participação neste turno.');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertStatus(422)->assertJsonValidationErrors('periodo');
    }

    public function test_fora_dos_turnos_a_aba_fica_fechada_com_o_proximo_turno(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 20, 12, 45));
        Sanctum::actingAs($this->avaliador);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.motivo_fechado', 'A avaliação presencial abre durante os turnos do evento. Próximo turno: 20/10 · Turno B (vespertino) · 13:30–17:30.');
    }

    public function test_sem_horario_dos_turnos_a_aba_explica(): void
    {
        $this->edicao->update(['horarios_turnos' => null]);
        Sanctum::actingAs($this->avaliador);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.motivo_fechado', 'A organização ainda não definiu os dias e os horários dos turnos da avaliação presencial.');
    }

    // --- Escolha no corredor -------------------------------------------------

    public function test_avaliador_escolhe_um_estande_do_turno_credenciado_e_checado(): void
    {
        $this->finalista('Projeto da tarde', turno: 'B');
        $this->finalista('Ainda montando', pronto: false);
        Sanctum::actingAs($this->avaliador);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonPath('data.aberto', true)
            ->assertJsonPath('data.turno.chave', '2026-10-20|A')
            ->assertJsonCount(1, 'data.disponiveis')
            ->assertJsonPath('data.disponiveis.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('data.itens.0.nome', 'Banner montado');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'em_andamento')
            ->assertJsonPath('data.prazo_label', '20/10 12:30')
            ->assertJsonPath('data.pode_escrever', true);

        $avaliacao = AvaliacaoPresencial::sole();
        $this->assertSame(self::DIA, $avaliacao->dia->toDateString());
        $this->assertFalse($avaliacao->designacao_manual);
    }

    public function test_projeto_nao_checado_ou_de_outro_turno_nao_e_escolhido(): void
    {
        $montando = $this->finalista('Ainda montando', pronto: false);
        $tarde = $this->finalista('Projeto da tarde', turno: 'B');
        Sanctum::actingAs($this->avaliador);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$montando->id}")
            ->assertStatus(422)->assertJsonPath('errors.projeto.0', 'Este projeto ainda não foi credenciado e checado.');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$tarde->id}")
            ->assertStatus(422)->assertJsonPath('errors.projeto.0', 'Este projeto não apresenta no turno em andamento.');
    }

    public function test_na_margem_nao_comeca_nada_novo_mas_termina_o_que_abriu(): void
    {
        $outro = $this->finalista('Compostagem');
        Sanctum::actingAs($this->avaliador);
        $id = $this->abrir($this->projeto);

        $this->travelTo(now()->setDateTime(2026, 10, 20, 12, 15));

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$outro->id}")
            ->assertStatus(422)->assertJsonValidationErrors('periodo');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", $this->envio())->assertOk();
    }

    public function test_passado_o_prazo_a_avaliacao_vira_leitura(): void
    {
        Sanctum::actingAs($this->avaliador);
        $id = $this->abrir($this->projeto);

        // O turno A acabou e a margem também. Ativado à tarde, ele ainda não
        // escreve no que era da manhã.
        $this->ativar($this->avaliador, turno: 'B');
        $this->travelTo(now()->setDateTime(2026, 10, 20, 14, 0));

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertJsonPath('data.minhas.0.status_label', 'Prazo encerrado')
            ->assertJsonPath('data.minhas.0.pode_escrever', false);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/rascunho", ['respostas' => ['clareza' => 8]])
            ->assertStatus(422)
            ->assertJsonPath('errors.periodo.0', 'O prazo desta avaliação acabou com o turno (e os 30 minutos de margem).');
    }

    // --- A avaliação ---------------------------------------------------------

    public function test_a_nota_e_calculada_no_servidor_e_o_checklist_fica_guardado(): void
    {
        Sanctum::actingAs($this->avaliador);
        $id = $this->abrir($this->projeto);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", [
            ...$this->envio(10),
            'comentario' => 'Equipe dominava o tema.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'concluida')
            ->assertJsonPath('data.nota', 10)
            ->assertJsonPath('data.itens.'.$this->banner->id, 'presente');

        // Metade da escala rende metade da nota; o checklist não muda nada.
        $outro = $this->finalista('Compostagem');
        $id2 = $this->abrir($outro);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id2}/concluir", [
            'respostas' => $this->respostasCheias(6), 'itens' => [$this->banner->id => 'ausente'],
        ])->assertOk()->assertJsonPath('data.nota', 6);
    }

    public function test_envio_sem_todas_as_perguntas_ou_sem_o_checklist_e_recusado(): void
    {
        Sanctum::actingAs($this->avaliador);
        $id = $this->abrir($this->projeto);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", [
            'respostas' => ['clareza' => 10], 'itens' => [$this->banner->id => 'presente'],
        ])->assertStatus(422)->assertJsonValidationErrors('respostas');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", [
            'respostas' => $this->respostasCheias(),
        ])->assertStatus(422)->assertJsonValidationErrors('itens');
    }

    public function test_rascunho_guarda_respostas_e_checklist(): void
    {
        Sanctum::actingAs($this->avaliador);
        $id = $this->abrir($this->projeto);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/rascunho", [
            'respostas' => ['clareza' => 8, 'inexistente' => 10],
            'itens' => [$this->banner->id => 'presente', 9999 => 'presente'],
            'comentario' => 'voltar depois',
        ])->assertOk()->assertJsonPath('data.respostas.clareza', 8);

        // O que está fora da rubrica ou do catálogo é descartado.
        $avaliacao = AvaliacaoPresencial::find($id);
        $this->assertArrayNotHasKey('inexistente', $avaliacao->respostas);
        $this->assertSame([$this->banner->id => 'presente'], $avaliacao->itens_conferidos);
        $this->assertNull($avaliacao->nota);
    }

    public function test_a_leitura_traz_o_projeto_sem_o_termo_do_finalista(): void
    {
        $this->projeto->documentos()->create(['tipo' => TipoDocumento::PlanoPesquisa, 'disk' => 'local', 'path' => 'x/plano.pdf', 'nome_original' => 'plano.pdf']);
        $this->projeto->documentos()->create(['tipo' => TipoDocumento::TermoResponsabilidade, 'disk' => 'local', 'path' => 'x/termo.pdf', 'nome_original' => 'termo.pdf']);
        Sanctum::actingAs($this->avaliador);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.projeto.documentos')
            ->assertJsonPath('data.projeto.documentos.0.nome_original', 'plano.pdf')
            ->assertJsonPath('data.projeto.local.estande', 1);

        // E o documento abre para ele: a Policy reconhece o avaliador presencial.
        $this->assertTrue($this->avaliador->can('view', $this->projeto->fresh()));
    }

    public function test_avaliacao_enviada_nao_e_reenviada(): void
    {
        Sanctum::actingAs($this->avaliador);
        $id = $this->abrir($this->projeto);
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", $this->envio())->assertOk();

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", $this->envio(2))->assertStatus(422);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertStatus(422)->assertJsonValidationErrors('projeto');
    }

    public function test_projeto_com_o_maximo_de_avaliacoes_sai_da_lista(): void
    {
        foreach (['Bruno', 'Carla', 'Diego'] as $nome) {
            Sanctum::actingAs($this->avaliadorPresencial($nome));
            $this->abrir($this->projeto);
        }

        Sanctum::actingAs($this->avaliador);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')->assertOk()->assertJsonCount(0, 'data.disponiveis');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertStatus(422)->assertJsonValidationErrors('projeto');
    }

    public function test_designada_pela_distribuicao_e_aberta_e_enviar_repoe_a_fila(): void
    {
        $segundo = $this->finalista('Compostagem');
        AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $this->projeto->id, 'avaliador_id' => $this->avaliador->id,
            'dia' => self::DIA, 'turno' => 'A', 'status' => StatusAvaliacao::Designada,
        ]);
        Sanctum::actingAs($this->avaliador);

        $id = $this->abrir($this->projeto);
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", $this->envio())->assertOk();

        // Enviou uma, recebeu outra do turno.
        $this->assertDatabaseHas('avaliacoes_presenciais', [
            'projeto_id' => $segundo->id, 'avaliador_id' => $this->avaliador->id, 'status' => StatusAvaliacao::Designada->value,
        ]);
    }

    public function test_nao_mexe_na_avaliacao_online(): void
    {
        Sanctum::actingAs($this->avaliador);
        $id = $this->abrir($this->projeto);
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", $this->envio())->assertOk();

        // A nota presencial vive na tabela dela: o ranking online não vê nada.
        $this->assertSame(0, Avaliacao::count());
        $this->assertSame(1, AvaliacaoPresencial::count());
    }

    public function test_avaliacao_de_outro_avaliador_e_barrada(): void
    {
        Sanctum::actingAs($this->avaliadorPresencial('Bruno'));
        $id = $this->abrir($this->projeto);

        Sanctum::actingAs($this->avaliador);

        $this->getJson("/api/v1/avaliador/presencial/avaliacoes/{$id}")->assertForbidden();
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/rascunho", ['respostas' => []])->assertForbidden();
    }

    // --- Modo de teste -------------------------------------------------------

    public function test_conta_demo_em_modo_de_teste_avalia_fora_do_turno_e_sem_ativacao(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 20, 6, 0)); // antes do primeiro turno
        $demo = User::factory()->create(['role' => Role::Avaliador->value, 'is_demo' => true]);
        AvaliadorProfile::factory()->create(['user_id' => $demo->id, 'presencial' => true]);
        $listaDemo = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Demo', 'tipo' => 'final', 'vigente' => true, 'demo' => true, 'versao' => 1,
        ]);
        $listaDemo->projetos()->attach($this->projeto->id);
        ChecagemEstande::create(['projeto_id' => $this->projeto->id, 'lista_final_id' => $listaDemo->id, 'verificado_em' => now(), 'demo' => true]);
        Sanctum::actingAs($demo);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')->assertJsonPath('data.aberto', false);

        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}", ['teste' => 1])
            ->assertOk()->json('data.id');
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", [...$this->envio(), 'teste' => 1])
            ->assertOk()->assertJsonPath('data.status', 'concluida');
    }

    // --- Lado do admin (a designação mora em DistribuicaoPresencialTest) ---

    public function test_avaliacao_concluida_nao_e_retirada(): void
    {
        Sanctum::actingAs($this->avaliador);
        $id = $this->abrir($this->projeto);
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", $this->envio())->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/v1/admin/presencial/avaliacoes/{$id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('avaliacao');
    }
}
