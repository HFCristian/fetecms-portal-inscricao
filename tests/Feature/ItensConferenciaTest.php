<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Models\Aluno;
use App\Models\ChecagemEstandeItem;
use App\Models\Coorientador;
use App\Models\CredenciamentoDocumento;
use App\Models\Edicao;
use App\Models\ItemConferencia;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprints 181–182 — a lista única de itens do credenciamento e da checagem:
 * item de pessoa (com papéis) ou do projeto, conferido num lugar ou nos dois.
 */
class ItensConferenciaTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Projeto $projeto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDay(),
        ]);

        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['name' => 'Marta Orientadora'])->id,
            'titulo' => 'Bioplástico', 'categoria' => Categoria::Fetecms, 'edicao_id' => $this->edicao->id,
        ]);
        Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Ana']);
        Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Beto']);
        Coorientador::create(['projeto_id' => $this->projeto->id, 'nome' => 'Caio', 'email' => 'caio@x.test', 'cpf' => '52998224725']);

        ListaFinal::create(['edicao_id' => $this->edicao->id, 'nome' => 'Oficial', 'vigente' => true, 'versao' => 1])
            ->projetos()->attach($this->projeto->id, ['manual' => false]);

        // Os itens do sistema (os termos) ficam de lado para as contas.
        ItemConferencia::whereNotNull('chave')->update(['ativo' => false]);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function criar(array $dados)
    {
        return $this->postJson('/api/v1/admin/itens-conferencia', $dados);
    }

    // --- Cadastro -------------------------------------------------------

    public function test_cria_item_de_pessoa_com_papeis_e_item_de_projeto(): void
    {
        $this->criar([
            'nome' => 'Documento com foto', 'abrangencia' => 'pessoa', 'papeis' => ['aluno', 'orientador'],
            'no_credenciamento' => true, 'na_checagem' => true, 'receber_antes' => true,
        ])->assertCreated()->assertJsonPath('meta.message', 'Item criado.');

        $this->criar([
            'nome' => 'Banner', 'descricao' => 'Impresso e montado', 'abrangencia' => 'projeto',
            'papeis' => ['aluno'], 'na_checagem' => true,
        ])->assertCreated();

        $foto = ItemConferencia::where('nome', 'Documento com foto')->firstOrFail();
        $this->assertSame(['aluno', 'orientador'], $foto->papeis);
        $this->assertTrue($foto->no_credenciamento && $foto->na_checagem && $foto->receber_antes);

        // Item do projeto não guarda papel.
        $banner = ItemConferencia::where('nome', 'Banner')->firstOrFail();
        $this->assertNull($banner->papeis);
        $this->assertSame('Impresso e montado', $banner->descricao);
    }

    public function test_item_precisa_de_lugar_e_o_de_pessoa_precisa_de_papel(): void
    {
        $this->criar(['nome' => 'RG', 'abrangencia' => 'pessoa', 'papeis' => [], 'no_credenciamento' => true])
            ->assertStatus(422)->assertJsonValidationErrors('papeis');

        $this->criar(['nome' => 'RG', 'abrangencia' => 'pessoa', 'papeis' => ['aluno']])
            ->assertStatus(422)->assertJsonValidationErrors('onde');

        $this->criar(['nome' => 'RG', 'abrangencia' => 'pessoa', 'papeis' => ['diretor'], 'no_credenciamento' => true])
            ->assertStatus(422)->assertJsonValidationErrors('papeis.0');
    }

    public function test_nome_repetido_e_recusado_sem_olhar_acento_e_caixa(): void
    {
        $this->criar(['nome' => 'Autorização', 'abrangencia' => 'projeto', 'na_checagem' => true])->assertCreated();

        $this->criar(['nome' => 'autorizacao', 'abrangencia' => 'projeto', 'na_checagem' => true])
            ->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_edicao_parcial_mantem_o_resto_do_item(): void
    {
        $this->criar([
            'nome' => 'RG', 'descricao' => 'Original ou cópia', 'abrangencia' => 'pessoa',
            'papeis' => ['aluno'], 'no_credenciamento' => true,
        ])->assertCreated();
        $rg = ItemConferencia::where('nome', 'RG')->firstOrFail();

        $this->patchJson("/api/v1/admin/itens-conferencia/{$rg->id}", ['ativo' => false])->assertOk();

        $rg->refresh();
        $this->assertFalse($rg->ativo);
        $this->assertSame('Original ou cópia', $rg->descricao);
        $this->assertSame(['aluno'], $rg->papeis);
    }

    public function test_item_do_sistema_nao_muda_de_abrangencia_nem_deixa_de_ser_recebido_antes(): void
    {
        $termo = ItemConferencia::where('chave', ItemConferencia::CHAVE_TERMO_IMAGEM)->firstOrFail();

        $this->patchJson("/api/v1/admin/itens-conferencia/{$termo->id}", [
            'nome' => 'Autorização de imagem', 'abrangencia' => 'projeto', 'receber_antes' => false, 'na_checagem' => true,
        ])->assertOk();

        $termo->refresh();
        $this->assertSame('Autorização de imagem', $termo->nome);
        $this->assertSame('pessoa', $termo->abrangencia);
        $this->assertSame(['aluno'], $termo->papeis);
        $this->assertTrue($termo->receber_antes);
        $this->assertTrue($termo->na_checagem);

        $this->deleteJson("/api/v1/admin/itens-conferencia/{$termo->id}")->assertStatus(422)->assertJsonValidationErrors('item');
    }

    public function test_item_sem_uso_e_excluido(): void
    {
        $this->criar(['nome' => 'Crachá', 'abrangencia' => 'projeto', 'na_checagem' => true])->assertCreated();
        $item = ItemConferencia::where('nome', 'Crachá')->firstOrFail();

        $this->deleteJson("/api/v1/admin/itens-conferencia/{$item->id}")->assertOk();
        $this->assertDatabaseMissing('itens_conferencia', ['id' => $item->id]);
    }

    public function test_cadastro_e_so_de_quem_tem_a_parametrizacao(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/itens-conferencia')->assertForbidden();
    }

    // --- Credenciamento --------------------------------------------------

    public function test_ficha_do_balcao_traz_item_de_varios_papeis_e_o_bloco_do_projeto(): void
    {
        $foto = ItemConferencia::create([
            'nome' => 'Documento com foto', 'abrangencia' => 'pessoa', 'papeis' => ['aluno', 'coorientador'],
            'no_credenciamento' => true, 'ativo' => true,
        ]);
        $relatorio = ItemConferencia::create([
            'nome' => 'Relatório impresso', 'abrangencia' => 'projeto', 'no_credenciamento' => true, 'ativo' => true,
        ]);
        // Só da checagem: não aparece no balcão.
        ItemConferencia::create(['nome' => 'Banner', 'abrangencia' => 'projeto', 'na_checagem' => true, 'ativo' => true]);

        $ficha = $this->getJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}")->assertOk()->json('data');

        $documentos = collect($ficha['pessoas'])->mapWithKeys(fn ($p) => [$p['nome'] => array_column($p['documentos'], 'id')]);
        $this->assertSame([$foto->id], $documentos['Ana']);
        $this->assertSame([], $documentos['Marta Orientadora']);
        $this->assertSame([$foto->id], $documentos['Caio']);
        $this->assertSame([$relatorio->id], array_column($ficha['itens_projeto'], 'id'));
        $this->assertSame('projeto', $ficha['itens_projeto'][0]['pessoa_tipo']);
        $this->assertSame($this->projeto->id, $ficha['itens_projeto'][0]['pessoa_id']);
    }

    public function test_balcao_marca_item_do_projeto_e_ignora_item_no_papel_errado(): void
    {
        $foto = ItemConferencia::create([
            'nome' => 'Documento com foto', 'abrangencia' => 'pessoa', 'papeis' => ['aluno'],
            'no_credenciamento' => true, 'ativo' => true,
        ]);
        $relatorio = ItemConferencia::create([
            'nome' => 'Relatório impresso', 'abrangencia' => 'projeto', 'no_credenciamento' => true, 'ativo' => true,
        ]);
        $orientador = $this->projeto->user;

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}", ['marcacoes' => [
            ['documento_id' => $relatorio->id, 'pessoa_tipo' => 'projeto', 'pessoa_id' => $this->projeto->id, 'situacao' => 'presente'],
            // O documento com foto não é do orientador: descartado.
            ['documento_id' => $foto->id, 'pessoa_tipo' => 'orientador', 'pessoa_id' => $orientador->id, 'situacao' => 'presente'],
            // Projeto de outro: descartado.
            ['documento_id' => $relatorio->id, 'pessoa_tipo' => 'projeto', 'pessoa_id' => 999, 'situacao' => 'ausente'],
        ]])->assertOk();

        $marcas = CredenciamentoDocumento::all();
        $this->assertCount(1, $marcas);
        $this->assertSame('projeto', $marcas[0]->pessoa_tipo);
        $this->assertSame($relatorio->id, $marcas[0]->item_id);

        $ficha = $this->getJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}")->json('data');
        $this->assertSame('presente', $ficha['itens_projeto'][0]['situacao']);
    }

    // --- Checagem --------------------------------------------------------

    public function test_checagem_confere_item_do_projeto_e_item_de_cada_pessoa(): void
    {
        $banner = ItemConferencia::create(['nome' => 'Banner', 'abrangencia' => 'projeto', 'na_checagem' => true, 'ativo' => true]);
        $cracha = ItemConferencia::create([
            'nome' => 'Crachá no pescoço', 'abrangencia' => 'pessoa', 'papeis' => ['aluno'], 'na_checagem' => true, 'ativo' => true,
        ]);
        [$ana, $beto] = [$this->projeto->alunos()->where('nome', 'Ana')->first(), $this->projeto->alunos()->where('nome', 'Beto')->first()];

        $ficha = $this->getJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}")->assertOk()->json('data');
        $this->assertSame([$banner->id], array_column($ficha['itens'], 'id'));
        $this->assertSame(['Ana', 'Beto'], array_column($ficha['itens_pessoa'][0]['pessoas'], 'nome'));

        $this->postJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}", ['itens' => [
            ['item_id' => $banner->id, 'situacao' => 'presente'],
            ['item_id' => $cracha->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $ana->id, 'situacao' => 'presente'],
            ['item_id' => $cracha->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $beto->id, 'situacao' => 'ausente'],
            // O orientador não é papel do crachá: descartado.
            ['item_id' => $cracha->id, 'pessoa_tipo' => 'orientador', 'pessoa_id' => $this->projeto->user_id, 'situacao' => 'presente'],
        ]])->assertOk()
            ->assertJsonPath('data.itens.0.situacao', 'presente')
            ->assertJsonPath('data.itens_pessoa.0.pessoas.0.situacao', 'presente')
            ->assertJsonPath('data.itens_pessoa.0.pessoas.1.situacao', 'ausente');

        $this->assertSame(3, ChecagemEstandeItem::count());

        $espelho = $this->getJson('/api/v1/admin/presencial/checagem/espelho')->assertOk()->json('data.0');
        $this->assertSame(['id' => $cracha->id, 'nome' => 'Crachá no pescoço', 'total' => 2, 'presentes' => 1, 'ausentes' => 1], $espelho['itens_pessoa'][0]);
    }
}
