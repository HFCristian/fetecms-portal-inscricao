<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusPresenca;
use App\Enums\StatusSuporte;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\SuporteProjeto;
use App\Models\User;
use App\Services\ContaTemporariaService;
use App\Services\ExportacaoListaFinalService;
use App\Services\IdentificacaoService;
use App\Support\CodigoParticipante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 161 — credenciamento **fora do prazo** aprovado, com a data prevista
 * de chegada, visível no credenciamento e na avaliação.
 *
 * Sprint 162 — **suporte** (acompanhante, intérpretes): o orientador pede na
 * aba dele, a organização aprova, e só o aprovado chega às equipes — o
 * acompanhante com crachá próprio.
 */
class ForaPrazoESuporteTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    private User $orientador;

    private Projeto $projeto;

    private Aluno $aluno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDays(2),
        ]);
        $this->orientador = User::factory()->create(['role' => Role::Orientador, 'name' => 'Marta Orientadora']);
        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => $this->orientador->id, 'titulo' => 'Biofiltro', 'edicao_id' => $this->edicao->id, 'categoria' => 'fetecms',
        ]);
        $this->aluno = Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Ana Aluna']);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Oficial', 'vigente' => true, 'demo' => false, 'versao' => 1,
        ]);
        $this->lista->projetos()->attach($this->projeto->id);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    // --- Sprint 161: fora do prazo ----------------------------------------

    public function test_admin_marca_fora_do_prazo_e_o_balcao_ve_a_data(): void
    {
        $this->admin();

        $this->putJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}/fora-prazo", [
            'previsto_em' => now()->addDay()->setTime(14, 0)->format('Y-m-d H:i'),
            'observacao' => 'Equipe chega na segunda pela manhã.',
        ])->assertOk()
            ->assertJsonPath('data.sinalizacoes.fora_prazo.previsto_label', now()->addDay()->format('d/m/Y').' 14:00')
            ->assertJsonPath('data.sinalizacoes.fora_prazo.observacao', 'Equipe chega na segunda pela manhã.');

        $this->getJson('/api/v1/admin/credenciamento/finalistas')
            ->assertOk()
            ->assertJsonPath('data.0.sinalizacoes.fora_prazo.observacao', 'Equipe chega na segunda pela manhã.');

        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::CredenciamentoForaPrazo)->exists());

        $this->deleteJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}/fora-prazo")
            ->assertOk()
            ->assertJsonPath('data.sinalizacoes', null);
    }

    public function test_conta_temporaria_do_balcao_nao_aprova_excecao(): void
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Bruna', 'email' => 'balcao@fetec.test', 'password' => 'senha-do-balcao',
            'cpf' => '52998224725', 'curso' => 'Computação', 'horas' => 5,
        ]);
        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();
        Sanctum::actingAs($conta->user);

        $this->putJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}/fora-prazo", [])
            ->assertForbidden();
    }

    public function test_avaliador_presencial_ve_por_que_o_estande_esta_vazio(): void
    {
        $this->admin();
        $this->putJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}/fora-prazo", [
            'observacao' => 'Chega depois.',
        ])->assertOk();

        $avaliador = User::factory()->create(['role' => Role::Avaliador]);
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'presencial' => true]);
        Sanctum::actingAs($avaliador);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonPath('data.disponiveis.0.sinalizacoes.fora_prazo.observacao', 'Chega depois.');
    }

    // --- Sprint 162: suporte ------------------------------------------------

    private function pedirAcompanhante(): SuporteProjeto
    {
        Sanctum::actingAs($this->orientador);

        $this->postJson("/api/v1/suporte/projetos/{$this->projeto->id}", [
            'tipo' => 'acompanhante',
            'aluno_id' => $this->aluno->id,
            'acompanhante_nome' => 'Maria Aluna',
            'acompanhante_documento' => 'RG 1234567',
            'acompanhante_vinculo' => 'Mãe',
        ])->assertCreated()
            ->assertJsonPath('data.projetos.0.suportes.0.status', 'pendente');

        return SuporteProjeto::firstOrFail();
    }

    public function test_orientador_pede_e_so_o_aprovado_chega_as_equipes(): void
    {
        $suporte = $this->pedirAcompanhante();

        // Pendente: ninguém do evento vê ainda.
        $this->admin();
        $this->getJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.sinalizacoes', null)
            ->assertJsonCount(0, 'data.acompanhantes');

        $this->patchJson("/api/v1/admin/credenciamento/suporte/{$suporte->id}/decidir", ['aprovar' => true])
            ->assertOk()
            ->assertJsonPath('data.totais.aprovado', 1);

        $this->getJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.sinalizacoes.suportes.0.resumo', 'Acompanhante: Maria Aluna (Mãe) — de Ana Aluna')
            ->assertJsonPath('data.acompanhantes.0.documento', 'RG 1234567');

        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::SuporteDecidido)->exists());
    }

    public function test_acompanhante_aprovado_ganha_cracha_que_o_balcao_le(): void
    {
        $suporte = $this->pedirAcompanhante();
        $this->admin();
        $this->patchJson("/api/v1/admin/credenciamento/suporte/{$suporte->id}/decidir", ['aprovar' => true])->assertOk();

        $participantes = app(IdentificacaoService::class)->participantes($this->lista);
        $acompanhante = $participantes->firstWhere('papel', CodigoParticipante::PAPEL_ACOMPANHANTE);
        $this->assertSame('Maria Aluna', $acompanhante['nome']);
        $this->assertSame("2026-{$this->projeto->id}-123-S{$suporte->id}", $acompanhante['codigo']);

        $this->postJson('/api/v1/admin/credenciamento/codigo', ['codigo' => $acompanhante['codigo']])
            ->assertOk()
            ->assertJsonPath('data.projeto.id', $this->projeto->id)
            ->assertJsonPath('data.participante.nome', 'Maria Aluna');

        $nominal = app(ExportacaoListaFinalService::class)->linhasPorPessoa($this->lista);
        $this->assertSame('Acompanhante de Ana Aluna', $nominal->firstWhere('nome', 'Maria Aluna')['funcao']);
    }

    public function test_acompanhante_sem_documento_ou_vinculo_e_recusado(): void
    {
        Sanctum::actingAs($this->orientador);

        $this->postJson("/api/v1/suporte/projetos/{$this->projeto->id}", [
            'tipo' => 'acompanhante', 'aluno_id' => $this->aluno->id, 'acompanhante_nome' => 'Maria',
        ])->assertStatus(422)->assertJsonValidationErrors('acompanhante_documento');

        $this->postJson("/api/v1/suporte/projetos/{$this->projeto->id}", ['tipo' => 'interprete_lingua'])
            ->assertStatus(422)->assertJsonValidationErrors('idioma');

        $this->postJson("/api/v1/suporte/projetos/{$this->projeto->id}", ['tipo' => 'interprete_libras', 'aluno_id' => $this->aluno->id])
            ->assertCreated();
    }

    public function test_so_o_dono_e_so_projeto_finalista(): void
    {
        $outro = User::factory()->create(['role' => Role::Orientador]);
        Sanctum::actingAs($outro);
        $this->postJson("/api/v1/suporte/projetos/{$this->projeto->id}", ['tipo' => 'interprete_libras'])->assertForbidden();

        $fora = Projeto::factory()->submetido()->create(['user_id' => $outro->id, 'edicao_id' => $this->edicao->id]);
        $this->postJson("/api/v1/suporte/projetos/{$fora->id}", ['tipo' => 'interprete_libras'])
            ->assertStatus(422)->assertJsonValidationErrors('projeto');
    }

    public function test_recusar_exige_motivo_e_alterar_um_aprovado_volta_para_a_fila(): void
    {
        $suporte = $this->pedirAcompanhante();
        $this->admin();

        $this->patchJson("/api/v1/admin/credenciamento/suporte/{$suporte->id}/decidir", ['aprovar' => false])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');

        $this->patchJson("/api/v1/admin/credenciamento/suporte/{$suporte->id}/decidir", ['aprovar' => true])->assertOk();

        Sanctum::actingAs($this->orientador);
        $this->putJson("/api/v1/suporte/{$suporte->id}", [
            'tipo' => 'acompanhante', 'aluno_id' => $this->aluno->id,
            'acompanhante_nome' => 'Maria Aluna Souza', 'acompanhante_documento' => 'RG 1234567', 'acompanhante_vinculo' => 'Mãe',
        ])->assertOk();

        $this->assertSame(StatusSuporte::Pendente, $suporte->fresh()->status);
    }

    public function test_depois_do_evento_o_orientador_nao_pede_mais(): void
    {
        $this->edicao->update(['evento_de' => now()->subDays(3), 'evento_ate' => now()->subDay()]);
        Sanctum::actingAs($this->orientador);

        $this->getJson('/api/v1/suporte')->assertOk()->assertJsonPath('data.janela.aberta', false);
        $this->postJson("/api/v1/suporte/projetos/{$this->projeto->id}", ['tipo' => 'interprete_libras'])
            ->assertStatus(422)->assertJsonValidationErrors('periodo');
    }

    public function test_admin_registra_pedido_recebido_por_fora_ja_aprovado(): void
    {
        $this->admin();

        $this->postJson("/api/v1/admin/credenciamento/suporte/projetos/{$this->projeto->id}", [
            'tipo' => 'interprete_lingua', 'idioma' => 'Espanhol', 'aluno_id' => $this->aluno->id, 'aprovar' => true,
        ])->assertCreated()
            ->assertJsonPath('data.pedidos.0.status', 'aprovado')
            ->assertJsonPath('data.pedidos.0.resumo', 'Intérprete de outra língua (Espanhol) — para Ana Aluna');
    }
}
