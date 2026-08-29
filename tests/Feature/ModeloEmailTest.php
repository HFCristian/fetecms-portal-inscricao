<?php

namespace Tests\Feature;

use App\Enums\ModeloEmail;
use App\Mail\MensagemTransacional;
use App\Models\User;
use App\Services\ConfirmacaoCadastroService;
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
}
