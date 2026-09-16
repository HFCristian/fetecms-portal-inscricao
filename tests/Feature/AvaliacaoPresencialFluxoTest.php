<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\RubricaPresencial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Avaliação presencial: o avaliador que confirmou presença avalia os estandes
 * no dia da feira, com rubrica própria e nota separada da avaliação online.
 */
class AvaliacaoPresencialFluxoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private User $avaliador;

    private Projeto $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDays(2),
        ]);

        $this->avaliador = $this->avaliadorPresencial('Ana Avaliadora');
        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
            'titulo' => 'Bioplástico de mandioca',
        ]);
    }

    private function avaliadorPresencial(string $nome, bool $aceitou = true): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'presencial' => $aceitou]);

        return $user;
    }

    private function publicar(array $projetos): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'vigente' => true, 'versao' => 1,
        ]);
        $lista->projetos()->attach(collect($projetos)->mapWithKeys(fn ($p) => [$p->id => ['manual' => false]])->all());

        return $lista;
    }

    /** @return array<string, int> */
    private function respostasCheias(int $ponto = 10): array
    {
        return array_fill_keys(RubricaPresencial::chaves(), $ponto);
    }

    public function test_quem_nao_aceitou_avaliar_presencialmente_nao_ve_estande(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs($this->avaliadorPresencial('Bruno', aceitou: false));

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.aceitou', false)
            ->assertJsonPath('data.motivo_fechado', 'Marque que quer avaliar presencialmente para receber os projetos do dia.')
            ->assertJsonCount(0, 'data.disponiveis');
    }

    public function test_avaliador_escolhe_um_estande_disponivel(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs($this->avaliador);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonPath('data.aberto', true)
            ->assertJsonCount(1, 'data.disponiveis')
            ->assertJsonPath('data.disponiveis.0.titulo', 'Bioplástico de mandioca');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'em_andamento');

        $this->assertDatabaseHas('avaliacoes_presenciais', [
            'projeto_id' => $this->projeto->id,
            'avaliador_id' => $this->avaliador->id,
            'designacao_manual' => false,
        ]);
    }

    public function test_a_nota_e_calculada_no_servidor_pela_rubrica_presencial(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs($this->avaliador);

        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->json('data.id');

        // Nota cheia: todas as perguntas no topo da escala.
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", [
            'respostas' => $this->respostasCheias(10),
            'comentario' => 'Equipe dominava o tema.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'concluida')
            ->assertJsonPath('data.nota', 10);

        // Metade da escala rende metade da nota.
        $outro = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
        ]);
        ListaFinal::vigente()->projetos()->attach($outro->id, ['manual' => false]);

        $id2 = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$outro->id}")->json('data.id');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id2}/concluir", [
            'respostas' => $this->respostasCheias(6),
        ])->assertOk()->assertJsonPath('data.nota', 6);
    }

    public function test_envio_incompleto_e_recusado(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs($this->avaliador);

        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")->json('data.id');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", [
            'respostas' => ['clareza' => 10],
        ])->assertStatus(422)->assertJsonValidationErrors('respostas');
    }

    public function test_rascunho_guarda_o_que_ja_foi_respondido(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs($this->avaliador);

        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")->json('data.id');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/rascunho", [
            'respostas' => ['clareza' => 8, 'inexistente' => 10],
            'comentario' => 'voltar depois',
        ])->assertOk()->assertJsonPath('data.respostas.clareza', 8);

        // Chave fora da rubrica é descartada, não guardada.
        $this->assertArrayNotHasKey('inexistente', AvaliacaoPresencial::find($id)->respostas);
        $this->assertNull(AvaliacaoPresencial::find($id)->nota);
    }

    public function test_avaliacao_enviada_nao_e_reenviada(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs($this->avaliador);

        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")->json('data.id');
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", ['respostas' => $this->respostasCheias()])
            ->assertOk();

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", ['respostas' => $this->respostasCheias(2)])
            ->assertStatus(422);

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto');
    }

    public function test_projeto_com_o_maximo_de_avaliacoes_sai_da_lista(): void
    {
        $this->publicar([$this->projeto]);

        // Três avaliadores ocupam o projeto.
        foreach (['Bruno', 'Carla', 'Diego'] as $nome) {
            $outro = $this->avaliadorPresencial($nome);
            Sanctum::actingAs($outro);
            $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")->assertOk();
        }

        Sanctum::actingAs($this->avaliador);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonCount(0, 'data.disponiveis');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto');
    }

    public function test_fora_do_evento_a_aba_fica_em_leitura(): void
    {
        $this->publicar([$this->projeto]);
        $this->edicao->update(['evento_de' => now()->addDays(5), 'evento_ate' => now()->addDays(7)]);
        Sanctum::actingAs($this->avaliador);

        $this->getJson('/api/v1/avaliador/presencial/avaliacoes')
            ->assertOk()
            ->assertJsonPath('data.aberto', false)
            ->assertJsonPath('data.motivo_fechado', 'A avaliação presencial abre no início do evento.');

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('periodo');
    }

    public function test_nao_mexe_na_avaliacao_online(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs($this->avaliador);

        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")->json('data.id');
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", ['respostas' => $this->respostasCheias()])
            ->assertOk();

        // A nota presencial vive na tabela dela: o ranking online não vê nada.
        $this->assertSame(0, Avaliacao::count());
        $this->assertSame(1, AvaliacaoPresencial::count());
    }

    public function test_avaliacao_de_outro_avaliador_e_barrada(): void
    {
        $this->publicar([$this->projeto]);
        $outro = $this->avaliadorPresencial('Bruno');
        Sanctum::actingAs($outro);
        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")->json('data.id');

        Sanctum::actingAs($this->avaliador);

        $this->getJson("/api/v1/avaliador/presencial/avaliacoes/{$id}")->assertForbidden();
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/rascunho", ['respostas' => []])->assertForbidden();
    }

    // --- Lado do admin ---

    public function test_admin_designa_estandes_a_quem_confirmou(): void
    {
        $this->publicar([$this->projeto]);
        $semConfirmar = $this->avaliadorPresencial('Bruno', aceitou: false);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/presencial/avaliacoes/designar', [
            'projeto_ids' => [$this->projeto->id],
            'avaliador_ids' => [$this->avaliador->id, $semConfirmar->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.designadas', 1);

        $this->assertDatabaseHas('avaliacoes_presenciais', [
            'projeto_id' => $this->projeto->id,
            'avaliador_id' => $this->avaliador->id,
            'designacao_manual' => true,
            'status' => StatusAvaliacao::Designada->value,
        ]);

        // Quem não confirmou é dito, não sumido.
        $this->assertSame(0, AvaliacaoPresencial::where('avaliador_id', $semConfirmar->id)->count());
    }

    public function test_designacao_repetida_e_avisada(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $payload = ['projeto_ids' => [$this->projeto->id], 'avaliador_ids' => [$this->avaliador->id]];

        $this->postJson('/api/v1/admin/presencial/avaliacoes/designar', $payload)->assertOk();

        $resposta = $this->postJson('/api/v1/admin/presencial/avaliacoes/designar', $payload)->assertOk();

        $this->assertSame(0, $resposta->json('data.designadas'));
        $this->assertStringContainsString('já está com ele', $resposta->json('data.ignoradas.0'));
    }

    public function test_designacao_do_admin_passa_por_cima_do_teto(): void
    {
        $this->publicar([$this->projeto]);

        foreach (['Bruno', 'Carla', 'Diego'] as $nome) {
            $outro = $this->avaliadorPresencial($nome);
            Sanctum::actingAs($outro);
            $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")->assertOk();
        }

        Sanctum::actingAs(User::factory()->admin()->create());

        // Quem designa à mão sabe que está pondo mais um ali.
        $this->postJson('/api/v1/admin/presencial/avaliacoes/designar', [
            'projeto_ids' => [$this->projeto->id],
            'avaliador_ids' => [$this->avaliador->id],
        ])->assertOk()->assertJsonPath('data.designadas', 1);

        $this->assertSame(4, AvaliacaoPresencial::where('projeto_id', $this->projeto->id)->count());
    }

    public function test_admin_retira_designacao_nao_concluida(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/presencial/avaliacoes/designar', [
            'projeto_ids' => [$this->projeto->id],
            'avaliador_ids' => [$this->avaliador->id],
        ])->assertOk();

        $avaliacao = AvaliacaoPresencial::sole();

        $this->deleteJson("/api/v1/admin/presencial/avaliacoes/{$avaliacao->id}")->assertOk();
        $this->assertSame(0, AvaliacaoPresencial::count());
    }

    public function test_avaliacao_concluida_nao_e_retirada(): void
    {
        $this->publicar([$this->projeto]);
        Sanctum::actingAs($this->avaliador);
        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$this->projeto->id}")->json('data.id');
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", ['respostas' => $this->respostasCheias()])
            ->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/v1/admin/presencial/avaliacoes/{$id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('avaliacao');
    }
}
