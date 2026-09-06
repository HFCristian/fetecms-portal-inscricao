<?php

namespace Tests\Feature;

use App\Enums\AbaAdmin;
use App\Enums\Categoria;
use App\Enums\TipoRegistro;
use App\Models\AlmoxarifadoGuarda;
use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 104 — Almoxarifado: a guarda de volumes dos finalistas durante a feira.
 *
 * O balcão só atende quem está na lista final vigente, só abre dentro da janela
 * do evento e, no modo de teste, trabalha numa trilha paralela — o ensaio não
 * enxerga (nem devolve) o material de um finalista de verdade.
 */
class AlmoxarifadoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDay(),
        ]);
    }

    private function listaVigente(bool $demo = false): ListaFinal
    {
        return ListaFinal::firstOrCreate(
            ['edicao_id' => $this->edicao->id, 'vigente' => true, 'demo' => $demo],
            ['nome' => $demo ? 'Demonstração' : 'Oficial', 'versao' => 1],
        );
    }

    private function finalista(string $titulo = 'Bioplástico', bool $demo = false): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['name' => 'Marta Orientadora', 'is_demo' => $demo])->id,
            'titulo' => $titulo,
            'categoria' => Categoria::Fetecms,
            'edicao_id' => $this->edicao->id,
        ]);
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana Aluna']);
        Coorientador::create([
            'projeto_id' => $projeto->id, 'nome' => 'Caio Coorientador',
            'email' => 'caio@x.test', 'cpf' => '52998224725',
        ]);

        $this->listaVigente($demo)->projetos()->syncWithoutDetaching([$projeto->id => ['manual' => false]]);

        return $projeto;
    }

    /** Guarda pronta, com os itens informados. */
    private function guardar(Projeto $projeto, array $itens = ['Maquete', 'Mochila'], array $over = []): array
    {
        $aluno = $projeto->alunos()->first();

        return $this->postJson('/api/v1/admin/almoxarifado/registros', array_merge([
            'projeto_id' => $projeto->id,
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $aluno->id,
            'itens' => $itens,
        ], $over))->json();
    }

    public function test_o_assistente_grava_a_guarda_com_um_item_por_linha(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $projeto = $this->finalista();

        $resposta = $this->postJson('/api/v1/admin/almoxarifado/registros', [
            'projeto_id' => $projeto->id,
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projeto->alunos()->first()->id,
            'itens' => ['Maquete de madeira', '  Mochila azul  ', ''],
        ])->assertCreated()->json('data');

        $this->assertSame('Ana Aluna', $resposta['responsavel']);
        // A linha em branco não vira item, e o espaço sobrando some.
        $this->assertSame(2, $resposta['itens_total']);
        $this->assertSame('Mochila azul', $resposta['itens'][1]['descricao']);
        $this->assertSame('guardado', $resposta['situacao']);
        $this->assertSame(2, $resposta['itens_pendentes']);

        // A entrada do material vira registro, com quem deixou e o que entrou.
        $registro = RegistroAtividade::where('tipo', TipoRegistro::AlmoxarifadoGuarda)->firstOrFail();
        $this->assertSame('Ana Aluna', $registro->detalhes['responsavel']);
        $this->assertSame(['Maquete de madeira', 'Mochila azul'], $registro->detalhes['itens']);
    }

    public function test_o_responsavel_precisa_ser_do_projeto(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $deOutroProjeto = $this->finalista('Outro projeto')->alunos()->first();

        $this->postJson('/api/v1/admin/almoxarifado/registros', [
            'projeto_id' => $projeto->id,
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $deOutroProjeto->id,
            'itens' => ['Maquete'],
        ])->assertStatus(422)->assertJsonValidationErrors('responsavel_id');
    }

    public function test_so_atende_projeto_da_lista_final_vigente(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        // Submetido, mas fora da lista final: não subiu ao evento.
        $foraDaLista = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);

        $this->postJson('/api/v1/admin/almoxarifado/registros', [
            'projeto_id' => $foraDaLista->id,
            'responsavel_tipo' => 'orientador',
            'responsavel_id' => $foraDaLista->user_id,
            'itens' => ['Maquete'],
        ])->assertStatus(422)->assertJsonValidationErrors('projeto_id');
    }

    public function test_itens_vazios_sao_recusados(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();

        $this->postJson('/api/v1/admin/almoxarifado/registros', [
            'projeto_id' => $projeto->id,
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projeto->alunos()->first()->id,
            'itens' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('itens');
    }

    public function test_fora_da_janela_do_evento_nao_guarda_mas_ainda_consulta(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $this->guardar($projeto);

        $this->edicao->update(['evento_de' => now()->addWeek(), 'evento_ate' => now()->addWeeks(2)]);

        $this->postJson('/api/v1/admin/almoxarifado/registros', [
            'projeto_id' => $projeto->id,
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projeto->alunos()->first()->id,
            'itens' => ['Maquete'],
        ])->assertStatus(422)->assertJsonValidationErrors('almoxarifado');

        // A leitura continua: é para isso que a aba abre fora do evento.
        $this->getJson('/api/v1/admin/almoxarifado/registros')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.config.aberto', false);
    }

    public function test_sem_data_de_evento_o_balcao_fica_fechado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->edicao->update(['evento_de' => null, 'evento_ate' => null]);

        $this->getJson('/api/v1/admin/almoxarifado/config')
            ->assertOk()
            ->assertJsonPath('data.aberto', false);
    }

    public function test_modo_de_teste_usa_a_lista_demo_e_nao_ve_o_material_de_verdade(): void
    {
        $demoAdmin = User::factory()->admin()->create(['is_demo' => true]);
        $projetoReal = $this->finalista();
        $projetoDemo = $this->finalista('Projeto de demonstração', demo: true);

        // Um registro de verdade, feito por um admin comum.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->guardar($projetoReal);

        Sanctum::actingAs($demoAdmin);
        // No ensaio, o projeto de verdade nem aparece...
        $this->postJson('/api/v1/admin/almoxarifado/registros?teste=1', [
            'projeto_id' => $projetoReal->id,
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projetoReal->alunos()->first()->id,
            'itens' => ['Maquete'],
        ])->assertStatus(422)->assertJsonValidationErrors('projeto_id');

        // ...e o registro de verdade não entra na lista do ensaio.
        $this->getJson('/api/v1/admin/almoxarifado/registros?teste=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/admin/almoxarifado/registros?teste=1', [
            'projeto_id' => $projetoDemo->id,
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projetoDemo->alunos()->first()->id,
            'itens' => ['Maquete de ensaio'],
        ])->assertCreated();

        $this->assertTrue(AlmoxarifadoGuarda::where('projeto_id', $projetoDemo->id)->firstOrFail()->demo);
        // E o balcão de verdade continua vendo só o dele.
        $this->getJson('/api/v1/admin/almoxarifado/registros')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_comum_nao_liga_o_modo_de_teste(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->finalista('Projeto de demonstração', demo: true);

        $this->getJson('/api/v1/admin/almoxarifado/config?teste=1')
            ->assertOk()
            ->assertJsonPath('data.modo_teste', false)
            ->assertJsonPath('data.pode_testar', false);
    }

    public function test_a_aba_e_barrada_para_quem_nao_tem_o_escopo(): void
    {
        $escopo = EscopoAdmin::create(['nome' => 'Só credenciamento', 'abas' => [AbaAdmin::Credenciamento->value]]);
        $admin = User::factory()->admin()->create();
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/almoxarifado/registros')->assertForbidden();

        // Com o escopo do almoxarifado, abre.
        $comAlmoxarifado = EscopoAdmin::create([
            'nome' => 'Almoxarifado', 'abas' => [AbaAdmin::Almoxarifado->value],
        ]);
        $admin->escopos()->attach($comAlmoxarifado->id, ['edicao_id' => $this->edicao->id]);

        $this->getJson('/api/v1/admin/almoxarifado/registros')->assertOk();
    }

    public function test_a_busca_de_projetos_do_assistente_traz_as_pessoas_do_projeto(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->finalista();

        $dados = $this->getJson('/api/v1/admin/almoxarifado/projetos?busca=biopl')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $dados);
        $this->assertSame(
            ['Ana Aluna', 'Marta Orientadora', 'Caio Coorientador'],
            array_column($dados[0]['pessoas'], 'nome'),
        );
    }

    // ------------------------------------------------------------------ //
    // Sprint 105 — retiradas, correção e exclusão                         //
    // ------------------------------------------------------------------ //

    public function test_retirada_parcial_deixa_o_resto_no_balcao_e_a_completa_zera(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $guarda = $this->guardar($projeto, ['Maquete', 'Mochila', 'Caixa'])['data'];
        $orientador = $projeto->user;

        // O orientador aparece antes e leva só a maquete.
        $parcial = $this->postJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}/retiradas", [
            'responsavel_tipo' => 'orientador',
            'responsavel_id' => $orientador->id,
            'itens' => [$guarda['itens'][0]['id']],
        ])->assertOk()->json('data');

        $this->assertSame('parcial', $parcial['situacao']);
        $this->assertSame(2, $parcial['itens_pendentes']);
        $this->assertSame('Marta Orientadora', $parcial['retirado_por']);

        // Depois a aluna busca o que sobrou: sem `itens`, sai tudo.
        $completa = $this->postJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}/retiradas", [
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projeto->alunos()->first()->id,
        ])->assertOk()->json('data');

        $this->assertSame('retirado', $completa['situacao']);
        $this->assertSame(0, $completa['itens_pendentes']);

        $registros = RegistroAtividade::where('tipo', TipoRegistro::AlmoxarifadoRetirada)->get();
        $this->assertCount(2, $registros);
        $this->assertFalse($registros->first()->detalhes['completa']);
        $this->assertTrue($registros->last()->detalhes['completa']);
        $this->assertSame(['Mochila', 'Caixa'], $registros->last()->detalhes['itens']);
    }

    public function test_item_ja_retirado_nao_e_retirado_de_novo(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $guarda = $this->guardar($projeto, ['Maquete'])['data'];
        $aluno = $projeto->alunos()->first();

        $this->postJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}/retiradas", [
            'responsavel_tipo' => 'aluno', 'responsavel_id' => $aluno->id,
        ])->assertOk();

        // Nada mais está guardado: a segunda tentativa não tem alvo.
        $this->postJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}/retiradas", [
            'responsavel_tipo' => 'orientador', 'responsavel_id' => $projeto->user_id,
        ])->assertStatus(422)->assertJsonValidationErrors('itens');
    }

    public function test_editar_troca_quem_deixou_e_a_lista_de_itens_com_justificativa(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $guarda = $this->guardar($projeto, ['Maquete', 'Mochila'])['data'];

        // Sem justificativa não passa.
        $this->putJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}", [
            'responsavel_tipo' => 'orientador',
            'responsavel_id' => $projeto->user_id,
            'itens' => [['id' => $guarda['itens'][0]['id'], 'descricao' => 'Maquete']],
        ])->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $corrigido = $this->putJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}", [
            'responsavel_tipo' => 'orientador',
            'responsavel_id' => $projeto->user_id,
            'itens' => [
                ['id' => $guarda['itens'][0]['id'], 'descricao' => 'Maquete de madeira'],
                ['descricao' => 'Caixa de ferramentas'],
            ],
            'justificativa' => 'Quem deixou foi a orientadora, e faltou lançar a caixa.',
        ])->assertOk()->json('data');

        $this->assertSame('Marta Orientadora', $corrigido['responsavel']);
        // A mochila saiu da lista, a maquete mudou de nome e a caixa entrou.
        $this->assertSame(
            ['Maquete de madeira', 'Caixa de ferramentas'],
            array_column($corrigido['itens'], 'descricao'),
        );

        $registro = RegistroAtividade::where('tipo', TipoRegistro::AlmoxarifadoEdicao)->firstOrFail();
        $this->assertSame('Ana Aluna · Maquete; Mochila', $registro->detalhes['de']);
        $this->assertStringContainsString('Caixa de ferramentas', $registro->detalhes['para']);
    }

    public function test_item_ja_retirado_nao_sai_do_registro_na_edicao(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $guarda = $this->guardar($projeto, ['Maquete', 'Mochila'])['data'];

        $this->postJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}/retiradas", [
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projeto->alunos()->first()->id,
            'itens' => [$guarda['itens'][0]['id']],
        ])->assertOk();

        // Tentar apagar a maquete, que já foi devolvida, reescreveria a entrega.
        $this->putJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}", [
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projeto->alunos()->first()->id,
            'itens' => [['id' => $guarda['itens'][1]['id'], 'descricao' => 'Mochila']],
            'justificativa' => 'Corrigindo a lista.',
        ])->assertStatus(422)->assertJsonValidationErrors('itens');
    }

    public function test_excluir_pede_justificativa_e_guarda_o_que_havia(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $guarda = $this->guardar($projeto, ['Maquete'])['data'];

        $this->deleteJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}", [])
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->deleteJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}", [
            'justificativa' => 'Lançado em duplicidade no balcão.',
        ])->assertOk();

        // Soft delete: some da tela, continua na trilha.
        $this->assertSoftDeleted('almoxarifado_guardas', ['id' => $guarda['id']]);
        $this->getJson('/api/v1/admin/almoxarifado/registros')->assertOk()->assertJsonCount(0, 'data');

        $registro = RegistroAtividade::where('tipo', TipoRegistro::AlmoxarifadoExclusao)->firstOrFail();
        $this->assertSame('Ana Aluna · Maquete', $registro->detalhes['de']);
        $this->assertSame('Lançado em duplicidade no balcão.', $registro->detalhes['justificativa']);
    }

    public function test_registro_de_ensaio_nao_e_alcancado_fora_do_modo_de_teste(): void
    {
        $demoAdmin = User::factory()->admin()->create(['is_demo' => true]);
        Sanctum::actingAs($demoAdmin);
        $projetoDemo = $this->finalista('Projeto de demonstração', demo: true);

        $guarda = $this->postJson('/api/v1/admin/almoxarifado/registros?teste=1', [
            'projeto_id' => $projetoDemo->id,
            'responsavel_tipo' => 'aluno',
            'responsavel_id' => $projetoDemo->alunos()->first()->id,
            'itens' => ['Maquete de ensaio'],
        ])->assertCreated()->json('data');

        // O mesmo admin, fora do modo de teste, não enxerga o registro do ensaio.
        $this->getJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}")->assertNotFound();
        $this->getJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}?teste=1")->assertOk();
    }

    public function test_fora_da_janela_do_evento_nao_retira_nem_exclui(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $guarda = $this->guardar($projeto, ['Maquete'])['data'];

        $this->edicao->update(['evento_de' => now()->subMonth(), 'evento_ate' => now()->subDay()]);

        $this->postJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}/retiradas", [
            'responsavel_tipo' => 'aluno', 'responsavel_id' => $projeto->alunos()->first()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('almoxarifado');

        $this->deleteJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}", [
            'justificativa' => 'Lançado em duplicidade.',
        ])->assertStatus(422)->assertJsonValidationErrors('almoxarifado');
    }

    public function test_a_secao_de_registros_do_almoxarifado_lista_os_quatro_tipos(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $projeto = $this->finalista();
        $guarda = $this->guardar($projeto, ['Maquete'])['data'];

        $this->postJson("/api/v1/admin/almoxarifado/registros/{$guarda['id']}/retiradas", [
            'responsavel_tipo' => 'aluno', 'responsavel_id' => $projeto->alunos()->first()->id,
        ])->assertOk();

        $resposta = $this->getJson('/api/v1/admin/registros?secao=almoxarifado')->assertOk();

        $this->assertSame(2, $resposta->json('meta.total'));
        $this->assertCount(4, $resposta->json('meta.tipos'));
        $this->assertStringContainsString(
            'retirou tudo: Maquete',
            $resposta->json('data.0.detalhes_texto'),
        );
    }
}
