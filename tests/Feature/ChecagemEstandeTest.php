<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SituacaoDocumento;
use App\Enums\TipoDocumento;
use App\Enums\Turno;
use App\Models\ChecagemEstande;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\EstandeProjeto;
use App\Models\ItemChecagemEstande;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\TurnoApresentacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Avaliação presencial → Checagem de estandes: a conferência no dia da feira,
 * o espelho e o catálogo do que se confere.
 */
class ChecagemEstandeTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Projeto $projeto;

    private ItemChecagemEstande $banner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDays(2),
        ]);

        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
            'titulo' => 'Bioplástico de mandioca',
        ]);

        $this->banner = ItemChecagemEstande::create(['nome' => 'Banner montado', 'ordem' => 1]);
    }

    private function publicar(array $projetos, bool $demo = false): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final',
            'vigente' => true, 'demo' => $demo, 'versao' => 1,
        ]);
        $lista->projetos()->attach(collect($projetos)->mapWithKeys(fn ($p) => [$p->id => ['manual' => false]])->all());

        return $lista;
    }

    private function comoAdmin(bool $demo = false): User
    {
        $admin = User::factory()->admin()->create(['is_demo' => $demo]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_sem_lista_final_nao_ha_estande_a_conferir(): void
    {
        $this->comoAdmin();

        $this->getJson('/api/v1/admin/presencial/config')
            ->assertOk()
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.motivo_fechado', 'Ainda não há lista final publicada — sem finalistas, não há estande a conferir.');

        $this->getJson('/api/v1/admin/presencial/checagem')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_lista_os_finalistas_com_o_estande_de_cada_um(): void
    {
        $this->publicar([$this->projeto]);
        EstandeProjeto::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $this->projeto->id,
            'turno' => Turno::A->value, 'numero' => 42,
        ]);
        TurnoApresentacao::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $this->projeto->id, 'turno' => Turno::A->value,
        ]);
        $this->comoAdmin();

        $this->getJson('/api/v1/admin/presencial/checagem')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('data.0.local.estande', 42)
            ->assertJsonPath('data.0.conferido', false)
            ->assertJsonPath('meta.resumo.finalistas', 1)
            ->assertJsonPath('meta.resumo.pendentes', 1);
    }

    public function test_registra_a_checagem_item_a_item(): void
    {
        $this->publicar([$this->projeto]);
        $admin = $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}", [
            'itens' => [['item_id' => $this->banner->id, 'situacao' => SituacaoDocumento::Ausente->value]],
            'observacao' => 'Equipe foi buscar o banner.',
        ])
            ->assertOk()
            ->assertJsonPath('data.itens.0.situacao', 'ausente')
            ->assertJsonPath('data.checagem.observacao', 'Equipe foi buscar o banner.')
            ->assertJsonPath('data.checagem.verificado_por', $admin->name);

        $this->assertDatabaseHas('checagem_estande_itens', [
            'item_nome' => 'Banner montado',
            'situacao' => 'ausente',
        ]);

        // A lista destaca o que ficou faltando.
        $this->getJson('/api/v1/admin/presencial/checagem')
            ->assertOk()
            ->assertJsonPath('data.0.conferido', true)
            ->assertJsonPath('data.0.ausentes', 1);
    }

    public function test_reconferir_substitui_a_marcacao_anterior(): void
    {
        $this->publicar([$this->projeto]);
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}", [
            'itens' => [['item_id' => $this->banner->id, 'situacao' => 'ausente']],
        ])->assertOk();

        $this->postJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}", [
            'itens' => [['item_id' => $this->banner->id, 'situacao' => 'presente']],
        ])->assertOk()->assertJsonPath('data.itens.0.situacao', 'presente');

        // Uma checagem por projeto, um item por linha: nada se acumula.
        $this->assertSame(1, ChecagemEstande::count());
        $this->assertSame(1, ChecagemEstande::first()->itens()->count());
    }

    public function test_termo_do_orientador_aparece_na_ficha_e_nao_e_editado_aqui(): void
    {
        $this->publicar([$this->projeto]);
        ProjetoDocumento::factory()->create([
            'projeto_id' => $this->projeto->id,
            'tipo' => TipoDocumento::TermoResponsabilidade->value,
            'nome_original' => 'termo-assinado.pdf',
            'assinatura_valida' => true,
            'assinatura' => ['motivo' => 'Assinatura digital ICP-Brasil conferida.'],
        ]);
        $this->comoAdmin();

        $this->getJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.termo.nome_original', 'termo-assinado.pdf')
            ->assertJsonPath('data.termo.assinatura_valida', true)
            ->assertJsonPath('data.termo.assinatura_motivo', 'Assinatura digital ICP-Brasil conferida.');
    }

    public function test_projeto_sem_termo_e_sinalizado_na_lista(): void
    {
        $this->publicar([$this->projeto]);
        $this->comoAdmin();

        $this->getJson('/api/v1/admin/presencial/checagem')
            ->assertOk()
            ->assertJsonPath('data.0.tem_termo', false);
    }

    public function test_espelho_traz_todos_com_itens_e_termo(): void
    {
        $this->publicar([$this->projeto]);
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}", [
            'itens' => [['item_id' => $this->banner->id, 'situacao' => 'presente']],
        ])->assertOk();

        $this->getJson('/api/v1/admin/presencial/checagem/espelho')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('data.0.itens.0.situacao', 'presente')
            ->assertJsonPath('data.0.termo', null);
    }

    public function test_fora_da_janela_do_evento_a_checagem_e_recusada(): void
    {
        $this->publicar([$this->projeto]);
        $this->edicao->update(['evento_de' => now()->addDays(5), 'evento_ate' => now()->addDays(7)]);
        $this->comoAdmin();

        // Leitura continua liberada — o que já foi conferido não some.
        $this->getJson('/api/v1/admin/presencial/checagem')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}", [
            'itens' => [['item_id' => $this->banner->id, 'situacao' => 'presente']],
        ])->assertStatus(422)->assertJsonValidationErrors('periodo');
    }

    public function test_modo_demo_nao_alcanca_finalista_de_verdade(): void
    {
        $oficial = $this->publicar([$this->projeto]);
        $demo = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value, 'is_demo' => true])->id,
        ]);
        $this->publicar([$demo], demo: true);
        $this->comoAdmin(demo: true);

        // Em modo de teste, o projeto oficial simplesmente não existe.
        $this->getJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}?teste=1")->assertNotFound();
        $this->getJson("/api/v1/admin/presencial/checagem/{$demo->id}?teste=1")->assertOk();

        // E fora dele, o de demonstração também não.
        $this->getJson("/api/v1/admin/presencial/checagem/{$demo->id}")->assertNotFound();

        $this->assertNotNull($oficial);
    }

    public function test_checagem_de_ensaio_fica_separada_da_de_verdade(): void
    {
        $demo = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value, 'is_demo' => true])->id,
        ]);
        $this->publicar([$demo], demo: true);
        $this->comoAdmin(demo: true);

        $this->postJson("/api/v1/admin/presencial/checagem/{$demo->id}?teste=1", [
            'itens' => [['item_id' => $this->banner->id, 'situacao' => 'presente']],
            'teste' => 1,
        ])->assertOk();

        $this->assertDatabaseHas('checagens_estande', ['projeto_id' => $demo->id, 'demo' => true]);
        $this->assertSame(0, ChecagemEstande::where('demo', false)->count());
    }

    public function test_catalogo_de_itens_e_gerido_pelo_admin(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/itens', ['nome' => 'Tomada disponível'])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        // Nome repetido é recusado.
        $this->postJson('/api/v1/admin/presencial/itens', ['nome' => 'Tomada disponível'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nome');

        $this->patchJson("/api/v1/admin/presencial/itens/{$this->banner->id}", ['ativo' => false])
            ->assertOk();

        $this->assertFalse(ItemChecagemEstande::find($this->banner->id)->ativo);
    }

    public function test_item_ja_conferido_nao_e_excluido(): void
    {
        $this->publicar([$this->projeto]);
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}", [
            'itens' => [['item_id' => $this->banner->id, 'situacao' => 'presente']],
        ])->assertOk();

        $this->deleteJson("/api/v1/admin/presencial/itens/{$this->banner->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        $this->assertDatabaseHas('itens_checagem_estande', ['id' => $this->banner->id]);
    }

    public function test_item_desativado_sai_das_fichas_novas(): void
    {
        $this->publicar([$this->projeto]);
        $this->comoAdmin();
        $this->patchJson("/api/v1/admin/presencial/itens/{$this->banner->id}", ['ativo' => false])->assertOk();

        $this->getJson("/api/v1/admin/presencial/checagem/{$this->projeto->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.itens');
    }

    public function test_orientacoes_do_avaliador_sao_salvas_na_edicao(): void
    {
        $this->comoAdmin();

        $this->patchJson('/api/v1/admin/presencial/informacoes', [
            'informacoes' => 'Chegue às 7h30 no ginásio.',
        ])->assertOk()->assertJsonPath('data.informacoes_avaliador', 'Chegue às 7h30 no ginásio.');

        $this->assertSame('Chegue às 7h30 no ginásio.', Edicao::first()->info_avaliacao_presencial);
    }

    public function test_admin_sem_a_aba_no_escopo_e_barrado(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/presencial/config')->assertForbidden();
    }
}
