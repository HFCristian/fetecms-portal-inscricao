<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\SituacaoDocumento;
use App\Enums\TipoDocumento;
use App\Models\Aluno;
use App\Models\CredenciamentoDocumento;
use App\Models\Edicao;
use App\Models\ItemConferencia;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 175 — o termo de uso de imagem enviado online já chega marcado na
 * ficha do credenciamento, estudante por estudante.
 */
class CredenciamentoTermoImagemTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Projeto $projeto;

    private Aluno $ana;

    private Aluno $beto;

    private ItemConferencia $termo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDay(),
        ]);

        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'titulo' => 'Bioplástico',
            'categoria' => Categoria::Fetecms, 'edicao_id' => $this->edicao->id,
        ]);
        $this->ana = Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Ana']);
        $this->beto = Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Beto']);

        ListaFinal::create(['edicao_id' => $this->edicao->id, 'nome' => 'Oficial', 'vigente' => true, 'versao' => 1])
            ->projetos()->attach($this->projeto->id, ['manual' => false]);

        $this->termo = ItemConferencia::where('chave', ItemConferencia::CHAVE_TERMO_IMAGEM)->firstOrFail();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /** Um arquivo do termo de imagem enviado online, valendo para os estudantes dados. */
    private function enviado(array $alunos, string $nome = 'termo.jpg'): ProjetoDocumento
    {
        $documento = ProjetoDocumento::factory()->create([
            'projeto_id' => $this->projeto->id, 'tipo' => TipoDocumento::TermoUsoImagem->value, 'nome_original' => $nome,
        ]);
        // O arquivo enviado antes vale para pessoas (Sprint 183): aqui, estudantes.
        $documento->forceFill(['item_conferencia_id' => ItemConferencia::where('chave', ItemConferencia::CHAVE_TERMO_IMAGEM)->value('id')])->save();
        foreach ($alunos as $aluno) {
            $documento->pessoas()->create(['pessoa_tipo' => 'aluno', 'pessoa_id' => $aluno->id]);
        }

        return $documento;
    }

    /** O item do termo de imagem de cada estudante na ficha, por nome. */
    private function itensDaFicha(): array
    {
        $pessoas = $this->getJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}")->assertOk()->json('data.pessoas');

        return collect($pessoas)
            ->where('tipo', 'aluno')
            ->mapWithKeys(fn ($p) => [$p['nome'] => collect($p['documentos'])->firstWhere('id', $this->termo->id)])
            ->all();
    }

    public function test_o_item_do_sistema_existe_para_o_estudante(): void
    {
        $this->assertSame(['aluno'], $this->termo->papeis);
        $this->assertSame('Termo de uso de imagem', $this->termo->nome);
        $this->assertTrue($this->termo->ativo);
        $this->assertTrue($this->termo->no_credenciamento);
        $this->assertTrue($this->termo->receber_antes);
    }

    public function test_estudante_coberto_chega_marcado_e_o_outro_em_branco(): void
    {
        $arquivo = $this->enviado([$this->ana], 'ana.jpg');

        $itens = $this->itensDaFicha();

        $this->assertSame('presente', $itens['Ana']['situacao']);
        $this->assertTrue($itens['Ana']['pre_marcado']);
        $this->assertSame([$arquivo->id], array_column($itens['Ana']['online'], 'id'));
        $this->assertSame('ana.jpg', $itens['Ana']['online'][0]['nome_original']);

        $this->assertNull($itens['Beto']['situacao']);
        $this->assertFalse($itens['Beto']['pre_marcado']);
        $this->assertSame([], $itens['Beto']['online']);
    }

    public function test_marcacao_do_balcao_vale_mais_que_o_envio_online(): void
    {
        $this->enviado([$this->ana, $this->beto]);

        // O atendente achou o arquivo da Ana ilegível e marcou ausente.
        $this->postJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}/rascunho", [
            'marcacoes' => [[
                'documento_id' => $this->termo->id, 'pessoa_tipo' => 'aluno',
                'pessoa_id' => $this->ana->id, 'situacao' => SituacaoDocumento::Ausente->value,
            ]],
        ])->assertOk();

        $itens = $this->itensDaFicha();

        $this->assertSame('ausente', $itens['Ana']['situacao']);
        $this->assertFalse($itens['Ana']['pre_marcado']);
        // O arquivo continua à mão, para conferir de novo.
        $this->assertCount(1, $itens['Ana']['online']);
        $this->assertSame('presente', $itens['Beto']['situacao']);
    }

    public function test_concluir_grava_a_marcacao_que_veio_do_envio_online(): void
    {
        $this->enviado([$this->ana]);

        $this->postJson("/api/v1/admin/credenciamento/projetos/{$this->projeto->id}", [
            'marcacoes' => [[
                'documento_id' => $this->termo->id, 'pessoa_tipo' => 'aluno',
                'pessoa_id' => $this->ana->id, 'situacao' => 'presente',
            ]],
        ])->assertOk();

        $this->assertSame(
            SituacaoDocumento::Presente,
            CredenciamentoDocumento::where('item_id', $this->termo->id)->firstOrFail()->situacao,
        );
    }

    public function test_lista_do_balcao_mostra_quantos_ja_enviaram(): void
    {
        $this->enviado([$this->ana]);

        $linha = $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertOk()->json('data.0');

        $this->assertSame(['situacao' => 'parcial', 'situacao_label' => 'Parcial', 'cobertos' => 1, 'total' => 2], $linha['termo_imagem']);
    }

    public function test_item_do_sistema_nao_pode_ser_excluido_mas_pode_ser_desativado(): void
    {
        $this->deleteJson("/api/v1/admin/itens-conferencia/{$this->termo->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        $this->patchJson("/api/v1/admin/itens-conferencia/{$this->termo->id}", ['ativo' => false])->assertOk();
        $this->assertFalse($this->termo->fresh()->ativo);

        $itens = $this->getJson('/api/v1/admin/itens-conferencia')->assertOk()->json('data.itens');
        $this->assertSame('termo_uso_imagem', collect($itens)->firstWhere('id', $this->termo->id)['chave']);
    }
}
