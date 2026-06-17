<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Area;
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

    public function test_projetos_por_area_agrupa_incluindo_rascunhos(): void
    {
        $orient = User::factory()->create();
        $area = Area::first();

        // 1 com área (rascunho) e 1 sem área (rascunho) — ambos devem aparecer.
        Projeto::factory()->create(['user_id' => $orient->id, 'area_id' => $area->id]);
        Projeto::factory()->create(['user_id' => $orient->id, 'area_id' => null]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $resp = $this->getJson('/api/v1/admin/projetos-por-area')->assertOk();

        $grupos = collect($resp->json('data'));
        $this->assertSame(2, $grupos->sum('total'));

        // O grupo sem área usa o rótulo de fallback e fica por último.
        $semArea = $grupos->firstWhere('area_id', null);
        $this->assertNotNull($semArea);
        $this->assertSame('Área ainda não informada', $semArea['area']);
        $this->assertSame('Área ainda não informada', $grupos->last()['area']);
        $this->assertSame($area->nome, $grupos->firstWhere('area_id', $area->id)['area']);
    }

    public function test_nao_admin_nao_acessa_projetos_por_area(): void
    {
        Sanctum::actingAs(User::factory()->create()); // orientador
        $this->getJson('/api/v1/admin/projetos-por-area')->assertForbidden();
    }

    public function test_projetos_por_localidade_agrupa_com_status_e_escolas(): void
    {
        $orient = User::factory()->create();
        $ms = Estado::where('uf', 'MS')->first();
        $cidade = $ms->cidades()->first();
        $insts = Instituicao::take(2)->get();

        // MS: 2 submetidos na escola[0] + 1 rascunho na escola[1] = 3 projetos.
        Projeto::factory()->submetido()->count(2)->create([
            'user_id' => $orient->id, 'estado_id' => $ms->id, 'cidade_id' => $cidade->id, 'instituicao_id' => $insts[0]->id,
        ]);
        Projeto::factory()->create([
            'user_id' => $orient->id, 'estado_id' => $ms->id, 'cidade_id' => $cidade->id, 'instituicao_id' => $insts[1]->id,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $data = $this->getJson('/api/v1/admin/projetos-por-localidade')->assertOk()->json('data');

        // Estado MS: 3 projetos (2 submetidos, 1 rascunho) e 2 escolas aninhadas.
        $estado = collect($data['estados'])->firstWhere('id', $ms->id);
        $this->assertNotNull($estado);
        $this->assertSame(3, $estado['total']);
        $this->assertSame(2, $estado['submetidos']);
        $this->assertSame(1, $estado['rascunho']);
        $this->assertSame('MS', $estado['uf']);
        $this->assertCount(2, $estado['escolas']);

        // Cidade também agrupa com escolas.
        $cid = collect($data['cidades'])->firstWhere('id', $cidade->id);
        $this->assertSame(3, $cid['total']);
        $this->assertCount(2, $cid['escolas']);

        // Escola[0] tem os 2 submetidos; escola[1] tem o rascunho.
        $escola0 = collect($data['escolas'])->firstWhere('id', $insts[0]->id);
        $this->assertSame(2, $escola0['submetidos']);
        $this->assertSame(0, $escola0['rascunho']);
        $escola1 = collect($data['escolas'])->firstWhere('id', $insts[1]->id);
        $this->assertSame(0, $escola1['submetidos']);
        $this->assertSame(1, $escola1['rascunho']);
    }

    public function test_nao_admin_nao_acessa_projetos_por_localidade(): void
    {
        Sanctum::actingAs(User::factory()->create()); // orientador
        $this->getJson('/api/v1/admin/projetos-por-localidade')->assertForbidden();
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
