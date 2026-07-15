<?php

namespace Tests\Feature;

use App\Enums\StatusConversa;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Notifications\MensagemRespondida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_orientador_envia_mensagem_e_cria_conversa(): void
    {
        $user = User::factory()->create(); // orientador (padrão)
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/chat/mensagens', ['corpo' => 'Como envio o vídeo?'])
            ->assertOk()
            ->assertJsonPath('data.status', 'nao_visualizada')
            ->assertJsonPath('data.mensagens.0.autor', 'usuario')
            ->assertJsonPath('data.mensagens.0.corpo', 'Como envio o vídeo?');

        $this->assertDatabaseHas('conversas', ['user_id' => $user->id, 'status' => 'nao_visualizada']);
        $this->assertDatabaseHas('mensagens', ['autor' => 'usuario', 'corpo' => 'Como envio o vídeo?']);
    }

    public function test_avaliador_tambem_pode_usar_o_chat(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->postJson('/api/v1/chat/mensagens', ['corpo' => 'Olá, suporte!'])->assertOk();
    }

    public function test_mensagem_vazia_e_rejeitada(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/chat/mensagens', ['corpo' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('corpo');
    }

    public function test_corpo_nao_string_e_rejeitado(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/chat/mensagens', ['corpo' => ['injection']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('corpo');
    }

    public function test_usuario_nao_consegue_forjar_mensagem_do_suporte(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Mesmo enviando campos extras, o autor é fixado no servidor como 'usuario'.
        $this->postJson('/api/v1/chat/mensagens', [
            'corpo' => 'Tentando me passar pelo suporte',
            'autor' => 'suporte',
            'autor_user_id' => 999,
            'status' => 'respondida',
        ])->assertOk()->assertJsonPath('data.status', 'nao_visualizada');

        $this->assertDatabaseHas('mensagens', ['autor' => 'usuario', 'autor_user_id' => null]);
        $this->assertDatabaseMissing('mensagens', ['autor' => 'suporte']);
    }

    public function test_admin_nao_usa_o_chat_de_usuario(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/chat/mensagens', ['corpo' => 'Oi'])->assertStatus(403);
    }

    public function test_visitante_nao_acessa_o_chat(): void
    {
        $this->getJson('/api/v1/chat/conversa')->assertStatus(401);
    }

    public function test_abrir_o_chat_nao_cria_conversa(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/chat/conversa')
            ->assertOk()
            ->assertJsonPath('data.mensagens', [])
            ->assertJsonPath('data.id', null);

        // Abrir o chat NÃO cria conversa — só o envio da 1ª mensagem cria.
        $this->assertDatabaseCount('conversas', 0);
    }

    public function test_abrir_o_chat_nao_faz_o_usuario_aparecer_no_suporte(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/chat/conversa')->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/conversas')
            ->assertOk()
            ->assertJsonPath('meta.contagem.nao_respondidas', 0);
    }

    public function test_nova_mensagem_reabre_conversa_respondida(): void
    {
        $user = User::factory()->create();
        Conversa::factory()->status(StatusConversa::Respondida)->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/chat/mensagens', ['corpo' => 'Mais uma dúvida'])
            ->assertOk()
            ->assertJsonPath('data.status', 'nao_visualizada');
    }

    public function test_admin_lista_conversas_agrupadas_com_contagem(): void
    {
        Conversa::factory()->create(); // nao_visualizada
        Conversa::factory()->status(StatusConversa::EmTratamento)->create();
        Conversa::factory()->status(StatusConversa::Respondida)->create();
        Conversa::factory()->status(StatusConversa::Arquivada)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/conversas')
            ->assertOk()
            ->assertJsonPath('meta.contagem.nao_respondidas', 2)
            ->assertJsonPath('meta.contagem.respondidas', 1)
            ->assertJsonPath('meta.contagem.arquivadas', 1);
    }

    public function test_admin_abrir_conversa_marca_visualizada(): void
    {
        $conversa = Conversa::factory()->create(); // nao_visualizada
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/conversas/{$conversa->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'visualizada')
            ->assertJsonPath('data.suporte_visto_em', fn ($v) => $v !== null);

        $this->assertDatabaseHas('conversas', ['id' => $conversa->id, 'status' => 'visualizada']);
        $this->assertNotNull($conversa->fresh()->suporte_visto_em);
    }

    public function test_usuario_ao_abrir_marca_recibo_se_a_conversa_ja_existe(): void
    {
        $user = User::factory()->create();
        Conversa::factory()->create(['user_id' => $user->id]); // já enviou antes
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/chat/conversa')->assertOk();

        $this->assertNotNull(Conversa::firstWhere('user_id', $user->id)->usuario_visto_em);
    }

    public function test_recibo_do_admin_nao_e_marcado_so_porque_o_usuario_abriu(): void
    {
        // O usuário abrir o chat marca o lado dele (usuario_visto_em), não o do suporte.
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/chat/conversa')
            ->assertOk()
            ->assertJsonPath('data.suporte_visto_em', null);
    }

    public function test_admin_responde_marca_respondida_e_notifica_usuario(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $conversa = Conversa::factory()->status(StatusConversa::Visualizada)->create(['user_id' => $user->id]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/conversas/{$conversa->id}/responder", ['corpo' => 'Use o passo Resumo.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'respondida');

        $this->assertDatabaseHas('mensagens', [
            'conversa_id' => $conversa->id,
            'autor' => 'suporte',
            'autor_user_id' => $admin->id,
            'corpo' => 'Use o passo Resumo.',
        ]);

        Notification::assertSentTo($user, MensagemRespondida::class);
    }

    public function test_admin_atualiza_status_com_valores_validos(): void
    {
        $conversa = Conversa::factory()->status(StatusConversa::Visualizada)->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/conversas/{$conversa->id}/status", ['status' => 'arquivada'])
            ->assertOk()
            ->assertJsonPath('data.status', 'arquivada');

        $this->patchJson("/api/v1/admin/conversas/{$conversa->id}/status", ['status' => 'rascunho'])
            ->assertStatus(422);
    }

    public function test_orientador_nao_acessa_o_inbox_do_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/conversas')->assertStatus(403);
    }

    public function test_nao_lidas_e_zero_sem_conversa(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/chat/nao-lidas')
            ->assertOk()
            ->assertJsonPath('data.nao_lidas', false)
            ->assertJsonPath('data.total', 0);
    }

    public function test_nao_lidas_detecta_resposta_do_suporte_sem_marcar_leitura(): void
    {
        $user = User::factory()->create();
        $conversa = Conversa::factory()->create([
            'user_id' => $user->id,
            'usuario_visto_em' => now()->subHour(),
        ]);
        // Resposta do suporte criada DEPOIS do último "visto" do usuário.
        Mensagem::factory()->doSuporte()->create([
            'conversa_id' => $conversa->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/chat/nao-lidas')
            ->assertOk()
            ->assertJsonPath('data.nao_lidas', true)
            ->assertJsonPath('data.total', 1);

        // A consulta NÃO pode marcar como visto (senão a bolinha sumiria sozinha).
        $this->assertTrue($conversa->fresh()->usuario_visto_em->lessThan(now()->subMinutes(30)));
    }

    public function test_nao_lidas_e_falso_quando_usuario_ja_viu_a_resposta(): void
    {
        $user = User::factory()->create();
        $conversa = Conversa::factory()->create(['user_id' => $user->id]);
        Mensagem::factory()->doSuporte()->create([
            'conversa_id' => $conversa->id,
            'created_at' => now()->subHour(),
        ]);
        $conversa->update(['usuario_visto_em' => now()]); // viu depois da resposta

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/chat/nao-lidas')
            ->assertOk()
            ->assertJsonPath('data.nao_lidas', false);
    }

    public function test_admin_conta_conversas_nao_vistas(): void
    {
        Conversa::factory()->count(2)->create(); // nao_visualizada
        Conversa::factory()->status(StatusConversa::Visualizada)->create();
        Conversa::factory()->status(StatusConversa::Respondida)->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/conversas-nao-vistas')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_orientador_nao_acessa_o_contador_do_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/conversas-nao-vistas')->assertStatus(403);
    }

    public function test_usuario_dispensa_o_balao_do_chat(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/chat/dispensar-dica')
            ->assertOk()
            ->assertJsonPath('data.chat_dica_dispensada', true);

        $this->assertTrue($user->fresh()->chat_dica_dispensada);
    }

    public function test_perfil_expoe_o_estado_da_dica_do_chat(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.chat_dica_dispensada', false);
    }

    public function test_admin_nao_acessa_o_dispensar_dica(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/chat/dispensar-dica')->assertStatus(403);
    }
}
