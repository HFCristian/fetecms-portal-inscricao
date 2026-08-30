<?php

namespace Tests\Feature;

use App\Enums\StatusDestinatario;
use App\Enums\StatusFeedback;
use App\Enums\TipoPerguntaFeedback;
use App\Jobs\EnviarConviteFeedback;
use App\Mail\MensagemTransacional;
use App\Models\Feedback;
use App\Models\FeedbackDestinatario;
use App\Models\FeedbackParticipacao;
use App\Models\FeedbackResposta;
use App\Models\User;
use App\Services\FeedbackService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 86 — Comunicação → Feedback: o questionário dirigido a um recorte da
 * base, o balão de quem responde e os resultados anônimos.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'titulo' => 'Como foi a XVI FETECMS para você?',
            'descricao' => 'Sua opinião ajuda a organizar a próxima edição.',
            'publicos' => ['orientadores'],
            'perguntas' => [
                [
                    'tipo' => 'alternativa',
                    'enunciado' => 'Como você avalia a organização?',
                    'modelo' => 'satisfacao',
                    'obrigatoria' => true,
                ],
                [
                    'tipo' => 'dissertativa',
                    'enunciado' => 'O que podemos melhorar?',
                    'unidade' => 'palavras',
                    'minimo' => 2,
                    'maximo' => 50,
                    'obrigatoria' => false,
                ],
            ],
        ], $extra);
    }

    private function orientador(string $email = 'ori@escola.test'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    private function criar(array $extra = []): Feedback
    {
        return app(FeedbackService::class)->criar($this->payload($extra));
    }

    // ------------------------------------------------------------------ //
    // Criação e convites                                                  //
    // ------------------------------------------------------------------ //

    public function test_admin_cria_feedback_e_enfileira_um_convite_por_destinatario(): void
    {
        $this->orientador('a@escola.test');
        $this->orientador('b@escola.test');
        User::factory()->avaliador()->create(); // fora do público escolhido

        Sanctum::actingAs(User::factory()->admin()->create());
        Queue::fake();

        $this->postJson('/api/v1/admin/feedbacks', $this->payload())
            ->assertCreated()
            ->assertJsonPath('meta.message', 'Feedback publicado. Os convites estão saindo.');

        $feedback = Feedback::firstOrFail();
        $this->assertSame(StatusFeedback::Enviando, $feedback->status);
        $this->assertSame(2, $feedback->destinatarios()->count());
        Queue::assertPushed(EnviarConviteFeedback::class, 2);

        // O modelo de alternativas é COPIADO para a pergunta.
        $alternativa = $feedback->perguntas()->where('tipo', TipoPerguntaFeedback::Alternativa->value)->firstOrFail();
        $this->assertSame(
            ['Muito insatisfeito', 'Insatisfeito', 'Neutro', 'Satisfeito', 'Muito satisfeito'],
            $alternativa->opcoes,
        );
    }

    public function test_job_envia_o_convite_e_poe_o_pedido_no_ar(): void
    {
        $this->orientador();
        Mail::fake();

        $feedback = $this->criar();
        $destinatario = $feedback->destinatarios()->firstOrFail();

        $this->assertSame(StatusFeedback::Ativo, $feedback->fresh()->status);
        $this->assertSame(StatusDestinatario::Enviado, $destinatario->fresh()->status);
        Mail::assertSent(MensagemTransacional::class, 1);
    }

    public function test_email_malformado_vira_invalido_sem_barrar_o_disparo(): void
    {
        $this->orientador('ok@escola.test');
        User::factory()->create(['email' => 'nao-e-email']);
        Mail::fake();

        $feedback = $this->criar();

        $this->assertSame(1, $feedback->destinatarios()->where('status', StatusDestinatario::Invalido->value)->count());
        $this->assertSame(1, $feedback->destinatarios()->where('status', StatusDestinatario::Enviado->value)->count());
        // Um inválido não impede o pedido de entrar no ar.
        $this->assertSame(StatusFeedback::Ativo, $feedback->fresh()->status);
    }

    public function test_conta_demo_e_inativa_nao_recebem_convite(): void
    {
        $this->orientador('ativo@escola.test');
        User::factory()->create(['email' => 'demo@escola.test', 'is_demo' => true]);
        User::factory()->create(['email' => 'inativo@escola.test', 'is_active' => false]);
        Mail::fake();

        $feedback = $this->criar();

        $this->assertSame(['ativo@escola.test'], $feedback->destinatarios()->pluck('email')->all());
    }

    public function test_publico_sem_ninguem_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/feedbacks', $this->payload(['publicos' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('publicos');
    }

    public function test_alternativa_sem_opcoes_nem_modelo_e_recusada(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/feedbacks', $this->payload(['perguntas' => [[
            'tipo' => 'alternativa',
            'enunciado' => 'Escolha uma',
            'opcoes' => ['Só uma'],
        ]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('perguntas.0.opcoes');
    }

    public function test_dissertativa_com_maximo_menor_que_o_minimo_e_recusada(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/feedbacks', $this->payload(['perguntas' => [[
            'tipo' => 'dissertativa',
            'enunciado' => 'Comente',
            'minimo' => 100,
            'maximo' => 10,
        ]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('perguntas.0.maximo');
    }

    // ------------------------------------------------------------------ //
    // O balão de quem responde                                            //
    // ------------------------------------------------------------------ //

    public function test_quem_esta_no_publico_ve_o_balao_e_quem_nao_esta_nao_ve(): void
    {
        $orientador = $this->orientador();
        $avaliador = User::factory()->avaliador()->create();
        Mail::fake();
        $feedback = $this->criar();

        Sanctum::actingAs($orientador);
        $this->getJson('/api/v1/feedbacks/pendente')
            ->assertOk()
            ->assertJsonPath('data.id', $feedback->id)
            ->assertJsonPath('data.titulo', 'Como foi a XVI FETECMS para você?')
            ->assertJsonCount(2, 'data.perguntas');

        Sanctum::actingAs($avaliador);
        $this->getJson('/api/v1/feedbacks/pendente')->assertOk()->assertJsonPath('data', null);
    }

    public function test_dispensar_tira_do_balao_mas_deixa_na_lista_do_perfil(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();

        Sanctum::actingAs($orientador);
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/dispensar")->assertOk();

        // Fora do balão…
        $this->getJson('/api/v1/feedbacks/pendente')->assertOk()->assertJsonPath('data', null);
        // …mas ainda respondível pelo perfil.
        $this->getJson('/api/v1/feedbacks')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_quem_respondeu_nao_ve_mais_em_lugar_nenhum(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();
        [$alternativa, $dissertativa] = $feedback->perguntas->all();

        Sanctum::actingAs($orientador);
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", ['respostas' => [
            $alternativa->id => 'Satisfeito',
            $dissertativa->id => 'Mais tempo de apresentação, por favor.',
        ]])->assertOk();

        $this->getJson('/api/v1/feedbacks/pendente')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/v1/feedbacks')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_nao_responde_duas_vezes(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();
        $alternativa = $feedback->perguntas->first();

        Sanctum::actingAs($orientador);
        $corpo = ['respostas' => [$alternativa->id => 'Satisfeito']];

        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", $corpo)->assertOk();
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", $corpo)->assertStatus(422);

        $this->assertSame(1, FeedbackResposta::where('pergunta_id', $alternativa->id)->count());
    }

    public function test_feedback_encerrado_nao_recebe_mais_resposta(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();
        app(FeedbackService::class)->encerrar($feedback);

        Sanctum::actingAs($orientador);
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", [
            'respostas' => [$feedback->perguntas->first()->id => 'Satisfeito'],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------ //
    // Validação das respostas                                             //
    // ------------------------------------------------------------------ //

    public function test_alternativa_so_aceita_uma_das_opcoes(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();
        $alternativa = $feedback->perguntas->first();

        Sanctum::actingAs($orientador);
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", [
            'respostas' => [$alternativa->id => 'Mais ou menos'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors("respostas.{$alternativa->id}");
    }

    public function test_dissertativa_respeita_o_minimo_e_o_maximo_em_palavras(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();
        [$alternativa, $dissertativa] = $feedback->perguntas->all();

        Sanctum::actingAs($orientador);

        // Uma palavra só, com mínimo de duas.
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", ['respostas' => [
            $alternativa->id => 'Satisfeito',
            $dissertativa->id => 'Nada',
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors("respostas.{$dissertativa->id}");

        // Acima do máximo de 50 palavras.
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", ['respostas' => [
            $alternativa->id => 'Satisfeito',
            $dissertativa->id => str_repeat('palavra ', 60),
        ]])->assertStatus(422);
    }

    public function test_pergunta_obrigatoria_em_branco_e_recusada(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();
        $alternativa = $feedback->perguntas->first();

        Sanctum::actingAs($orientador);
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", ['respostas' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors("respostas.{$alternativa->id}");
    }

    public function test_pergunta_opcional_pode_ficar_em_branco(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();
        $alternativa = $feedback->perguntas->first();

        Sanctum::actingAs($orientador);
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", [
            'respostas' => [$alternativa->id => 'Satisfeito'],
        ])->assertOk();

        $this->assertSame(1, FeedbackResposta::where('feedback_id', $feedback->id)->count());
    }

    // ------------------------------------------------------------------ //
    // Anonimato e resultados                                              //
    // ------------------------------------------------------------------ //

    /**
     * O ponto central da feature: dá para saber QUANTOS responderam, nunca QUEM
     * respondeu o quê.
     */
    public function test_resposta_nao_guarda_o_autor(): void
    {
        $orientador = $this->orientador();
        Mail::fake();
        $feedback = $this->criar();
        [$alternativa, $dissertativa] = $feedback->perguntas->all();

        Sanctum::actingAs($orientador);
        $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", ['respostas' => [
            $alternativa->id => 'Satisfeito',
            $dissertativa->id => 'Mais tempo de apresentação.',
        ]])->assertOk();

        // A tabela de respostas não tem sequer a coluna de dono…
        $this->assertNotContains('user_id', Schema::getColumnListing('feedback_respostas'));

        // …e as duas respostas do mesmo preenchimento compartilham um `envio`
        // aleatório, que não leva a ninguém.
        $envios = FeedbackResposta::where('feedback_id', $feedback->id)->pluck('envio')->unique();
        $this->assertCount(1, $envios);

        // A participação sabe que ele respondeu — só isso.
        $participacao = FeedbackParticipacao::where('feedback_id', $feedback->id)
            ->where('user_id', $orientador->id)->firstOrFail();
        $this->assertNotNull($participacao->respondido_em);
    }

    public function test_resultados_contam_alternativas_e_listam_dissertativas(): void
    {
        $a = $this->orientador('a@escola.test');
        $b = $this->orientador('b@escola.test');
        Mail::fake();
        $feedback = $this->criar();
        [$alternativa, $dissertativa] = $feedback->perguntas->all();

        foreach ([[$a, 'Satisfeito', 'Mais tempo de apresentação.'], [$b, 'Satisfeito', 'Melhorar o café.']] as [$user, $opcao, $texto]) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/feedbacks/{$feedback->id}/responder", ['respostas' => [
                $alternativa->id => $opcao,
                $dissertativa->id => $texto,
            ]])->assertOk();
        }

        Sanctum::actingAs(User::factory()->admin()->create());
        $resp = $this->getJson("/api/v1/admin/feedbacks/{$feedback->id}")->assertOk();

        $this->assertSame(2, $resp->json('data.resumo.responderam'));
        $this->assertSame(2, $resp->json('data.resumo.convidados'));
        // Todo mundo respondeu: o JSON serializa 100.0 como 100.
        $this->assertEquals(100, $resp->json('data.resumo.taxa_resposta'));

        // A alternativa traz TODAS as opções, inclusive as zeradas.
        $primeira = $resp->json('data.perguntas.0');
        $this->assertCount(5, $primeira['opcoes']);
        $satisfeito = collect($primeira['opcoes'])->firstWhere('opcao', 'Satisfeito');
        $this->assertSame(2, $satisfeito['total']);
        $this->assertEquals(100, $satisfeito['percentual']);
        $this->assertSame(0, collect($primeira['opcoes'])->firstWhere('opcao', 'Neutro')['total']);

        // A dissertativa traz os textos, sem nome de ninguém.
        $segunda = $resp->json('data.perguntas.1');
        $this->assertSame(2, $segunda['respostas']);
        $this->assertEqualsCanonicalizing(
            ['Mais tempo de apresentação.', 'Melhorar o café.'],
            $segunda['textos'],
        );
    }

    public function test_relatorio_de_envio_e_reenvio_das_falhas(): void
    {
        $this->orientador('a@escola.test');
        Mail::fake();
        $feedback = $this->criar();

        // Simula uma recusa do servidor.
        $feedback->destinatarios()->update([
            'status' => StatusDestinatario::Falha->value,
            'erro' => 'Caixa cheia.',
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/feedbacks/{$feedback->id}/destinatarios")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'falha')
            ->assertJsonPath('data.0.erro', 'Caixa cheia.');

        Queue::fake();
        $this->postJson("/api/v1/admin/feedbacks/{$feedback->id}/reenviar-falhas")
            ->assertOk()
            ->assertJsonPath('meta.message', '1 convite(s) reenviado(s).');

        Queue::assertPushed(EnviarConviteFeedback::class, 1);
        $this->assertSame(
            StatusDestinatario::Pendente,
            FeedbackDestinatario::where('feedback_id', $feedback->id)->firstOrFail()->status,
        );
    }

    public function test_lista_de_pedidos_mostra_convidados_e_respostas(): void
    {
        $this->orientador();
        Mail::fake();
        $this->criar();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/feedbacks')
            ->assertOk()
            ->assertJsonPath('data.0.titulo', 'Como foi a XVI FETECMS para você?')
            ->assertJsonPath('data.0.perguntas', 2)
            ->assertJsonPath('data.0.convidados', 1)
            ->assertJsonPath('data.0.respostas', 0)
            ->assertJsonPath('data.0.publicos', ['Todos os orientadores']);
    }

    public function test_feedback_e_so_para_admin(): void
    {
        Sanctum::actingAs($this->orientador());

        $this->getJson('/api/v1/admin/feedbacks')->assertForbidden();
        $this->postJson('/api/v1/admin/feedbacks', $this->payload())->assertForbidden();
    }
}
