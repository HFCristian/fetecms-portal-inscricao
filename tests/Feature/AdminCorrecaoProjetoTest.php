<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCorrecaoProjetoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function projeto(array $over = []): Projeto
    {
        return Projeto::factory()->submetido()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'categoria' => Categoria::Fetecms->value,
            'area_id' => Area::first()->id,
            'link_video' => 'https://youtu.be/antigo00000',
        ], $over));
    }

    public function test_admin_corrige_categoria_area_subarea_e_video(): void
    {
        $projeto = $this->projeto();
        $outra = Area::where('id', '!=', $projeto->area_id)->first();
        $subarea = $outra->subareas()->first();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}", [
            'categoria' => Categoria::FetecJr->value,
            'area_id' => $outra->id,
            'subarea_id' => $subarea->id,
            'link_video' => 'https://youtu.be/novo0000000',
            'justificativa' => 'Classificação corrigida pela coordenação.',
        ])
            ->assertOk()
            ->assertJsonPath('data.categoria', Categoria::FetecJr->value)
            ->assertJsonPath('data.area_id', $outra->id)
            ->assertJsonPath('data.subarea_id', $subarea->id)
            ->assertJsonPath('data.link_video', 'https://youtu.be/novo0000000');

        $this->assertDatabaseHas('projetos', [
            'id' => $projeto->id,
            'categoria' => Categoria::FetecJr->value,
            'area_id' => $outra->id,
            'subarea_id' => $subarea->id,
            'link_video' => 'https://youtu.be/novo0000000',
        ]);

        // Um registro por campo alterado, todos na seção Projetos e com a justificativa.
        foreach ([TipoRegistro::ProjetoCategoria, TipoRegistro::ProjetoArea, TipoRegistro::ProjetoSubarea, TipoRegistro::ProjetoVideo] as $tipo) {
            $this->assertDatabaseHas('registros_atividade', [
                'tipo' => $tipo->value,
                'projeto_id' => $projeto->id,
                'autor_email' => $admin->email,
            ]);
        }

        $registro = RegistroAtividade::where('tipo', TipoRegistro::ProjetoCategoria->value)->first();
        $this->assertSame('FETECMS', $registro->detalhes['de']);
        $this->assertSame('FETEC Jr', $registro->detalhes['para']);
        $this->assertSame('Classificação corrigida pela coordenação.', $registro->detalhes['justificativa']);
    }

    public function test_justificativa_e_obrigatoria(): void
    {
        $projeto = $this->projeto();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}", [
            'categoria' => Categoria::FetecJr->value,
        ])->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->assertDatabaseCount('registros_atividade', 0);
    }

    public function test_campo_sem_mudanca_nao_gera_registro(): void
    {
        $projeto = $this->projeto();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}", [
            'categoria' => $projeto->categoria->value,
            'area_id' => $projeto->area_id,
            'justificativa' => 'Conferência de rotina.',
        ])->assertOk();

        $this->assertDatabaseMissing('registros_atividade', ['tipo' => TipoRegistro::ProjetoCategoria->value]);
        $this->assertDatabaseMissing('registros_atividade', ['tipo' => TipoRegistro::ProjetoArea->value]);
    }

    public function test_trocar_a_area_sem_informar_subarea_limpa_a_subarea(): void
    {
        $area = Area::first();
        $projeto = $this->projeto(['area_id' => $area->id, 'subarea_id' => $area->subareas()->first()->id]);
        $outra = Area::where('id', '!=', $area->id)->first();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}", [
            'area_id' => $outra->id,
            'justificativa' => 'Área trocada a pedido do avaliador.',
        ])->assertOk()->assertJsonPath('data.subarea_id', null);

        $this->assertDatabaseHas('projetos', ['id' => $projeto->id, 'subarea_id' => null]);
    }

    public function test_subarea_de_outra_area_e_rejeitada(): void
    {
        $area = Area::first();
        $projeto = $this->projeto(['area_id' => $area->id]);
        $outra = Area::where('id', '!=', $area->id)->first();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}", [
            'subarea_id' => $outra->subareas()->first()->id,
            'justificativa' => 'Tentativa inconsistente.',
        ])->assertStatus(422)->assertJsonValidationErrors('subarea_id');
    }

    public function test_so_admin_corrige(): void
    {
        $projeto = $this->projeto();
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}", [
            'categoria' => Categoria::FetecJr->value,
            'justificativa' => 'Não deveria passar.',
        ])->assertForbidden();
    }

    /**
     * Sprint 90 — a série de cada aluno viaja com o projeto, para o diálogo de
     * correção mostrá-la abaixo da categoria: é ela que diz se a categoria está
     * certa (FETEC Jr é do fundamental, as demais do médio).
     */
    public function test_a_listagem_leva_a_serie_de_cada_aluno(): void
    {
        $projeto = $this->projeto();
        Aluno::factory()->create([
            'projeto_id' => $projeto->id,
            'nome' => 'Ana Aluna',
            'modalidade' => 'medio',
            'ano_escolar' => '2_em',
        ]);
        // Sem série cadastrada: a linha existe, o rótulo é nulo.
        Aluno::factory()->create([
            'projeto_id' => $projeto->id,
            'nome' => 'Bruno Aluno',
            'modalidade' => null,
            'ano_escolar' => null,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $linha = collect($this->getJson('/api/v1/admin/avaliacao/projetos')->assertOk()->json('data'))
            ->firstWhere('id', $projeto->id);

        $alunos = collect($linha['alunos'])->keyBy('nome');

        $this->assertSame('2º ano do Ensino Médio', $alunos['Ana Aluna']['serie']);
        $this->assertNull($alunos['Bruno Aluno']['serie']);
    }

    /** E o técnico integrado aparece com nome próprio, mesmo contando no médio. */
    public function test_tecnico_integrado_tem_rotulo_proprio(): void
    {
        $projeto = $this->projeto();
        Aluno::factory()->create([
            'projeto_id' => $projeto->id,
            'nome' => 'Clara Técnica',
            'modalidade' => 'tecnico_integrado',
            'ano_escolar' => '4_em',
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $linha = collect($this->getJson('/api/v1/admin/avaliacao/projetos')->assertOk()->json('data'))
            ->firstWhere('id', $projeto->id);

        $this->assertSame('4º ano do Ensino Técnico Integrado', $linha['alunos'][0]['serie']);
    }
}
