<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    public function test_dashboard_traz_as_nove_metricas(): void
    {
        $orient = User::factory()->create(); // role orientador
        $ms = Estado::where('uf', 'MS')->first();
        $sp = Estado::where('uf', 'SP')->first();
        $insts = Instituicao::take(2)->get();

        // 2 submetidos em escolas/cidades/estados distintos
        Projeto::factory()->submetido()->create([
            'user_id' => $orient->id, 'instituicao_id' => $insts[0]->id,
            'estado_id' => $ms->id, 'cidade_id' => $ms->cidades()->first()->id,
        ]);
        Projeto::factory()->submetido()->create([
            'user_id' => $orient->id, 'instituicao_id' => $insts[1]->id,
            'estado_id' => $sp->id, 'cidade_id' => $sp->cidades()->first()->id,
        ]);
        // 1 rascunho (não conta nas métricas escolas/cidades/estados)
        $rascunho = Projeto::factory()->create(['user_id' => $orient->id]);
        Aluno::factory()->count(3)->create(['projeto_id' => $rascunho->id]);
        Coorientador::factory()->create(['projeto_id' => $rascunho->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.projetos_total', 3)
            ->assertJsonPath('data.projetos_submetidos', 2)
            ->assertJsonPath('data.projetos_rascunho', 1)
            ->assertJsonPath('data.orientadores', 1)
            ->assertJsonPath('data.alunos', 3)
            ->assertJsonPath('data.coorientadores', 1)
            ->assertJsonPath('data.escolas_com_projeto', 2)
            ->assertJsonPath('data.cidades_com_projeto', 2)
            ->assertJsonPath('data.estados_com_projeto', 2);
    }

    public function test_admin_cria_outro_admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/admins', [
            'name' => 'Novo Admin',
            'email' => 'novo@admin.test',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
        ])->assertCreated()->assertJsonPath('data.role', 'admin');

        $this->assertDatabaseHas('users', ['email' => 'novo@admin.test', 'role' => 'admin']);
    }

    public function test_nao_admin_nao_acessa_dashboard_nem_cria_admin(): void
    {
        Sanctum::actingAs(User::factory()->create()); // orientador

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->postJson('/api/v1/admin/admins', [
            'name' => 'X', 'email' => 'x@x.com', 'password' => 'Senha@123', 'password_confirmation' => 'Senha@123',
        ])->assertForbidden();
    }

    public function test_visitante_nao_acessa_dashboard(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }
}
