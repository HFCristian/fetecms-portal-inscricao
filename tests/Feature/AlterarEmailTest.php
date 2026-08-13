<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoRegistro;
use App\Models\OrientadorProfile;
use App\Models\RegistroAtividade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Troca do e-mail de acesso — disponível a qualquer papel e sempre registrada. */
class AlterarEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_orientador_altera_o_proprio_email(): void
    {
        $user = User::factory()->create(['role' => Role::Orientador, 'email' => 'antigo@escola.test']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/email', ['email' => 'novo@escola.test'])
            ->assertOk()
            ->assertJsonPath('data.email', 'novo@escola.test');

        $this->assertSame('novo@escola.test', $user->fresh()->email);
    }

    public function test_avaliador_tambem_altera_o_proprio_email(): void
    {
        $user = User::factory()->create(['role' => Role::Avaliador, 'email' => 'aval@uni.test']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/email', ['email' => 'aval.novo@uni.test'])->assertOk();

        $this->assertSame('aval.novo@uni.test', $user->fresh()->email);
    }

    public function test_troca_de_email_vira_registro_com_o_de_e_o_para(): void
    {
        $user = User::factory()->create(['role' => Role::Orientador, 'email' => 'antigo@escola.test']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/email', ['email' => 'novo@escola.test'])->assertOk();

        $registro = RegistroAtividade::where('tipo', TipoRegistro::TrocaEmail)->sole();
        // O autor fica identificado pelo e-mail que ele tinha na hora da troca.
        $this->assertSame('antigo@escola.test', $registro->autor_email);
        $this->assertSame('antigo@escola.test', $registro->detalhes['de']);
        $this->assertSame('novo@escola.test', $registro->detalhes['para']);
    }

    public function test_email_ja_usado_por_outra_conta_e_rejeitado(): void
    {
        User::factory()->create(['email' => 'ocupado@escola.test']);
        $user = User::factory()->create(['role' => Role::Orientador]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/email', ['email' => 'ocupado@escola.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, RegistroAtividade::count());
    }

    public function test_email_invalido_e_rejeitado(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador]));

        $this->putJson('/api/v1/auth/email', ['email' => 'nao-e-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_repetir_o_mesmo_email_nao_gera_registro(): void
    {
        $user = User::factory()->create(['role' => Role::Orientador, 'email' => 'mesmo@escola.test']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/email', ['email' => 'MESMO@escola.test'])->assertOk();

        $this->assertSame('mesmo@escola.test', $user->fresh()->email);
        $this->assertSame(0, RegistroAtividade::count());
    }

    public function test_visitante_nao_altera_email(): void
    {
        $this->putJson('/api/v1/auth/email', ['email' => 'qualquer@escola.test'])->assertUnauthorized();
    }

    public function test_perfil_do_orientador_nao_altera_mais_o_email(): void
    {
        $user = User::factory()->create(['role' => Role::Orientador, 'email' => 'perfil@escola.test']);
        OrientadorProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/perfil', ['name' => 'Nome Novo', 'email' => 'burlado@escola.test'])
            ->assertOk();

        $user->refresh();
        $this->assertSame('Nome Novo', $user->name);
        $this->assertSame('perfil@escola.test', $user->email);
    }
}
