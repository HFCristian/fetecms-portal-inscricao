<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\RedefinirSenhaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class RecuperacaoSenhaTest extends TestCase
{
    use RefreshDatabase;

    private const RESPOSTA_NEUTRA = 'Se este e-mail estiver cadastrado, enviamos um link para redefinir a senha.';

    public function test_envia_link_para_email_existente(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'a@b.com']);

        $this->postJson('/api/v1/auth/esqueci-senha', ['email' => 'a@b.com'])
            ->assertOk()
            ->assertJsonPath('data.message', self::RESPOSTA_NEUTRA);

        Notification::assertSentTo($user, RedefinirSenhaNotification::class);
    }

    public function test_email_inexistente_tem_resposta_neutra_e_nao_envia(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/esqueci-senha', ['email' => 'ninguem@x.com'])
            ->assertOk()
            ->assertJsonPath('data.message', self::RESPOSTA_NEUTRA);

        Notification::assertNothingSent();
    }

    public function test_email_invalido_e_rejeitado(): void
    {
        $this->postJson('/api/v1/auth/esqueci-senha', ['email' => 'nao-e-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_redefine_senha_com_token_valido(): void
    {
        $user = User::factory()->create(['email' => 'a@b.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/redefinir-senha', [
            'token' => $token,
            'email' => 'a@b.com',
            'password' => 'nova-senha-segura',
            'password_confirmation' => 'nova-senha-segura',
        ])->assertOk()
            ->assertJsonPath('data.message', 'Senha redefinida com sucesso. Você já pode entrar com a nova senha.');

        $this->assertTrue(Hash::check('nova-senha-segura', $user->fresh()->password));
    }

    public function test_avaliador_e_admin_tambem_redefinem(): void
    {
        foreach ([User::factory()->avaliador()->create(), User::factory()->admin()->create()] as $user) {
            $token = Password::createToken($user);

            $this->postJson('/api/v1/auth/redefinir-senha', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'nova-senha-segura',
                'password_confirmation' => 'nova-senha-segura',
            ])->assertOk();

            $this->assertTrue(Hash::check('nova-senha-segura', $user->fresh()->password));
        }
    }

    public function test_token_invalido_e_rejeitado(): void
    {
        User::factory()->create(['email' => 'a@b.com']);

        $this->postJson('/api/v1/auth/redefinir-senha', [
            'token' => 'token-falso',
            'email' => 'a@b.com',
            'password' => 'nova-senha-segura',
            'password_confirmation' => 'nova-senha-segura',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // Senha não mudou.
        $this->assertTrue(Hash::check('password', User::where('email', 'a@b.com')->first()->password));
    }

    public function test_email_que_nao_casa_com_o_token_e_rejeitado(): void
    {
        $user = User::factory()->create(['email' => 'dono@b.com']);
        User::factory()->create(['email' => 'outro@b.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/redefinir-senha', [
            'token' => $token,
            'email' => 'outro@b.com',
            'password' => 'nova-senha-segura',
            'password_confirmation' => 'nova-senha-segura',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_confirmacao_diferente_e_rejeitada(): void
    {
        $user = User::factory()->create(['email' => 'a@b.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/redefinir-senha', [
            'token' => $token,
            'email' => 'a@b.com',
            'password' => 'nova-senha-segura',
            'password_confirmation' => 'diferente',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_senha_curta_e_rejeitada(): void
    {
        $user = User::factory()->create(['email' => 'a@b.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/redefinir-senha', [
            'token' => $token,
            'email' => 'a@b.com',
            'password' => 'curta',
            'password_confirmation' => 'curta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_token_nao_pode_ser_reutilizado(): void
    {
        $user = User::factory()->create(['email' => 'a@b.com']);
        $token = Password::createToken($user);

        $payload = [
            'token' => $token,
            'email' => 'a@b.com',
            'password' => 'nova-senha-segura',
            'password_confirmation' => 'nova-senha-segura',
        ];

        $this->postJson('/api/v1/auth/redefinir-senha', $payload)->assertOk();

        // Segundo uso do mesmo token deve falhar (token de uso único).
        $this->postJson('/api/v1/auth/redefinir-senha', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
