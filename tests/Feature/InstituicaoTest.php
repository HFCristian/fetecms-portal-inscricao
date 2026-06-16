<?php

namespace Tests\Feature;

use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstituicaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_nao_cria_instituicao(): void
    {
        $this->postJson('/api/v1/catalogos/instituicoes', ['nome' => 'Escola Nova'])
            ->assertUnauthorized();
    }

    public function test_autenticado_cria_instituicao_global(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/catalogos/instituicoes', ['nome' => '  Escola  Estadual X '])
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Escola Estadual X'); // trim + espaços colapsados

        $this->assertDatabaseHas('instituicoes', ['nome' => 'Escola Estadual X']);
    }

    public function test_criacao_e_idempotente_sem_diferenciar_caixa(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $a = $this->postJson('/api/v1/catalogos/instituicoes', ['nome' => 'Colégio ABC'])->json('data.id');
        $b = $this->postJson('/api/v1/catalogos/instituicoes', ['nome' => 'colégio abc'])->json('data.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, Instituicao::count());
    }

    public function test_busca_retorna_com_cidade(): void
    {
        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $cidade = $estado->cidades()->create(['nome' => 'Dourados']);
        Instituicao::create(['nome' => 'IFMS Dourados', 'cidade_id' => $cidade->id, 'tipo' => 'publica_federal']);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/catalogos/instituicoes?search=IFMS')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'IFMS Dourados')
            ->assertJsonPath('data.0.cidade', 'Dourados');
    }

    public function test_busca_encontra_palavras_fora_de_ordem(): void
    {
        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $cidade = $estado->cidades()->create(['nome' => 'Dourados']);
        Instituicao::create(['nome' => 'IFMS - Campus Dourados', 'cidade_id' => $cidade->id, 'tipo' => 'publica_federal']);
        Instituicao::create(['nome' => 'IFMS - Campus Três Lagoas', 'cidade_id' => $cidade->id, 'tipo' => 'publica_federal']);

        Sanctum::actingAs(User::factory()->create());

        // "IFMS Dourados" deve casar "IFMS - Campus Dourados" (palavras em qualquer ordem/posição).
        $this->getJson('/api/v1/catalogos/instituicoes?search=IFMS+Dourados')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'IFMS - Campus Dourados');
    }

    public function test_orientador_cria_instituicao_nova_no_cadastro(): void
    {
        $this->postJson('/api/v1/orientadores', [
            'name' => 'Professora Teste',
            'email' => 'prof@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
            'instituicao_nome' => 'Escola Municipal Sol Nascente',
        ])->assertCreated()
            ->assertJsonPath('data.orientador_profile.instituicao', 'Escola Municipal Sol Nascente');

        $this->assertDatabaseHas('instituicoes', ['nome' => 'Escola Municipal Sol Nascente']);
    }

    public function test_comando_importa_instituicoes_do_csv(): void
    {
        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $estado->cidades()->create(['nome' => 'Campo Grande']);

        $csv = storage_path('app/teste_escolas.csv');
        file_put_contents($csv, implode("\n", [
            '"MUNICÍPIO","ZONA","CÓDIGO DO INEP","UNIDADE ESCOLAR","TIPO"',
            '"Campo Grande","URBANA","50099999","ESCOLA TESTE UM","ESTADUAL"',
            '"Campo Grande","RURAL","50088888","ESCOLA TESTE DOIS","MUNICIPAL"',
        ]));

        $this->artisan('instituicoes:importar', ['--arquivo' => $csv])->assertSuccessful();

        $this->assertDatabaseHas('instituicoes', [
            'codigo_inep' => '50099999', 'nome' => 'ESCOLA TESTE UM', 'tipo' => 'publica_estadual', 'zona' => 'URBANA',
        ]);
        $this->assertDatabaseHas('instituicoes', [
            'codigo_inep' => '50088888', 'tipo' => 'publica_municipal', 'zona' => 'RURAL',
        ]);

        // Idempotente: rodar de novo não duplica (chave = código do INEP).
        $this->artisan('instituicoes:importar', ['--arquivo' => $csv])->assertSuccessful();
        $this->assertSame(2, Instituicao::whereIn('codigo_inep', ['50099999', '50088888'])->count());

        @unlink($csv);
    }

    public function test_mesmo_nome_em_cidades_diferentes_cria_instituicoes_distintas(): void
    {
        $ms = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $cg = $ms->cidades()->create(['nome' => 'Campo Grande']);
        $rs = Estado::create(['nome' => 'Rio Grande do Sul', 'uf' => 'RS']);
        $poa = $rs->cidades()->create(['nome' => 'Porto Alegre']);

        Sanctum::actingAs(User::factory()->create());

        $a = $this->postJson('/api/v1/catalogos/instituicoes', ['nome' => 'Escola São José', 'cidade_id' => $cg->id])->json('data.id');
        $b = $this->postJson('/api/v1/catalogos/instituicoes', ['nome' => 'Escola São José', 'cidade_id' => $poa->id])->json('data.id');

        $this->assertNotSame($a, $b);
        $this->assertSame(2, Instituicao::where('nome', 'Escola São José')->count());

        // Mesma cidade reaproveita.
        $c = $this->postJson('/api/v1/catalogos/instituicoes', ['nome' => 'Escola São José', 'cidade_id' => $cg->id])->json('data.id');
        $this->assertSame($a, $c);
    }

    public function test_orientador_cria_instituicao_nova_com_cidade_e_tipo(): void
    {
        $ms = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $cg = $ms->cidades()->create(['nome' => 'Campo Grande']);

        $this->postJson('/api/v1/orientadores', [
            'name' => 'Professor Teste',
            'email' => 'prof2@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
            'instituicao_nome' => 'Colégio Novo',
            'instituicao_cidade_id' => $cg->id,
            'instituicao_tipo' => 'particular',
        ])->assertCreated()
            ->assertJsonPath('data.orientador_profile.instituicao', 'Colégio Novo');

        $this->assertDatabaseHas('instituicoes', [
            'nome' => 'Colégio Novo', 'cidade_id' => $cg->id, 'tipo' => 'particular',
        ]);
    }
}
