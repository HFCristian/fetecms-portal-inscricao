<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoDocumento;
use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\Edicao;
use App\Models\ItemConferencia;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 183 — qualquer item da lista única marcado "receber antes" ganha a
 * sua seção na aba Documentos do orientador, não só os dois termos.
 */
class EnviosAntecipadosTest extends TestCase
{
    use RefreshDatabase;

    private User $orientador;

    private Projeto $projeto;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subDay(), 'evento_ate' => now()->addDays(3),
        ]);
        $this->orientador = User::factory()->create(['role' => Role::Orientador->value, 'name' => 'Marta']);
        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => $this->orientador->id, 'titulo' => 'Bioplástico', 'edicao_id' => $edicao->id,
        ]);
        Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Ana']);
        Coorientador::create(['projeto_id' => $this->projeto->id, 'nome' => 'Caio', 'email' => 'c@x.test', 'cpf' => '52998224725']);

        ListaFinal::create(['edicao_id' => $edicao->id, 'nome' => 'Final', 'vigente' => true, 'versao' => 1])
            ->projetos()->attach($this->projeto->id, ['manual' => false]);

        Sanctum::actingAs($this->orientador);
    }

    private function item(array $over = []): ItemConferencia
    {
        return ItemConferencia::create(array_merge([
            'nome' => 'Declaração da escola', 'abrangencia' => 'pessoa', 'papeis' => ['orientador', 'coorientador'],
            'no_credenciamento' => true, 'receber_antes' => true, 'ativo' => true,
        ], $over));
    }

    private function enviar(ItemConferencia $item, array $pessoas = [])
    {
        return $this->post("/api/v1/documentos-presenciais/projetos/{$this->projeto->id}/itens/{$item->id}", [
            'file' => UploadedFile::fake()->create('declaracao.pdf', 100, 'application/pdf'),
            'pessoas' => $pessoas,
        ], ['Accept' => 'application/json']);
    }

    public function test_item_da_organizacao_aparece_na_aba_com_as_pessoas_dos_papeis_dele(): void
    {
        $item = $this->item();

        $itens = $this->getJson('/api/v1/documentos-presenciais')->assertOk()->json('data.projetos.0.itens');
        $secao = collect($itens)->firstWhere('id', $item->id);

        $this->assertSame(['Marta', 'Caio'], array_column($secao['pessoas'], 'nome'));
        $this->assertSame(['orientador', 'coorientador'], array_column($secao['pessoas'], 'tipo'));
        // Os dois termos do sistema também estão lá.
        $this->assertEqualsCanonicalizing(
            ['termo_responsabilidade', 'termo_uso_imagem'],
            array_values(array_filter(array_column($itens, 'chave'))),
        );
    }

    public function test_envia_para_orientador_e_coorientador_e_o_tipo_e_documento_do_evento(): void
    {
        $item = $this->item();
        $coorientador = $this->projeto->coorientador;

        $this->enviar($item, ["orientador:{$this->orientador->id}", "coorientador:{$coorientador->id}"])
            ->assertCreated()
            ->assertJsonPath('data.situacao', 'completo')
            ->assertJsonPath('meta.message', 'Declaração da escola: arquivo enviado.');

        $documento = ProjetoDocumento::firstOrFail();
        $this->assertSame(TipoDocumento::DocumentoEvento, $documento->tipo);
        $this->assertSame($item->id, $documento->item_conferencia_id);
        $this->assertSame(2, $documento->pessoas()->count());
    }

    public function test_pessoa_fora_dos_papeis_do_item_e_recusada(): void
    {
        $item = $this->item();
        $aluno = $this->projeto->alunos()->first();

        $this->enviar($item, ["aluno:{$aluno->id}"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pessoas');
    }

    public function test_item_do_projeto_nao_pede_pessoa(): void
    {
        $item = $this->item(['nome' => 'Relatório', 'abrangencia' => 'projeto', 'papeis' => null]);

        $this->enviar($item)->assertCreated()->assertJsonPath('data.situacao_label', 'Enviado');
    }

    public function test_item_que_nao_e_recebido_antes_ou_desativado_nao_aceita_arquivo(): void
    {
        $this->enviar($this->item(['nome' => 'Banner', 'receber_antes' => false]))->assertNotFound();
        $this->enviar($this->item(['nome' => 'Antigo', 'ativo' => false]))->assertNotFound();
    }

    public function test_arquivo_enviado_impede_excluir_o_item_na_parametrizacao(): void
    {
        $item = $this->item(['nome' => 'Relatório', 'abrangencia' => 'projeto', 'papeis' => null]);
        $this->enviar($item)->assertCreated();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->deleteJson("/api/v1/admin/itens-conferencia/{$item->id}")->assertStatus(422)->assertJsonValidationErrors('item');
    }

    /** Sprint 184: o que chegou antes já vem marcado no balcão e na checagem, para qualquer item. */
    public function test_balcao_e_checagem_ja_recebem_o_item_marcado(): void
    {
        $declaracao = $this->item(['na_checagem' => true]);
        $relatorio = $this->item(['nome' => 'Relatório', 'abrangencia' => 'projeto', 'papeis' => null, 'na_checagem' => true]);
        $this->enviar($declaracao, ["orientador:{$this->orientador->id}"])->assertCreated();
        $this->enviar($relatorio)->assertCreated();

        Sanctum::actingAs(User::factory()->admin()->create());

        $ficha = $this->getJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}")->assertOk()->json('data');
        $orientador = collect($ficha['pessoas'])->firstWhere('tipo', 'orientador');
        $coorientador = collect($ficha['pessoas'])->firstWhere('tipo', 'coorientador');
        $doc = fn (array $pessoa) => collect($pessoa['documentos'])->firstWhere('id', $declaracao->id);

        $this->assertSame('presente', $doc($orientador)['situacao']);
        $this->assertTrue($doc($orientador)['pre_marcado']);
        $this->assertSame('declaracao.pdf', $doc($orientador)['online'][0]['nome_original']);
        // O coorientador não foi coberto pelo arquivo: fica para o balcão.
        $this->assertNull($doc($coorientador)['situacao']);

        $doProjeto = collect($ficha['itens_projeto'])->firstWhere('id', $relatorio->id);
        $this->assertSame('presente', $doProjeto['situacao']);
        $this->assertTrue($doProjeto['pre_marcado']);

        $checagem = $this->getJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}")->assertOk()->json('data');
        $this->assertSame('presente', collect($checagem['itens'])->firstWhere('id', $relatorio->id)['situacao']);
        $pessoas = collect(collect($checagem['itens_pessoa'])->firstWhere('id', $declaracao->id)['pessoas'])->keyBy('tipo');
        $this->assertSame('presente', $pessoas['orientador']['situacao']);
        $this->assertNull($pessoas['coorientador']['situacao']);
    }
}
