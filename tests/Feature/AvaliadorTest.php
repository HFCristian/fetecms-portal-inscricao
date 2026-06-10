<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AvaliadorProfile;
use App\Models\Coorientador;
use App\Models\OrientadorProfile;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvaliadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Carla Avaliadora',
            'email' => 'carla@avaliadora.com',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'titulacao' => 'Doutorado',
            'area_id' => Area::first()->id,
        ], $over);
    }

    public function test_avaliador_se_cadastra(): void
    {
        $this->postJson('/api/v1/avaliadores', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.role', 'avaliador')
            ->assertJsonPath('data.avaliador_profile.cpf', '52998224725');

        $this->assertDatabaseHas('users', ['email' => 'carla@avaliadora.com', 'role' => 'avaliador']);
        $this->assertDatabaseHas('avaliador_profiles', ['cpf' => '52998224725']);
    }

    public function test_cpf_de_orientador_nao_pode_ser_avaliador(): void
    {
        $orientador = User::factory()->create();
        OrientadorProfile::factory()->create(['user_id' => $orientador->id, 'cpf' => '52998224725']);

        $this->postJson('/api/v1/avaliadores', $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors('cpf');
    }

    public function test_email_de_orientador_nao_pode_ser_avaliador(): void
    {
        User::factory()->create(['email' => 'dup@escola.com']);

        $this->postJson('/api/v1/avaliadores', $this->payload(['email' => 'dup@escola.com']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_cpf_de_coorientador_nao_pode_ser_avaliador(): void
    {
        Coorientador::factory()->create(['cpf' => '52998224725']);

        $this->postJson('/api/v1/avaliadores', $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors('cpf');
    }

    public function test_simetria_avaliador_nao_pode_ser_orientador(): void
    {
        AvaliadorProfile::factory()->create(['cpf' => '52998224725']);

        $this->postJson('/api/v1/orientadores', [
            'name' => 'Tenta Orientador',
            'email' => 'tenta@escola.com',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '67999990000',
            'data_nascimento' => '1985-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('cpf');
    }

    public function test_avaliador_loga_e_me_retorna_perfil(): void
    {
        $user = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'avaliador')
            ->assertJsonPath('data.avaliador_profile.cpf', $user->avaliadorProfile->cpf);
    }
}
