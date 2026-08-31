<?php

namespace Tests\Feature;

use App\Enums\ModeloEmail;
use App\Mail\MensagemTransacional;
use App\Models\User;
use App\Services\ConfirmacaoCadastroService;
use App\Services\ModeloEmailService;
use App\Support\HtmlEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModeloEmailTest extends TestCase
{
    use RefreshDatabase;

    private function comoAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_lista_traz_o_texto_de_fabrica_e_as_variaveis(): void
    {
        $this->comoAdmin();

        $this->getJson('/api/v1/admin/modelos-email')
            ->assertOk()
            ->assertJsonPath('data.0.chave', ModeloEmail::ConfirmacaoCadastro->value)
            ->assertJsonPath('data.0.assunto', ConfirmacaoCadastroService::ASSUNTO_PADRAO)
            ->assertJsonPath('data.0.personalizado', false)
            ->assertJsonPath('data.0.variaveis.3.chave', 'codigo');
    }

    public function test_admin_edita_o_texto_e_o_email_sai_com_ele(): void
    {
        $admin = $this->comoAdmin();

        $this->putJson('/api/v1/admin/modelos-email/confirmacao_cadastro', [
            'assunto' => 'Seu código FETECMS',
            'corpo' => "Oi, {{nome}}!\n\n{{codigo}}\n\nVale {{validade}} minutos.",
        ])
            ->assertOk()
            ->assertJsonPath('data.personalizado', true)
            ->assertJsonPath('data.autor_nome', $admin->name);

        Mail::fake();

        $this->postJson('/api/v1/orientadores', [
            'name' => 'João da Silva',
            'email' => 'joao@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
        ])->assertStatus(202);

        Mail::assertSent(MensagemTransacional::class, function (MensagemTransacional $m) {
            return $m->assunto === 'Seu código FETECMS'
                && str_contains($m->corpo, 'Oi, João!')
                && str_contains($m->corpo, 'Vale 15 minutos.')
                && str_contains($m->corpo, (string) $m->destaque);
        });
    }

    public function test_restaurar_volta_ao_texto_padrao(): void
    {
        $this->comoAdmin();

        $this->putJson('/api/v1/admin/modelos-email/confirmacao_cadastro', [
            'assunto' => 'Outro assunto',
            'corpo' => 'Outro corpo com {{codigo}}.',
        ])->assertOk();

        $this->deleteJson('/api/v1/admin/modelos-email/confirmacao_cadastro')
            ->assertOk()
            ->assertJsonPath('data.personalizado', false)
            ->assertJsonPath('data.assunto', ConfirmacaoCadastroService::ASSUNTO_PADRAO);

        $this->assertDatabaseCount('modelos_email', 0);
    }

    public function test_salvar_o_proprio_padrao_nao_marca_como_personalizado(): void
    {
        $this->comoAdmin();

        $this->putJson('/api/v1/admin/modelos-email/confirmacao_cadastro', [
            'assunto' => ConfirmacaoCadastroService::ASSUNTO_PADRAO,
            'corpo' => ConfirmacaoCadastroService::CORPO_PADRAO,
        ])
            ->assertOk()
            ->assertJsonPath('data.personalizado', false);

        $this->assertDatabaseCount('modelos_email', 0);
    }

    public function test_so_admin_acessa(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/modelos-email')->assertForbidden();
        $this->putJson('/api/v1/admin/modelos-email/confirmacao_cadastro', [
            'assunto' => 'x', 'corpo' => 'y',
        ])->assertForbidden();
    }

    public function test_modelo_desconhecido_da_404(): void
    {
        $this->comoAdmin();

        $this->getJson('/api/v1/admin/modelos-email/nao_existe')->assertNotFound();
    }

    // --- Sprint 91: corpo formatado (editor rico) ---

    /** O corpo em HTML é sanitizado na gravação: só formatação passa. */
    public function test_corpo_em_html_e_sanitizado_ao_salvar(): void
    {
        $this->comoAdmin();

        $this->putJson('/api/v1/admin/modelos-email/projeto_submetido', [
            'assunto' => 'Projeto submetido',
            'corpo' => '<p>Olá, <strong>{{nome}}</strong>!</p>'
                .'<script>alert(1)</script>'
                .'<p onclick="roubar()">Projeto <em>{{projeto}}</em>.</p>',
            'formato' => 'html',
        ])
            ->assertOk()
            ->assertJsonPath('data.formato', 'html')
            ->assertJsonPath('data.personalizado', true);

        $corpo = app(ModeloEmailService::class)->texto(ModeloEmail::ProjetoSubmetido)['corpo'];

        $this->assertStringContainsString('<strong>{{nome}}</strong>', $corpo);
        $this->assertStringContainsString('<em>{{projeto}}</em>', $corpo);
        $this->assertStringNotContainsString('script', $corpo);
        $this->assertStringNotContainsString('onclick', $corpo);
    }

    /** A mensagem enviada carrega o formato: é o layout que decide como desenhar. */
    public function test_email_sai_com_o_corpo_em_html(): void
    {
        $this->comoAdmin();

        $this->putJson('/api/v1/admin/modelos-email/confirmacao_cadastro', [
            'assunto' => 'Seu código',
            'corpo' => '<p>Oi, <strong>{{nome}}</strong>!</p><p>{{codigo}}</p>',
            'formato' => 'html',
        ])->assertOk();

        Mail::fake();

        $this->postJson('/api/v1/orientadores', [
            'name' => 'João da Silva',
            'email' => 'joao@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
        ])->assertStatus(202);

        Mail::assertSent(MensagemTransacional::class, function (MensagemTransacional $m) {
            return $m->ehHtml()
                && str_contains($m->corpo, '<strong>João</strong>')
                && str_contains($m->corpo, (string) $m->destaque);
        });
    }

    /**
     * O código de 6 dígitos continua saindo no bloco grande, agora achado
     * dentro do próprio HTML em vez de parágrafo a parágrafo.
     */
    public function test_destaque_do_codigo_vale_no_corpo_em_html(): void
    {
        $html = '<p>Oi!</p><p>123456</p><p>Vale 15 minutos.</p>';

        $destacado = HtmlEmail::destacar($html, '123456', 'font-size:32px;');

        $this->assertStringContainsString('<p style="font-size:32px;">123456</p>', $destacado);
        $this->assertStringContainsString('<p>Oi!</p>', $destacado);
    }

    /** Sem parágrafo correspondente, o HTML volta inteiro — não é erro. */
    public function test_destaque_sem_correspondencia_nao_mexe_no_html(): void
    {
        $html = '<p>Sem código aqui.</p>';

        $this->assertSame($html, HtmlEmail::destacar($html, '123456', 'font-size:32px;'));
    }

    /** Texto puro segue como estava: nada muda para quem não abrir o editor. */
    public function test_formato_padrao_continua_texto(): void
    {
        $this->comoAdmin();

        $this->putJson('/api/v1/admin/modelos-email/projeto_submetido', [
            'assunto' => 'Projeto submetido',
            'corpo' => "Olá!\n\nSeu projeto foi recebido.",
        ])
            ->assertOk()
            ->assertJsonPath('data.formato', 'texto');
    }
}
