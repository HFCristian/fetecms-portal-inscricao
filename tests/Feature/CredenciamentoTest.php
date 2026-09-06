<?php

namespace Tests\Feature;

use App\Enums\AbaAdmin;
use App\Enums\Categoria;
use App\Enums\SituacaoDocumento;
use App\Enums\TipoPessoaCredenciamento;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\ContaTemporaria;
use App\Models\Coorientador;
use App\Models\Credenciamento;
use App\Models\DocumentoCredenciamento;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 70 — credenciamento dos finalistas no evento: quem sobe sai da lista
 * final vigente, a conferência é documento a documento por pessoa, e o balcão
 * só abre dentro da janela do evento (o admin demo tem modo de teste).
 *
 * Sprint 88 — no modo de teste o balcão troca a lista oficial pela lista
 * **demo** da edição, então o ensaio não alcança nenhum finalista de verdade.
 */
class CredenciamentoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            // Evento acontecendo agora.
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDay(),
        ]);
    }

    private function documento(TipoPessoaCredenciamento $tipo, string $nome): DocumentoCredenciamento
    {
        return DocumentoCredenciamento::create(['tipo_pessoa' => $tipo->value, 'nome' => $nome, 'ativo' => true]);
    }

    /** Projeto finalista (na lista vigente), com aluno e coorientador. */
    private function finalista(string $titulo = 'Bioplástico', ?Area $area = null): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['name' => 'Marta Orientadora'])->id,
            'titulo' => $titulo,
            'categoria' => Categoria::Fetecms,
            'area_id' => $area?->id,
            'edicao_id' => $this->edicao->id,
        ]);
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana Aluna']);
        Coorientador::create([
            'projeto_id' => $projeto->id, 'nome' => 'Caio Coorientador',
            'email' => 'caio@x.test', 'cpf' => '52998224725',
        ]);

        $this->listaVigente()->projetos()->syncWithoutDetaching([$projeto->id => ['manual' => false]]);

        return $projeto;
    }

    private function listaVigente(): ListaFinal
    {
        return ListaFinal::firstOrCreate(
            ['edicao_id' => $this->edicao->id, 'vigente' => true, 'demo' => false],
            ['nome' => 'Oficial', 'versao' => 1],
        );
    }

    /** A lista paralela do modo de teste, com o seu próprio projeto de mentira. */
    private function listaDemo(): ListaFinal
    {
        return ListaFinal::firstOrCreate(
            ['edicao_id' => $this->edicao->id, 'vigente' => true, 'demo' => true],
            ['nome' => 'Demonstração', 'versao' => 1],
        );
    }

    private function finalistaDemo(string $titulo = 'Projeto de demonstração'): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['name' => 'Orientador Demo', 'is_demo' => true])->id,
            'titulo' => $titulo,
            'categoria' => Categoria::Fetecms,
            'edicao_id' => $this->edicao->id,
        ]);

        $this->listaDemo()->projetos()->syncWithoutDetaching([$projeto->id => ['manual' => false]]);

        return $projeto;
    }

    /** Uma conta de balcão: admin restrito à aba Credenciamento, com prazo. */
    private function contaTemporaria(string $email = 'balcao@fetec.test'): ContaTemporaria
    {
        return app(ContaTemporariaService::class)->criar([
            'name' => 'Bruna Atendente',
            'email' => $email,
            'password' => 'senha-do-balcao',
            'cpf' => '52998224725',
            'curso' => 'Ciência da Computação',
            'horas' => 5,
        ]);
    }

    // ------------------------------------------------------------------ //
    // Sprint 97 — cancelar e mexer no credenciamento alheio               //
    // ------------------------------------------------------------------ //

    public function test_admin_cancela_o_credenciamento_e_o_projeto_volta_para_a_fila(): void
    {
        $projeto = $this->finalista();
        $admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();
        $this->assertDatabaseHas('credenciamentos', ['projeto_id' => $projeto->id]);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/cancelar", [
            'justificativa' => 'Credenciado por engano, equipe errada.',
        ])->assertOk()->assertJsonPath('data.credenciamento', null);

        $this->assertDatabaseMissing('credenciamentos', ['projeto_id' => $projeto->id]);

        // Volta a aparecer como pendente no balcão.
        $this->getJson('/api/v1/admin/credenciamento/finalistas?situacao=pendentes')
            ->assertOk()
            ->assertJsonPath('data.0.id', $projeto->id);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::CredenciamentoCancelado)->firstOrFail();
        $this->assertSame('Credenciado por engano, equipe errada.', $registro->detalhes['justificativa']);
        $this->assertStringContainsString('Ana Admin', $registro->detalhes['de']);
    }

    public function test_cancelamento_exige_justificativa(): void
    {
        $projeto = $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/cancelar", ['justificativa' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('justificativa');

        $this->assertDatabaseHas('credenciamentos', ['projeto_id' => $projeto->id]);
    }

    public function test_conta_temporaria_nao_mexe_no_credenciamento_de_outra_conta(): void
    {
        $projeto = $this->finalista();
        $permanente = User::factory()->admin()->create(['name' => 'Ana Admin']);
        Sanctum::actingAs($permanente);
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        Sanctum::actingAs($this->contaTemporaria()->user);

        // Vê a ficha, mas em leitura e com o motivo.
        $this->getJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.credenciamento.pode_alterar', false)
            ->assertJsonPath('data.credenciamento.motivo_bloqueio', 'Este credenciamento foi feito por Ana Admin. Só um administrador com conta permanente pode alterá-lo ou cancelá-lo.');

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/cancelar", [
            'justificativa' => 'Quero desfazer o trabalho da colega.',
        ])->assertStatus(422)->assertJsonValidationErrors('credenciamento');

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('credenciamento');

        $this->assertDatabaseHas('credenciamentos', ['projeto_id' => $projeto->id]);
    }

    public function test_conta_temporaria_corrige_e_cancela_o_que_ela_mesma_credenciou(): void
    {
        $projeto = $this->finalista();
        $conta = $this->contaTemporaria();
        Sanctum::actingAs($conta->user);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        $this->getJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.credenciamento.pode_alterar', true)
            ->assertJsonPath('data.credenciamento.credenciado_por_mim', true);

        // Regravar o próprio credenciamento continua permitido...
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        // ...e cancelar também.
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/cancelar", [
            'justificativa' => 'Digitei no projeto errado.',
        ])->assertOk();

        $this->assertDatabaseMissing('credenciamentos', ['projeto_id' => $projeto->id]);
    }

    public function test_admin_permanente_mexe_no_credenciamento_da_conta_temporaria(): void
    {
        $projeto = $this->finalista();
        Sanctum::actingAs($this->contaTemporaria()->user);
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/cancelar", [
            'justificativa' => 'Documento conferido errado no balcão.',
        ])->assertOk();

        $this->assertDatabaseMissing('credenciamentos', ['projeto_id' => $projeto->id]);
    }

    public function test_nao_cancela_o_que_ainda_nao_foi_credenciado(): void
    {
        $projeto = $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/cancelar", [
            'justificativa' => 'Não deveria estar aqui.',
        ])->assertStatus(422)->assertJsonValidationErrors('credenciamento');
    }

    public function test_finalistas_saem_da_lista_vigente(): void
    {
        $dentro = $this->finalista('Na lista');
        // Submetido, mas fora da lista oficial: não é finalista.
        Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'titulo' => 'Fora', 'edicao_id' => $this->edicao->id,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $resposta = $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertOk();

        $this->assertSame([$dentro->id], array_column($resposta->json('data'), 'id'));
        $this->assertSame(1, $resposta->json('meta.resumo.finalistas'));
        $this->assertSame(0, $resposta->json('meta.resumo.credenciados'));
    }

    public function test_sem_lista_oficial_nao_ha_finalistas(): void
    {
        Projeto::factory()->submetido()->create(['user_id' => User::factory()->create()->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/credenciamento/finalistas')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.resumo.finalistas', 0);
    }

    public function test_a_ficha_traz_alunos_orientador_e_coorientador_com_seus_documentos(): void
    {
        $rg = $this->documento(TipoPessoaCredenciamento::Aluno, 'RG');
        $autorizacao = $this->documento(TipoPessoaCredenciamento::Aluno, 'Autorização de menor');
        $this->documento(TipoPessoaCredenciamento::Orientador, 'Documento com foto');
        $this->documento(TipoPessoaCredenciamento::Coorientador, 'Documento com foto');

        $projeto = $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        $pessoas = $this->getJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}")
            ->assertOk()
            ->json('data.pessoas');

        $this->assertCount(3, $pessoas);
        $this->assertSame(['aluno', 'orientador', 'coorientador'], array_column($pessoas, 'tipo'));
        $this->assertSame('Ana Aluna', $pessoas[0]['nome']);
        // Cada papel vê só os documentos da sua lista.
        $this->assertSame([$rg->id, $autorizacao->id], array_column($pessoas[0]['documentos'], 'id'));
        $this->assertCount(1, $pessoas[1]['documentos']);
        $this->assertNull($pessoas[0]['documentos'][0]['situacao']);
    }

    public function test_projeto_fora_da_lista_nao_tem_ficha(): void
    {
        $this->listaVigente();
        $fora = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'edicao_id' => $this->edicao->id,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/credenciamento/projetos/{$fora->id}")->assertNotFound();
    }

    public function test_credenciar_grava_a_conferencia_e_registra_quem_e_quando(): void
    {
        $rg = $this->documento(TipoPessoaCredenciamento::Aluno, 'RG');
        $autorizacao = $this->documento(TipoPessoaCredenciamento::Aluno, 'Autorização de menor');
        $foto = $this->documento(TipoPessoaCredenciamento::Orientador, 'Documento com foto');

        $projeto = $this->finalista();
        $aluno = $projeto->alunos()->first();
        $admin = User::factory()->admin()->create(['name' => 'Rita Balcão']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", [
            'marcacoes' => [
                ['documento_id' => $rg->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id, 'situacao' => 'presente'],
                ['documento_id' => $autorizacao->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id, 'situacao' => 'nao_necessario'],
                ['documento_id' => $foto->id, 'pessoa_tipo' => 'orientador', 'pessoa_id' => $projeto->user_id, 'situacao' => 'ausente'],
            ],
            'observacao' => 'Orientador vai trazer o documento depois.',
        ])->assertOk()->assertJsonPath('data.credenciamento.concluido', true);

        $credenciamento = Credenciamento::where('projeto_id', $projeto->id)->first();
        $this->assertNotNull($credenciamento);
        $this->assertSame($admin->id, $credenciamento->credenciado_por);
        $this->assertNotNull($credenciamento->finalizado_em);
        $this->assertNotNull($credenciamento->iniciado_em);
        $this->assertSame(3, $credenciamento->documentos()->count());

        // O nome da pessoa fica gravado junto da marcação.
        $this->assertDatabaseHas('credenciamento_documentos', [
            'credenciamento_id' => $credenciamento->id,
            'documento_credenciamento_id' => $rg->id,
            'pessoa_nome' => 'Ana Aluna',
            'situacao' => SituacaoDocumento::Presente->value,
        ]);

        // Auditoria: quem credenciou, quando, e o que ficou ausente.
        $registro = RegistroAtividade::where('tipo', TipoRegistro::CredenciamentoRealizado)->first();
        $this->assertNotNull($registro);
        $this->assertSame($admin->email, $registro->autor_email);
        $this->assertSame($projeto->id, $registro->projeto_id);
        $this->assertContains('Marta Orientadora: Documento com foto', $registro->detalhes['pendencias']);
    }

    public function test_marcacao_de_pessoa_ou_documento_de_fora_e_ignorada(): void
    {
        $rg = $this->documento(TipoPessoaCredenciamento::Aluno, 'RG');
        $projeto = $this->finalista();
        $intruso = Aluno::factory()->create(['projeto_id' => $this->finalista('Outro')->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", [
            'marcacoes' => [
                // Aluno de outro projeto.
                ['documento_id' => $rg->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $intruso->id, 'situacao' => 'presente'],
                // Documento de aluno marcado no orientador.
                ['documento_id' => $rg->id, 'pessoa_tipo' => 'orientador', 'pessoa_id' => $projeto->user_id, 'situacao' => 'presente'],
            ],
        ])->assertOk();

        $this->assertSame(0, Credenciamento::where('projeto_id', $projeto->id)->first()->documentos()->count());
    }

    public function test_fora_da_janela_do_evento_nao_credencia(): void
    {
        $this->edicao->update(['evento_de' => now()->addDays(5), 'evento_ate' => now()->addDays(6)]);
        $projeto = $this->finalista();

        Sanctum::actingAs(User::factory()->admin()->create());

        // Ler continua liberado: a aba abre em leitura.
        $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertOk();
        $this->getJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.config.aberto', false);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credenciamento');
    }

    public function test_sem_data_de_evento_o_balcao_fica_fechado(): void
    {
        $this->edicao->update(['evento_de' => null, 'evento_ate' => null]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/credenciamento/config')
            ->assertOk()
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.iniciado', false);
    }

    public function test_admin_demo_credencia_antes_do_evento_em_modo_de_teste(): void
    {
        $this->edicao->update(['evento_de' => now()->addDays(5), 'evento_ate' => now()->addDays(6)]);
        $projeto = $this->finalistaDemo();
        $demo = User::factory()->admin()->create(['is_demo' => true]);
        Sanctum::actingAs($demo);

        $this->getJson('/api/v1/admin/credenciamento/config?teste=1')
            ->assertOk()
            ->assertJsonPath('data.aberto', true)
            ->assertJsonPath('data.pode_testar', true)
            ->assertJsonPath('data.modo_teste', true)
            // A lista que aparece no ensaio é a de demonstração.
            ->assertJsonPath('data.lista.demo', true);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}?teste=1", ['marcacoes' => []])
            ->assertOk();

        $this->assertNotNull(Credenciamento::where('projeto_id', $projeto->id)->first()->finalizado_em);
    }

    public function test_admin_comum_nao_tem_modo_de_teste(): void
    {
        $this->edicao->update(['evento_de' => now()->addDays(5), 'evento_ate' => now()->addDays(6)]);
        $projeto = $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/credenciamento/config?teste=1')
            ->assertOk()
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.pode_testar', false);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}?teste=1", ['marcacoes' => []])
            ->assertStatus(422);
    }

    public function test_lista_separa_credenciados_de_pendentes(): void
    {
        $credenciado = $this->finalista('Já passou');
        $this->finalista('Ainda não');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$credenciado->id}", ['marcacoes' => []])->assertOk();

        $this->getJson('/api/v1/admin/credenciamento/finalistas?situacao=credenciados')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.titulo', 'Já passou');

        $this->getJson('/api/v1/admin/credenciamento/finalistas?situacao=pendentes')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.titulo', 'Ainda não');

        // O resumo ignora o filtro de situação — senão o card mostraria só a aba aberta.
        $this->getJson('/api/v1/admin/credenciamento/finalistas?situacao=credenciados')
            ->assertJsonPath('meta.resumo.finalistas', 2)
            ->assertJsonPath('meta.resumo.credenciados', 1)
            ->assertJsonPath('meta.resumo.pendentes', 1);
    }

    public function test_lista_filtra_por_busca_area_e_categoria(): void
    {
        $area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $alvo = $this->finalista('Energia solar', $area);
        $this->finalista('Robótica');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/credenciamento/finalistas?busca=solar')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alvo->id);

        $this->getJson("/api/v1/admin/credenciamento/finalistas?area_id={$area->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alvo->id);

        $this->getJson('/api/v1/admin/credenciamento/finalistas?categoria='.Categoria::Fetecms->value)
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_sem_a_aba_credenciamento_no_escopo_e_barrado(): void
    {
        User::factory()->admin()->create(); // guardião do acesso total
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => [AbaAdmin::Projetos->value]]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertForbidden();
    }

    public function test_admin_parametriza_janela_do_evento_e_documentos(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/credenciamento/janela', [
            'evento_de' => '2026-10-01T08:00',
            'evento_ate' => '2026-10-03T18:00',
        ])->assertOk()->assertJsonPath('data.config.inicio_label', '01/10/2026 08:00');

        $this->postJson('/api/v1/admin/credenciamento/documentos', [
            'tipo_pessoa' => 'aluno', 'nome' => 'RG',
        ])->assertCreated();

        // Repetir o mesmo documento no mesmo papel é recusado.
        $this->postJson('/api/v1/admin/credenciamento/documentos', [
            'tipo_pessoa' => 'aluno', 'nome' => 'RG',
        ])->assertStatus(422)->assertJsonValidationErrors('nome');

        // O mesmo nome em outro papel passa.
        $this->postJson('/api/v1/admin/credenciamento/documentos', [
            'tipo_pessoa' => 'orientador', 'nome' => 'RG',
        ])->assertCreated();

        $documento = DocumentoCredenciamento::where('tipo_pessoa', 'aluno')->first();
        $this->deleteJson("/api/v1/admin/credenciamento/documentos/{$documento->id}")->assertOk();
        $this->assertDatabaseMissing('documentos_credenciamento', ['id' => $documento->id]);
    }

    public function test_fim_do_evento_antes_do_inicio_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/credenciamento/janela', [
            'evento_de' => '2026-10-03T08:00',
            'evento_ate' => '2026-10-01T18:00',
        ])->assertStatus(422)->assertJsonValidationErrors('evento_ate');
    }

    public function test_documento_ja_conferido_nao_e_excluido(): void
    {
        $rg = $this->documento(TipoPessoaCredenciamento::Aluno, 'RG');
        $projeto = $this->finalista();
        $aluno = $projeto->alunos()->first();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", [
            'marcacoes' => [
                ['documento_id' => $rg->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id, 'situacao' => 'presente'],
            ],
        ])->assertOk();

        $this->deleteJson("/api/v1/admin/credenciamento/documentos/{$rg->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('documento');
    }

    public function test_credenciamento_aparece_na_secao_propria_dos_registros(): void
    {
        $projeto = $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        $tipos = array_column(
            $this->getJson('/api/v1/admin/registros?secao='.TipoRegistro::SECAO_CREDENCIAMENTO)->assertOk()->json('data'),
            'tipo',
        );

        $this->assertSame([TipoRegistro::CredenciamentoRealizado->value], $tipos);
    }

    // --- Sprint 71: horários do atendimento e itens entregues ---

    public function test_sem_alterar_o_inicio_o_fim_e_o_instante_da_conclusao(): void
    {
        $projeto = $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        // O relógio anda para a hora do atendimento; a janela cobre o dia todo.
        $this->travelTo(now()->setTime(10, 0));
        $this->edicao->update(['evento_de' => now()->startOfDay(), 'evento_ate' => now()->endOfDay()]);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        $credenciamento = Credenciamento::where('projeto_id', $projeto->id)->first();
        $this->assertSame('10:00', $credenciamento->iniciado_em->format('H:i'));
        $this->assertSame('10:00', $credenciamento->finalizado_em->format('H:i'));
    }

    public function test_inicio_alterado_faz_o_fim_ser_inicio_mais_cinco_minutos(): void
    {
        $projeto = $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        // Lançado às 16h, mas o atendimento aconteceu às 9h.
        $this->travelTo(now()->setTime(16, 0));
        $this->edicao->update(['evento_de' => now()->startOfDay(), 'evento_ate' => now()->endOfDay()]);
        $inicio = now()->setTime(9, 0)->format('Y-m-d\TH:i');

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", [
            'marcacoes' => [],
            'iniciado_em' => $inicio,
        ])->assertOk();

        $credenciamento = Credenciamento::where('projeto_id', $projeto->id)->first();
        $this->assertSame('09:00', $credenciamento->iniciado_em->format('H:i'));
        $this->assertSame('09:05', $credenciamento->finalizado_em->format('H:i'));
    }

    public function test_a_config_traz_os_itens_a_entregar_e_a_duracao_padrao(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/credenciamento/itens', [
            'itens' => ['Camiseta', ' Crachá ', 'Camiseta', ''],
        ])->assertOk();

        // Duplicata, espaço e vazio saem da lista.
        $this->assertSame(['Camiseta', 'Crachá'], $this->edicao->fresh()->itens_credenciamento);

        $this->getJson('/api/v1/admin/credenciamento/config')
            ->assertOk()
            ->assertJsonPath('data.itens', ['Camiseta', 'Crachá'])
            ->assertJsonPath('data.minutos_atendimento', 5);
    }

    public function test_itens_com_lista_vazia_limpam_a_parametrizacao(): void
    {
        $this->edicao->update(['itens_credenciamento' => ['Camiseta']]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/credenciamento/itens', ['itens' => []])->assertOk();

        $this->assertSame([], $this->edicao->fresh()->itens_credenciamento);
    }

    // --- Sprint 88: a lista demo do modo de teste ---

    /** Ligado o modo de teste, o balcão troca de lista — não vê a oficial. */
    public function test_modo_de_teste_lista_apenas_os_finalistas_demo(): void
    {
        $oficial = $this->finalista('Finalista de verdade');
        $ensaio = $this->finalistaDemo('Finalista de mentira');

        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $comTeste = $this->getJson('/api/v1/admin/credenciamento/finalistas?teste=1')->assertOk();
        $this->assertSame([$ensaio->id], array_column($comTeste->json('data'), 'id'));

        // Desligado, é a oficial de novo: a lista demo some da tela.
        $semTeste = $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertOk();
        $this->assertSame([$oficial->id], array_column($semTeste->json('data'), 'id'));
    }

    /** O ensaio não alcança um finalista de verdade nem forçando a URL. */
    public function test_modo_de_teste_nao_credencia_finalista_oficial(): void
    {
        $this->edicao->update(['evento_de' => now()->addDays(5), 'evento_ate' => now()->addDays(6)]);
        $oficial = $this->finalista();
        $this->finalistaDemo();

        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $this->getJson("/api/v1/admin/credenciamento/projetos/{$oficial->id}?teste=1")->assertNotFound();

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$oficial->id}?teste=1", ['marcacoes' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credenciamento');

        $this->assertNull(Credenciamento::where('projeto_id', $oficial->id)->first());
    }

    /** E o balcão de verdade não enxerga o projeto de demonstração. */
    public function test_balcao_oficial_ignora_a_lista_demo(): void
    {
        $ensaio = $this->finalistaDemo();

        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $this->getJson("/api/v1/admin/credenciamento/projetos/{$ensaio->id}")->assertNotFound();
        $this->getJson('/api/v1/admin/credenciamento/config')
            ->assertOk()
            ->assertJsonPath('data.lista', null);
    }

    /** Publicar a lista oficial não encerra a demo, e vice-versa. */
    public function test_lista_demo_e_oficial_convivem(): void
    {
        $this->listaDemo();
        $this->listaVigente();

        $this->assertTrue(ListaFinal::vigente($this->edicao)->exists);
        $this->assertFalse(ListaFinal::vigente($this->edicao)->demo);
        $this->assertTrue(ListaFinal::vigente($this->edicao, true)->demo);
    }

    // ------------------------------------------------------------------ //
    // Sprint 103 — presença por pessoa e retirada de kit                  //
    // ------------------------------------------------------------------ //

    public function test_pessoa_ausente_dispensa_os_documentos_e_o_projeto_e_credenciado(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $projeto = $this->finalista();
        $rg = $this->documento(TipoPessoaCredenciamento::Aluno, 'RG');
        $aluno = $projeto->alunos()->first();

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", [
            'marcacoes' => [],
            'pessoas' => [
                ['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id, 'presente' => false],
            ],
        ])->assertOk();

        $credenciamento = Credenciamento::where('projeto_id', $projeto->id)->firstOrFail();
        $this->assertNotNull($credenciamento->finalizado_em, 'o projeto é credenciado mesmo com alguém ausente');

        $linha = $credenciamento->pessoas()->where('pessoa_id', $aluno->id)->firstOrFail();
        $this->assertFalse($linha->presente);
        // Não se confere o RG de quem não veio.
        $this->assertSame(0, $credenciamento->documentos()->count());

        // A ausência entra na trilha, junto do credenciamento.
        $registro = RegistroAtividade::where('tipo', TipoRegistro::CredenciamentoRealizado)->firstOrFail();
        $this->assertStringContainsString('Ana Aluna', json_encode($registro->detalhes));

        // E a lista mostra a pendência.
        $linhaLista = collect($this->getJson('/api/v1/admin/credenciamento/finalistas')->json('data'))
            ->firstWhere('id', $projeto->id);
        $this->assertSame(1, $linhaLista['ausentes']);

        $this->assertNotNull($rg->id);
    }

    public function test_marcar_ausente_apaga_o_que_ja_tinha_sido_conferido_daquela_pessoa(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $projeto = $this->finalista();
        $rg = $this->documento(TipoPessoaCredenciamento::Aluno, 'RG');
        $aluno = $projeto->alunos()->first();

        $marcacao = [[
            'documento_id' => $rg->id, 'pessoa_tipo' => 'aluno',
            'pessoa_id' => $aluno->id, 'situacao' => SituacaoDocumento::Presente->value,
        ]];

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => $marcacao])
            ->assertOk();
        $this->assertSame(1, Credenciamento::firstOrFail()->documentos()->count());

        // O admin percebe que era o irmão que estava ali, e corrige para ausente.
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", [
            'marcacoes' => $marcacao,
            'pessoas' => [['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id, 'presente' => false]],
        ])->assertOk();

        $this->assertSame(0, Credenciamento::firstOrFail()->documentos()->count());
    }

    public function test_kit_sai_no_nome_de_um_responsavel_e_o_que_sobra_e_retirado_depois(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $projeto = $this->finalista();
        $aluno = $projeto->alunos()->first();
        $orientador = $projeto->user;
        $coorientador = $projeto->coorientador;

        // No balcão, a aluna leva o kit dela e o do orientador.
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", [
            'marcacoes' => [],
            'kits' => [
                'responsavel_tipo' => 'aluno',
                'responsavel_id' => $aluno->id,
                'pessoas' => [
                    ['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id],
                    ['pessoa_tipo' => 'orientador', 'pessoa_id' => $orientador->id],
                ],
            ],
        ])->assertOk();

        $credenciamento = Credenciamento::where('projeto_id', $projeto->id)->firstOrFail();
        $this->assertSame(
            2,
            $credenciamento->pessoas()->whereNotNull('kit_retirado_em')->count(),
        );
        $this->assertSame(
            'Ana Aluna',
            $credenciamento->pessoas()->where('pessoa_tipo', 'orientador')->first()->kit_retirado_por_nome,
        );

        // O coorientador aparece mais tarde e retira o dele.
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/kits", [
            'responsavel_tipo' => 'coorientador',
            'responsavel_id' => $coorientador->id,
            'pessoas' => [['pessoa_tipo' => 'coorientador', 'pessoa_id' => $coorientador->id]],
        ])->assertOk();

        $this->assertSame(0, $credenciamento->pessoas()->whereNull('kit_retirado_em')->count());

        // Cada retirada vira um registro, com quem levou e de quem.
        $registros = RegistroAtividade::where('tipo', TipoRegistro::CredenciamentoKitRetirado)->get();
        $this->assertCount(2, $registros);
        $this->assertSame('Ana Aluna', $registros->first()->detalhes['responsavel']);
        $this->assertSame(['Ana Aluna', 'Marta Orientadora'], $registros->first()->detalhes['kits']);
    }

    public function test_kit_ja_retirado_nao_muda_de_dono(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $projeto = $this->finalista();
        $aluno = $projeto->alunos()->first();
        $orientador = $projeto->user;

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", [
            'marcacoes' => [],
            'kits' => [
                'responsavel_tipo' => 'aluno', 'responsavel_id' => $aluno->id,
                'pessoas' => [['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id]],
            ],
        ])->assertOk();

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/kits", [
            'responsavel_tipo' => 'orientador', 'responsavel_id' => $orientador->id,
            'pessoas' => [['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id]],
        ])->assertOk();

        $linha = Credenciamento::firstOrFail()->pessoas()->where('pessoa_tipo', 'aluno')->firstOrFail();
        $this->assertSame('Ana Aluna', $linha->kit_retirado_por_nome);
    }

    public function test_responsavel_pelo_kit_precisa_ser_do_projeto(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $aluno = $projeto->alunos()->first();

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/kits", [
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $aluno->id + 999,
            'pessoas' => [['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('responsavel_id');
    }

    public function test_conta_temporaria_registra_kit_de_credenciamento_alheio(): void
    {
        $projeto = $this->finalista();
        $aluno = $projeto->alunos()->first();

        // Um admin permanente credencia no primeiro turno.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}", ['marcacoes' => []])->assertOk();

        // No turno seguinte, o balcão é uma conta temporária: ela não corrige a
        // conferência alheia, mas precisa poder entregar o kit que faltou.
        Sanctum::actingAs($this->contaTemporaria()->user);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$projeto->id}/kits", [
            'responsavel_tipo' => 'aluno', 'responsavel_id' => $aluno->id,
            'pessoas' => [['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id]],
        ])->assertOk();

        $this->assertNotNull(
            Credenciamento::firstOrFail()->pessoas()->where('pessoa_tipo', 'aluno')->first()->kit_retirado_em,
        );
    }
}
