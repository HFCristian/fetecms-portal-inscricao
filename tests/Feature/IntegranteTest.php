<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Models\Aluno;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegranteTest extends TestCase
{
    use RefreshDatabase;

    private function projetoDe(User $user, ?Categoria $categoria = Categoria::Fetecms): Projeto
    {
        return Projeto::factory()->create([
            'user_id' => $user->id,
            'categoria' => $categoria,
        ]);
    }

    private function alunoPayload(array $over = []): array
    {
        return array_merge([
            'nome' => 'Maria Clara',
            'email' => 'maria'.fake()->unique()->numberBetween(1, 99999).'@aluno.ms.gov.br',
            'cpf' => '529.982.247-25',
            'data_nascimento' => '2010-05-20',
        ], $over);
    }

    public function test_orientador_adiciona_aluno(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/alunos", $this->alunoPayload())
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Maria Clara');

        $this->assertDatabaseHas('alunos', ['projeto_id' => $projeto->id, 'cpf' => '52998224725']);
    }

    public function test_exige_categoria_antes_de_adicionar_aluno(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user, null); // sem categoria
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/alunos", $this->alunoPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('categoria');
    }

    public function test_respeita_limite_da_categoria_fetecms(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user, Categoria::Fetecms); // máx 3
        Aluno::factory()->count(3)->create(['projeto_id' => $projeto->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/alunos", $this->alunoPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipe');
    }

    public function test_fetec_jr_limita_em_tres(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user, Categoria::FetecJr); // máx 3
        Aluno::factory()->count(3)->create(['projeto_id' => $projeto->id]);
        Sanctum::actingAs($user);

        // 4º aluno é rejeitado na FETEC Jr (limite reduzido para 3).
        $this->postJson("/api/v1/projetos/{$projeto->id}/alunos", $this->alunoPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipe');
    }

    public function test_fundect_permite_ate_quatro(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user, Categoria::FetecmsFundect); // máx 4
        Aluno::factory()->count(3)->create(['projeto_id' => $projeto->id]);
        Sanctum::actingAs($user);

        // 4º aluno é permitido na FETECMS FUNDECT.
        $this->postJson("/api/v1/projetos/{$projeto->id}/alunos", $this->alunoPayload())
            ->assertCreated();
    }

    public function test_fetecms_com_pictec_permite_ate_quatro(): void
    {
        $user = User::factory()->create();
        $projeto = Projeto::factory()->create([
            'user_id' => $user->id,
            'categoria' => Categoria::Fetecms,
            'pictec_ms' => true, // PICTEC MS eleva o limite para 4
        ]);
        Aluno::factory()->count(3)->create(['projeto_id' => $projeto->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/alunos", $this->alunoPayload())
            ->assertCreated();

        $this->getJson("/api/v1/projetos/{$projeto->id}/integrantes")
            ->assertOk()
            ->assertJsonPath('data.limites.max_alunos', 4);
    }

    public function test_nao_adiciona_aluno_em_projeto_alheio(): void
    {
        $projetoAlheio = $this->projetoDe(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/projetos/{$projetoAlheio->id}/alunos", $this->alunoPayload())
            ->assertForbidden();
    }

    public function test_nao_gerencia_integrantes_de_projeto_submetido(): void
    {
        $user = User::factory()->create();
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $user->id,
            'categoria' => Categoria::Fetecms,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projetos/{$projeto->id}/alunos", $this->alunoPayload())
            ->assertForbidden();
    }

    public function test_coorientador_e_unico_via_upsert(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user);
        Sanctum::actingAs($user);

        $payload = ['nome' => 'Ana', 'email' => 'ana@x.com', 'cpf' => '111.444.777-35'];
        // 1º upsert cria (201).
        $this->putJson("/api/v1/projetos/{$projeto->id}/coorientador", $payload)->assertCreated();

        // 2º upsert substitui (200) — continua 1 coorientador.
        $this->putJson("/api/v1/projetos/{$projeto->id}/coorientador",
            array_merge($payload, ['nome' => 'Ana Carolina']))->assertOk();

        $this->assertDatabaseCount('coorientadores', 1);
        $this->assertDatabaseHas('coorientadores', ['projeto_id' => $projeto->id, 'nome' => 'Ana Carolina']);
    }

    public function test_integrantes_index_traz_limites_e_equipe(): void
    {
        $user = User::factory()->create();
        $projeto = $this->projetoDe($user, Categoria::Fetecms);
        Aluno::factory()->count(2)->create(['projeto_id' => $projeto->id]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/projetos/{$projeto->id}/integrantes")
            ->assertOk()
            ->assertJsonPath('data.limites.max_alunos', 3)
            ->assertJsonPath('data.limites.alunos_atual', 2)
            ->assertJsonPath('data.orientador.nome', $user->name)
            ->assertJsonCount(2, 'data.alunos');
    }
}
