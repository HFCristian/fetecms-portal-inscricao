<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubareaTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_nao_cria_subarea(): void
    {
        $area = Area::create(['nome' => 'Engenharias']);

        $this->postJson('/api/v1/catalogos/subareas', ['area_id' => $area->id, 'nome' => 'Robótica'])
            ->assertUnauthorized();
    }

    public function test_usuario_autenticado_cria_subarea_global(): void
    {
        $area = Area::create(['nome' => 'Engenharias']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/catalogos/subareas', ['area_id' => $area->id, 'nome' => '  Robótica  Móvel '])
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Robótica Móvel') // trim + espaços colapsados
            ->assertJsonPath('data.area_id', $area->id);

        $this->assertDatabaseHas('subareas', ['area_id' => $area->id, 'nome' => 'Robótica Móvel']);
    }

    public function test_criacao_e_idempotente_sem_diferenciar_caixa(): void
    {
        $area = Area::create(['nome' => 'Engenharias']);
        Sanctum::actingAs(User::factory()->create());

        $a = $this->postJson('/api/v1/catalogos/subareas', ['area_id' => $area->id, 'nome' => 'Nanotecnologia'])
            ->assertCreated()->json('data.id');
        $b = $this->postJson('/api/v1/catalogos/subareas', ['area_id' => $area->id, 'nome' => 'nanotecnologia'])
            ->assertCreated()->json('data.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, Subarea::where('area_id', $area->id)->count());
    }

    public function test_orientador_cria_subarea_nova_no_cadastro(): void
    {
        $area = Area::create(['nome' => 'Ciências Biológicas']);

        $this->postJson('/api/v1/orientadores', [
            'name' => 'Maria Souza',
            'email' => 'maria@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
            'area_id' => $area->id,
            'subarea_nome' => 'Bioinformática',
        ])->assertCreated()
            ->assertJsonPath('data.orientador_profile.area', 'Ciências Biológicas')
            ->assertJsonPath('data.orientador_profile.subarea', 'Bioinformática');

        $this->assertDatabaseHas('subareas', ['area_id' => $area->id, 'nome' => 'Bioinformática']);
    }

    public function test_avaliador_cria_subarea_nova_no_cadastro(): void
    {
        $area = Area::create(['nome' => 'Engenharias']);

        $this->postJson('/api/v1/avaliadores', [
            'name' => 'João Lima',
            'email' => 'joao.aval@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'area_id' => $area->id,
            'subarea_nome' => 'Mecatrônica',
        ])->assertCreated()
            ->assertJsonPath('data.avaliador_profile.subarea', 'Mecatrônica');

        $this->assertDatabaseHas('subareas', ['area_id' => $area->id, 'nome' => 'Mecatrônica']);
    }

    public function test_subarea_de_outra_area_e_rejeitada(): void
    {
        $eng = Area::create(['nome' => 'Engenharias']);
        $bio = Area::create(['nome' => 'Ciências Biológicas']);
        $subBio = $bio->subareas()->create(['nome' => 'Genética']);

        $this->postJson('/api/v1/avaliadores', [
            'name' => 'Ana Paula',
            'email' => 'ana.aval@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'area_id' => $eng->id,
            'subarea_id' => $subBio->id,
        ])->assertStatus(422)->assertJsonValidationErrors('subarea_id');
    }
}
