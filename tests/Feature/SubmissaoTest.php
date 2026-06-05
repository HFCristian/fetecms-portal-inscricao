<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\TipoDocumento;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    /** Cria um projeto que satisfaz todo o checklist de submissão. */
    private function projetoCompleto(User $user, array $over = []): Projeto
    {
        $area = Area::first();
        $estado = Estado::where('uf', 'MS')->first();

        $projeto = Projeto::factory()->create(array_merge([
            'user_id' => $user->id,
            'titulo' => 'Bioplástico de Mandioca',
            'categoria' => Categoria::Fetecms,
            'instituicao_id' => Instituicao::first()->id,
            'area_id' => $area->id,
            'subarea_id' => $area->subareas()->first()->id,
            'palavras_chave' => ['Biotecnologia', 'Sustentabilidade', 'Mandioca'],
            'pais' => 'BR',
            'estado_id' => $estado->id,
            'cidade_id' => $estado->cidades()->first()->id,
            'link_video' => 'https://youtu.be/abcdefghijk',
            'resumo' => implode(' ', array_fill(0, 160, 'palavra')),
            'email_comunicacao' => 'contato@escola.ms.gov.br',
            'declaracao_email' => true,
        ], $over));

        Aluno::factory()->create(['projeto_id' => $projeto->id]);
        ProjetoDocumento::factory()->create([
            'projeto_id' => $projeto->id,
            'tipo' => TipoDocumento::PlanoPesquisa,
        ]);

        return $projeto;
    }

    public function test_resumo_lista_pendencias_de_projeto_incompleto(): void
    {
        $user = User::factory()->create();
        $projeto = Projeto::factory()->create(['user_id' => $user->id, 'categoria' => null]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/projetos/{$projeto->id}/resumo")
            ->assertOk()
            ->assertJsonPath('data.pode_submeter', false)
            ->assertJsonStructure(['data' => ['pendencias' => [['code', 'message']]]]);
    }

    public function test_nao_submete_projeto_incompleto(): void
    {
        $user = User::factory()->create();
        $projeto = Projeto::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'pendencias', 'code']);

        $this->assertDatabaseHas('projetos', ['id' => $projeto->id, 'status' => 'rascunho']);
    }

    public function test_submete_projeto_completo(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoCompleto($user);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/projetos/{$projeto->id}/resumo")
            ->assertOk()->assertJsonPath('data.pode_submeter', true);

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")
            ->assertOk()
            ->assertJsonPath('data.status', 'submetido');

        $projeto->refresh();
        $this->assertSame(ProjetoStatus::Submetido, $projeto->status);
        $this->assertNotNull($projeto->submitted_at);
    }

    public function test_toggle_continuacao_exige_documento(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoCompleto($user, ['continuacao' => true]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'CONTINUACAO_DOC']);
    }

    public function test_submissao_e_idempotente(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoCompleto($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")->assertOk();
        // Resubmeter o próprio projeto já submetido não dá erro.
        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")->assertOk();
    }

    public function test_nao_submete_projeto_alheio(): void
    {
        $projetoAlheio = $this->projetoCompleto(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/projetos/{$projetoAlheio->id}/submeter")->assertForbidden();
    }
}
