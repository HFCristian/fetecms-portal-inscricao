<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Estado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterOrientadorTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'João da Silva Santos',
            'email' => 'joao@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
            'genero' => 'M',
            'camiseta' => 'G',
            'instituicao' => 'UFMS',
        ], $overrides);
    }

    public function test_orientador_consegue_se_cadastrar(): void
    {
        $response = $this->postJson('/api/v1/orientadores', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'joao@escola.ms.gov.br')
            ->assertJsonPath('data.role', 'orientador')
            ->assertJsonPath('data.orientador_profile.cpf', '52998224725');

        $this->assertDatabaseHas('users', [
            'email' => 'joao@escola.ms.gov.br',
            'role' => Role::Orientador->value,
        ]);
        $this->assertDatabaseHas('orientador_profiles', ['cpf' => '52998224725']);
    }

    public function test_senha_e_armazenada_como_hash(): void
    {
        $this->postJson('/api/v1/orientadores', $this->payload());

        $user = User::where('email', 'joao@escola.ms.gov.br')->first();
        $this->assertNotSame('Senha@123', $user->password);
        $this->assertTrue(password_get_info($user->password)['algo'] !== null);
    }

    public function test_cpf_invalido_e_rejeitado(): void
    {
        $this->postJson('/api/v1/orientadores', $this->payload(['cpf' => '12345678900']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpf');
    }

    public function test_email_duplicado_e_rejeitado(): void
    {
        User::factory()->create(['email' => 'joao@escola.ms.gov.br']);

        $this->postJson('/api/v1/orientadores', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_senha_precisa_de_confirmacao_e_minimo(): void
    {
        $this->postJson('/api/v1/orientadores', $this->payload([
            'password' => '123',
            'password_confirmation' => '123',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_orientador_se_cadastra_com_endereco_por_fk(): void
    {
        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $cidade = $estado->cidades()->create(['nome' => 'Campo Grande']);

        $this->postJson('/api/v1/orientadores', $this->payload([
            'pais' => 'BR',
            'cep' => '79000-000',
            'estado_id' => $estado->id,
            'cidade_id' => $cidade->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.orientador_profile.endereco.cidade', 'Campo Grande')
            ->assertJsonPath('data.orientador_profile.endereco.estado_uf', 'MS');

        $this->assertDatabaseHas('orientador_profiles', [
            'estado_id' => $estado->id,
            'cidade_id' => $cidade->id,
            'cep' => '79000000',
        ]);
    }

    public function test_cidade_de_outro_estado_e_rejeitada(): void
    {
        $ms = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $sp = Estado::create(['nome' => 'São Paulo', 'uf' => 'SP']);
        $cidadeSp = $sp->cidades()->create(['nome' => 'São Paulo']);

        $this->postJson('/api/v1/orientadores', $this->payload([
            'estado_id' => $ms->id,
            'cidade_id' => $cidadeSp->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cidade_id');
    }

    public function test_endereco_no_exterior_usa_texto_livre(): void
    {
        $this->postJson('/api/v1/orientadores', $this->payload([
            'pais' => 'AR',
            'estado_nome' => 'Buenos Aires',
            'cidade_nome' => 'La Plata',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.orientador_profile.endereco.cidade', 'La Plata')
            ->assertJsonPath('data.orientador_profile.endereco.estado', 'Buenos Aires');
    }
}
