<?php

namespace Tests\Feature;

use App\Enums\PublicoMala;
use App\Enums\StatusAvaliacao;
use App\Enums\StatusDestinatario;
use App\Enums\StatusMala;
use App\Jobs\EnviarMalaDireta;
use App\Mail\MalaDiretaMensagem;
use App\Models\Avaliacao;
use App\Models\MalaDireta;
use App\Models\MalaDiretaDestinatario;
use App\Models\Projeto;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Mala direta do admin: públicos, prévia, disparo pela fila e relatório.
 */
class AdminMalaDiretaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Payload mínimo de um disparo. */
    private function mensagem(array $over = []): array
    {
        return array_merge([
            'nome' => 'Lembrete de prazo',
            'justificativa' => 'O prazo de submissão fecha na sexta-feira.',
            'solicitante' => 'Coordenação da FETECMS',
            'assunto' => 'Prazo de submissão',
            'corpo' => "Olá, {{nome}}!\n\nO prazo termina sexta.",
        ], $over);
    }

    public function test_publico_todos_ignora_admin_conta_inativa_e_demo(): void
    {
        $orientador = User::factory()->create();
        $avaliador = User::factory()->avaliador()->create();
        User::factory()->create(['is_active' => false]);
        User::factory()->avaliador()->create(['is_demo' => true]);
        $this->admin(); // admin não entra em "todos os usuários"

        $resposta = $this->postJson('/api/v1/admin/mala-direta/previa', [
            'publicos' => [PublicoMala::Todos->value],
        ])->assertOk();

        $emails = collect($resposta->json('data'))->pluck('email')->all();
        $this->assertEqualsCanonicalizing([$orientador->email, $avaliador->email], $emails);
        $this->assertSame(2, $resposta->json('meta.total'));
        $this->assertSame(0, $resposta->json('meta.invalidos'));
    }

    public function test_publicos_de_orientador_separam_rascunho_de_submetido(): void
    {
        $comRascunho = User::factory()->create();
        Projeto::factory()->create(['user_id' => $comRascunho->id]);

        $comSubmetido = User::factory()->create();
        Projeto::factory()->submetido()->create(['user_id' => $comSubmetido->id]);

        User::factory()->create(); // sem projeto: fora dos dois recortes
        $this->admin();

        $rascunho = $this->postJson('/api/v1/admin/mala-direta/previa', [
            'publicos' => [PublicoMala::OrientadoresRascunho->value],
        ])->assertOk();
        $this->assertSame([$comRascunho->email], collect($rascunho->json('data'))->pluck('email')->all());

        $submetidos = $this->postJson('/api/v1/admin/mala-direta/previa', [
            'publicos' => [PublicoMala::OrientadoresSubmetidos->value],
        ])->assertOk();
        $this->assertSame([$comSubmetido->email], collect($submetidos->json('data'))->pluck('email')->all());
    }

    public function test_publicos_de_avaliador_separam_pendente_de_concluida(): void
    {
        $projeto = Projeto::factory()->submetido()->create(['user_id' => User::factory()->create()->id]);

        $pendente = User::factory()->avaliador()->create();
        Avaliacao::create(['projeto_id' => $projeto->id, 'avaliador_id' => $pendente->id, 'status' => StatusAvaliacao::EmAndamento]);

        $concluiu = User::factory()->avaliador()->create();
        Avaliacao::create(['projeto_id' => $projeto->id, 'avaliador_id' => $concluiu->id, 'status' => StatusAvaliacao::Concluida]);

        // Só designada (nem abriu) não conta como pendente — regra combinada com o cliente.
        $soDesignado = User::factory()->avaliador()->create();
        Avaliacao::create(['projeto_id' => $projeto->id, 'avaliador_id' => $soDesignado->id, 'status' => StatusAvaliacao::Designada]);
        $this->admin();

        $pendentes = $this->postJson('/api/v1/admin/mala-direta/previa', [
            'publicos' => [PublicoMala::AvaliadoresPendentes->value],
        ])->assertOk();
        $this->assertSame([$pendente->email], collect($pendentes->json('data'))->pluck('email')->all());

        $concluidas = $this->postJson('/api/v1/admin/mala-direta/previa', [
            'publicos' => [PublicoMala::AvaliadoresConcluidas->value],
        ])->assertOk();
        $this->assertSame([$concluiu->email], collect($concluidas->json('data'))->pluck('email')->all());
    }

    public function test_previa_deduplica_por_email_guardando_todas_as_origens(): void
    {
        $orientador = User::factory()->create(['name' => 'Ana Souza']);
        Projeto::factory()->create(['user_id' => $orientador->id]);
        Projeto::factory()->submetido()->create(['user_id' => $orientador->id]);
        $this->admin();

        $resposta = $this->postJson('/api/v1/admin/mala-direta/previa', [
            'publicos' => [
                PublicoMala::OrientadoresRascunho->value,
                PublicoMala::OrientadoresSubmetidos->value,
            ],
            'destinatarios' => [['email' => strtoupper($orientador->email)]],
        ])->assertOk();

        $this->assertSame(1, $resposta->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            ['orientadores_rascunho', 'orientadores_submetidos', 'personalizado'],
            $resposta->json('data.0.origens'),
        );
        // Cada público continua mostrando o próprio tamanho, antes da dedup.
        $this->assertSame(1, $resposta->json('meta.por_publico.orientadores_rascunho'));
        $this->assertSame(2, $resposta->json('data.0.projetos_total'));
    }

    public function test_previa_marca_email_personalizado_invalido(): void
    {
        $this->admin();

        $resposta = $this->postJson('/api/v1/admin/mala-direta/previa', [
            'destinatarios' => [
                ['email' => 'valido@escola.test', 'nome' => 'Beto'],
                ['email' => 'sem-arroba'],
            ],
        ])->assertOk();

        $this->assertSame(2, $resposta->json('meta.total'));
        $this->assertSame(1, $resposta->json('meta.validos'));
        $this->assertSame(1, $resposta->json('meta.invalidos'));
        // Inválidos vêm primeiro: é o que o admin precisa corrigir.
        $this->assertSame('sem-arroba', $resposta->json('data.0.email'));
        $this->assertSame(StatusDestinatario::Invalido->value, $resposta->json('data.0.status'));
    }

    public function test_previa_exige_publico_ou_lista_personalizada(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/mala-direta/previa', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('publicos');
    }

    public function test_previa_exporta_csv_com_projetos_do_orientador(): void
    {
        $orientador = User::factory()->create(['name' => 'Ana Souza']);
        Projeto::factory()->create(['user_id' => $orientador->id, 'titulo' => 'Bioplástico de Mandioca']);
        Projeto::factory()->create(['user_id' => $orientador->id, 'titulo' => 'Horta Vertical']);
        $this->admin();

        $resposta = $this->post('/api/v1/admin/mala-direta/previa/exportar', [
            'publicos' => [PublicoMala::Orientadores->value],
        ])->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $resposta->getContent();
        $this->assertStringContainsString('Ana Souza', $csv);
        $this->assertStringContainsString($orientador->email, $csv);
        $this->assertStringContainsString('Bioplástico de Mandioca | Horta Vertical', $csv);
        $this->assertStringContainsString(';2;', $csv);
    }

    public function test_disparo_envia_para_cada_destinatario_e_personaliza_o_corpo(): void
    {
        Mail::fake();
        $orientador = User::factory()->create(['name' => 'Ana Souza']);
        Projeto::factory()->create(['user_id' => $orientador->id]);
        $this->admin();

        $resposta = $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'publicos' => [PublicoMala::OrientadoresRascunho->value],
            'destinatarios' => [['email' => 'externo@parceiro.test', 'nome' => 'Beto Lima']],
        ]))->assertCreated();

        $this->assertSame(2, $resposta->json('data.totais.total'));

        Mail::assertSent(MalaDiretaMensagem::class, 2);
        Mail::assertSent(
            MalaDiretaMensagem::class,
            fn (MalaDiretaMensagem $mail) => $mail->hasTo($orientador->email)
                && str_contains($mail->corpo, 'Olá, Ana!')
                && $mail->envelope()->subject === 'Prazo de submissão',
        );
        Mail::assertSent(
            MalaDiretaMensagem::class,
            fn (MalaDiretaMensagem $mail) => $mail->hasTo('externo@parceiro.test')
                && str_contains($mail->corpo, 'Olá, Beto!'),
        );

        $mala = MalaDireta::firstOrFail();
        $this->assertSame(StatusMala::Concluida, $mala->status);
        $this->assertNotNull($mala->concluido_em);
        $this->assertSame(2, $mala->destinatarios()->where('status', StatusDestinatario::Enviado)->count());
        $this->assertSame('Coordenação da FETECMS', $mala->solicitante);
    }

    public function test_disparo_nao_envia_para_email_invalido_mas_registra_no_relatorio(): void
    {
        Mail::fake();
        $this->admin();

        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'destinatarios' => [
                ['email' => 'ok@escola.test'],
                ['email' => 'torto@@escola'],
            ],
        ]))->assertCreated();

        Mail::assertSent(MalaDiretaMensagem::class, 1);

        $invalido = MalaDiretaDestinatario::where('email', 'torto@@escola')->firstOrFail();
        $this->assertSame(StatusDestinatario::Invalido, $invalido->status);
        $this->assertSame('Endereço de e-mail inválido.', $invalido->erro);
    }

    public function test_disparo_sem_destinatario_valido_e_recusado(): void
    {
        Mail::fake();
        $this->admin();

        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'destinatarios' => [['email' => 'sem-arroba']],
        ]))->assertStatus(422)->assertJsonValidationErrors('publicos');

        Mail::assertNothingSent();
        $this->assertSame(0, MalaDireta::count());
    }

    public function test_disparo_valida_campos_obrigatorios_da_mensagem(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/mala-direta', [
            'publicos' => [PublicoMala::Orientadores->value],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['nome', 'justificativa', 'assunto', 'corpo']);
    }

    public function test_falha_de_envio_vira_linha_de_falha_no_relatorio(): void
    {
        Mail::fake();
        $this->admin();
        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'destinatarios' => [['email' => 'quebrou@escola.test']],
        ]))->assertCreated();

        $destinatario = MalaDiretaDestinatario::firstOrFail();
        // Volta para a fila e simula o job esgotando as tentativas.
        $destinatario->update(['status' => StatusDestinatario::Pendente, 'enviado_em' => null]);
        $destinatario->mala->update(['status' => StatusMala::Enviando, 'concluido_em' => null]);

        (new EnviarMalaDireta($destinatario->id))->failed(new RuntimeException('Caixa postal inexistente'));

        $destinatario->refresh();
        $this->assertSame(StatusDestinatario::Falha, $destinatario->status);
        $this->assertSame('Caixa postal inexistente', $destinatario->erro);
        // Sem ninguém na fila, a mala fecha mesmo tendo falha.
        $this->assertSame(StatusMala::Concluida, $destinatario->mala->fresh()->status);

        $relatorio = $this->getJson('/api/v1/admin/mala-direta/'.$destinatario->mala_direta_id.'/destinatarios?status=falha')
            ->assertOk();
        $this->assertSame('Caixa postal inexistente', $relatorio->json('data.0.erro'));
    }

    public function test_reenviar_falhas_recoloca_apenas_as_falhas_na_fila(): void
    {
        Mail::fake();
        $this->admin();
        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'destinatarios' => [
                ['email' => 'ok@escola.test'],
                ['email' => 'falhou@escola.test'],
                ['email' => 'sem-arroba'],
            ],
        ]))->assertCreated();

        $mala = MalaDireta::firstOrFail();
        $mala->destinatarios()->where('email', 'falhou@escola.test')
            ->update(['status' => StatusDestinatario::Falha, 'erro' => 'Recusado']);

        $resposta = $this->postJson('/api/v1/admin/mala-direta/'.$mala->id.'/reenviar-falhas')->assertOk();

        $this->assertSame(1, $resposta->json('meta.reenviados'));
        // O reenvio saiu (sync no teste): 2 do disparo + 1 do reenvio.
        Mail::assertSent(MalaDiretaMensagem::class, 3);
        $this->assertSame(StatusDestinatario::Enviado, $mala->destinatarios()->where('email', 'falhou@escola.test')->first()->status);
        // O inválido continua de fora do reenvio.
        $this->assertSame(StatusDestinatario::Invalido, $mala->destinatarios()->where('email', 'sem-arroba')->first()->status);
    }

    public function test_listagem_traz_as_malas_da_mais_recente_para_a_mais_antiga(): void
    {
        Mail::fake();
        $this->admin();
        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'nome' => 'Antiga', 'destinatarios' => [['email' => 'a@escola.test']],
        ]))->assertCreated();
        MalaDireta::firstOrFail()->update(['enviado_em' => now()->subDays(3)]);

        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'nome' => 'Recente', 'destinatarios' => [['email' => 'b@escola.test']],
        ]))->assertCreated();

        $resposta = $this->getJson('/api/v1/admin/mala-direta')->assertOk();

        $this->assertSame(['Recente', 'Antiga'], collect($resposta->json('data'))->pluck('nome')->all());
        $this->assertSame(1, $resposta->json('data.0.totais.enviado'));
        $this->assertCount(count(PublicoMala::cases()), $resposta->json('meta.publicos'));
    }

    public function test_relatorio_exporta_csv_da_mala(): void
    {
        Mail::fake();
        $this->admin();
        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'destinatarios' => [['email' => 'ok@escola.test', 'nome' => 'Beto Lima']],
        ]))->assertCreated();

        $mala = MalaDireta::firstOrFail();
        $resposta = $this->get('/api/v1/admin/mala-direta/'.$mala->id.'/exportar')->assertOk();
        $csv = $resposta->getContent();

        $this->assertStringContainsString('Beto Lima', $csv);
        $this->assertStringContainsString('Enviado', $csv);
        $this->assertStringContainsString('Lista personalizada', $csv);
    }

    public function test_orientador_nao_acessa_a_mala_direta(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/mala-direta')->assertForbidden();
        $this->postJson('/api/v1/admin/mala-direta/previa', ['publicos' => ['todos']])->assertForbidden();
        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem(['publicos' => ['todos']]))->assertForbidden();
    }

    public function test_visitante_nao_acessa_a_mala_direta(): void
    {
        $this->getJson('/api/v1/admin/mala-direta')->assertUnauthorized();
    }
}
