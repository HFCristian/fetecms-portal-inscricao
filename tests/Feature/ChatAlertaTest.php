<?php

namespace Tests\Feature;

use App\Enums\StatusConversa;
use App\Mail\ConversasPendentesAlerta;
use App\Models\Conversa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChatAlertaTest extends TestCase
{
    use RefreshDatabase;

    public function test_envia_alerta_quando_ha_mensagens_pendentes(): void
    {
        Mail::fake();

        Conversa::factory()->create(); // nao_visualizada -> conta
        Conversa::factory()->status(StatusConversa::Visualizada)->create(); // conta
        Conversa::factory()->status(StatusConversa::EmTratamento)->create(); // não conta
        Conversa::factory()->status(StatusConversa::Respondida)->create(); // não conta

        $this->artisan('chat:alertar-pendentes')->assertSuccessful();

        Mail::assertSent(
            ConversasPendentesAlerta::class,
            fn (ConversasPendentesAlerta $mail) => $mail->quantidade === 2
                && $mail->hasTo(config('fetecms.suporte_alerta_email')),
        );
    }

    public function test_nao_envia_quando_nao_ha_pendentes(): void
    {
        Mail::fake();

        Conversa::factory()->status(StatusConversa::EmTratamento)->create();
        Conversa::factory()->status(StatusConversa::Respondida)->create();
        Conversa::factory()->status(StatusConversa::Arquivada)->create();

        $this->artisan('chat:alertar-pendentes')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
