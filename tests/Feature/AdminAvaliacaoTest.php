<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    private function avaliador(int $areaId, string $nome): User
    {
        $user = User::factory()->avaliador()->create([
            'name' => $nome,
            // E-mail derivado do nome: a busca por texto fica previsível nos testes.
            'email' => Str::slug($nome).'-'.Str::random(6).'@avaliadores.test',
        ]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);

        return $user;
    }

    public function test_tabela_de_avaliadores_com_progresso(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);

        $ana = $this->avaliador($a->id, 'Ana');   // 2 concluídas + 1 em andamento
        $this->avaliador($a->id, 'Bruno');        // nada
        $carlos = $this->avaliador($b->id, 'Carlos'); // 3 concluídas

        $orient = User::factory()->create();
        $p1 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Projeto A1']);
        $p2 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Projeto A2']);
        $p3 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $b->id, 'titulo' => 'Projeto B1']);

        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $ana->id, 'status' => 'concluida', 'nota' => 8]);
        Avaliacao::create(['projeto_id' => $p2->id, 'avaliador_id' => $ana->id, 'status' => 'concluida', 'nota' => 9]);
        Avaliacao::create(['projeto_id' => $p3->id, 'avaliador_id' => $ana->id, 'status' => 'em_andamento']);
        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $carlos->id, 'status' => 'concluida', 'nota' => 7]);
        Avaliacao::create(['projeto_id' => $p2->id, 'avaliador_id' => $carlos->id, 'status' => 'concluida', 'nota' => 7]);
        Avaliacao::create(['projeto_id' => $p3->id, 'avaliador_id' => $carlos->id, 'status' => 'concluida', 'nota' => 7]);

        Sanctum::actingAs(User::factory()->admin()->create());

        // Uma tabela só, em ordem alfabética por padrão.
        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.nome', 'Ana')
            ->assertJsonPath('data.0.area', 'Área A')
            ->assertJsonPath('data.0.em_avaliacao', 1)
            ->assertJsonPath('data.0.avaliou', 2)
            ->assertJsonPath('data.0.faltam', 1)
            ->assertJsonPath('data.1.nome', 'Bruno')
            ->assertJsonPath('data.1.avaliou', 0)
            ->assertJsonPath('data.1.faltam', 3)
            ->assertJsonPath('data.2.nome', 'Carlos')
            ->assertJsonPath('data.2.area', 'Área B')
            ->assertJsonPath('data.2.avaliou', 3)
            ->assertJsonPath('data.2.faltam', 0)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_tabela_de_avaliadores_busca_filtra_e_ordena(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);

        $ana = $this->avaliador($a->id, 'Ana');
        $this->avaliador($a->id, 'Bruno');
        $this->avaliador($b->id, 'Carlos');

        $orient = User::factory()->create();
        $p1 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'P1']);
        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $ana->id, 'status' => 'concluida', 'nota' => 8]);

        Sanctum::actingAs(User::factory()->admin()->create());

        // Busca por nome (e também acha por e-mail).
        $this->getJson('/api/v1/admin/avaliacao/avaliadores?q=bru')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Bruno');

        $this->getJson('/api/v1/admin/avaliacao/avaliadores?q='.urlencode($ana->email))
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Ana');

        // Filtro por área.
        $this->getJson("/api/v1/admin/avaliacao/avaliadores?area_id={$b->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Carlos');

        // Ordenação por nome, decrescente.
        $this->getJson('/api/v1/admin/avaliacao/avaliadores?ordenar=nome&direcao=desc')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Carlos')
            ->assertJsonPath('data.2.nome', 'Ana');

        // "Faltam" é o espelho de "avaliadas": quem avaliou mais falta menos.
        $this->getJson('/api/v1/admin/avaliacao/avaliadores?ordenar=faltam&direcao=asc')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Ana');

        // As áreas do filtro vêm no meta.
        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertJsonPath('meta.areas.0.nome', 'Área A')
            ->assertJsonPath('meta.areas.1.nome', 'Área B');
    }

    public function test_ordenacao_invalida_e_recusada(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores?ordenar=senha')
            ->assertStatus(422)
            ->assertJsonValidationErrors('ordenar');
    }

    public function test_exporta_a_tabela_de_avaliadores_em_csv(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id, 'Ana');
        $this->avaliador($a->id, 'Bruno');

        Sanctum::actingAs(User::factory()->admin()->create());

        $csv = $this->get('/api/v1/admin/avaliacao/avaliadores/exportar?q=ana')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Nome;E-mail;Área', $csv);
        $this->assertStringContainsString('Ana', $csv);
        $this->assertStringNotContainsString('Bruno', $csv); // respeita o filtro da tela
    }

    public function test_opcoes_de_avaliadores_para_a_designacao(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id, 'Zilda');
        $this->avaliador($a->id, 'Ana');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores/opcoes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.nome', 'Ana')
            ->assertJsonPath('data.0.area', 'Área A')
            ->assertJsonPath('data.1.nome', 'Zilda');
    }

    public function test_tabela_de_projetos_submetidos_com_metricas(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);

        $ana = $this->avaliador($a->id, 'Ana');
        $carlos = $this->avaliador($b->id, 'Carlos');

        $orient = User::factory()->create();
        $p1 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Projeto A1']);
        $p2 = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $b->id, 'titulo' => 'Projeto B1']);
        // rascunho não deve aparecer
        Projeto::factory()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Rascunho']);

        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $ana->id, 'status' => 'concluida', 'nota' => 8]);
        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $carlos->id, 'status' => 'concluida', 'nota' => 9]);
        Avaliacao::create(['projeto_id' => $p2->id, 'avaliador_id' => $ana->id, 'status' => 'em_andamento']);

        Sanctum::actingAs(User::factory()->admin()->create());

        // Uma tabela só, por título; o rascunho não entra.
        $this->getJson('/api/v1/admin/avaliacao/projetos')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.titulo', 'Projeto A1')
            ->assertJsonPath('data.0.area', 'Área A')
            ->assertJsonPath('data.0.realizadas', 2)
            ->assertJsonPath('data.0.em_avaliacao', 0)
            ->assertJsonPath('data.0.faltantes', 1)
            ->assertJsonPath('data.1.titulo', 'Projeto B1')
            ->assertJsonPath('data.1.realizadas', 0)
            ->assertJsonPath('data.1.em_avaliacao', 1)
            ->assertJsonPath('data.1.faltantes', 3)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_tabela_de_projetos_busca_filtra_ordena_e_exporta(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);
        $ana = $this->avaliador($a->id, 'Ana');
        $orient = User::factory()->create();

        $p1 = Projeto::factory()->submetido()->create([
            'user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Bioplástico de mandioca',
            'categoria' => 'fetec_jr',
        ]);
        Projeto::factory()->submetido()->create([
            'user_id' => $orient->id, 'area_id' => $b->id, 'titulo' => 'Zebrafish e poluentes',
            'categoria' => 'fetecms',
        ]);
        Avaliacao::create(['projeto_id' => $p1->id, 'avaliador_id' => $ana->id, 'status' => 'concluida', 'nota' => 8]);

        Sanctum::actingAs(User::factory()->admin()->create());

        // Busca por título.
        $this->getJson('/api/v1/admin/avaliacao/projetos?q=mandioca')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'Bioplástico de mandioca');

        // Filtro por área e por categoria.
        $this->getJson("/api/v1/admin/avaliacao/projetos?area_id={$b->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'Zebrafish e poluentes');

        $this->getJson('/api/v1/admin/avaliacao/projetos?categoria=fetec_jr')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.categoria_label', 'FETEC Jr');

        // Ordenação: "faltantes" é o espelho de "realizadas".
        $this->getJson('/api/v1/admin/avaliacao/projetos?ordenar=faltantes&direcao=asc')
            ->assertOk()
            ->assertJsonPath('data.0.titulo', 'Bioplástico de mandioca');

        $this->getJson('/api/v1/admin/avaliacao/projetos?ordenar=titulo&direcao=desc')
            ->assertOk()
            ->assertJsonPath('data.0.titulo', 'Zebrafish e poluentes');

        // As opções dos filtros vêm no meta.
        $this->getJson('/api/v1/admin/avaliacao/projetos')
            ->assertJsonPath('meta.areas.0.nome', 'Área A')
            ->assertJsonCount(3, 'meta.categorias');

        // CSV do mesmo recorte.
        $csv = $this->get('/api/v1/admin/avaliacao/projetos/exportar?categoria=fetec_jr')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Título;Área;Subárea;Categoria', $csv);
        $this->assertStringContainsString('Bioplástico de mandioca', $csv);
        $this->assertStringNotContainsString('Zebrafish', $csv);
    }

    public function test_ordenacao_invalida_de_projetos_e_recusada(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/projetos?ordenar=nota')
            ->assertStatus(422)
            ->assertJsonValidationErrors('ordenar');
    }

    public function test_admin_designa_projeto_a_avaliador_especifico(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');
        $proj = Projeto::factory()->submetido()->create(['user_id' => User::factory()->create()->id, 'area_id' => $a->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$proj->id}/designar", [
            'tipo' => 'avaliador', 'alvo_id' => $ana->id,
        ])->assertOk()->assertJsonPath('data.designadas', 1);

        $this->assertDatabaseHas('avaliacoes', [
            'projeto_id' => $proj->id, 'avaliador_id' => $ana->id, 'status' => 'designada',
        ]);

        // Idempotente: designar de novo não duplica.
        $this->postJson("/api/v1/admin/avaliacao/projetos/{$proj->id}/designar", [
            'tipo' => 'avaliador', 'alvo_id' => $ana->id,
        ])->assertOk()->assertJsonPath('data.designadas', 0);
    }

    public function test_admin_designa_projeto_a_todos_de_uma_area(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);
        $this->avaliador($a->id, 'Ana');
        $this->avaliador($a->id, 'Bruno');
        $this->avaliador($b->id, 'Carlos'); // outra área: não designa

        $proj = Projeto::factory()->submetido()->create(['user_id' => User::factory()->create()->id, 'area_id' => $a->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$proj->id}/designar", [
            'tipo' => 'area', 'alvo_id' => $a->id,
        ])->assertOk()->assertJsonPath('data.designadas', 2);

        $this->assertSame(2, Avaliacao::where('projeto_id', $proj->id)->count());
    }

    public function test_nao_designa_projeto_nao_submetido(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');
        $rascunho = Projeto::factory()->create(['user_id' => User::factory()->create()->id, 'area_id' => $a->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/avaliacao/projetos/{$rascunho->id}/designar", [
            'tipo' => 'avaliador', 'alvo_id' => $ana->id,
        ])->assertStatus(422);
    }

    public function test_avaliador_comeca_sem_limite(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $this->avaliador($a->id, 'Ana');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertOk()
            ->assertJsonPath('data.0.limite', null);
    }

    public function test_admin_define_e_remove_limite_do_avaliador(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$ana->id}/limite", ['limite' => 2])
            ->assertOk()
            ->assertJsonPath('data.limite', 2);
        $this->assertDatabaseHas('avaliador_profiles', ['user_id' => $ana->id, 'limite_avaliacoes' => 2]);

        // A lista passa a exibir o limite.
        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertJsonPath('data.0.limite', 2);

        // Remover o limite (null).
        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$ana->id}/limite", ['limite' => null])
            ->assertOk()
            ->assertJsonPath('data.limite', null);
        $this->assertDatabaseHas('avaliador_profiles', ['user_id' => $ana->id, 'limite_avaliacoes' => null]);
    }

    public function test_limitar_exige_que_o_alvo_seja_avaliador(): void
    {
        $orientador = User::factory()->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$orientador->id}/limite", ['limite' => 2])
            ->assertStatus(404);
    }

    public function test_atingiu_limite_considera_as_assumidas(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');
        $perfil = $ana->avaliadorProfile;

        $perfil->update(['limite_avaliacoes' => 2]);
        $this->assertTrue($perfil->atingiuLimite(2));   // 2 assumidas >= limite 2
        $this->assertFalse($perfil->atingiuLimite(1));  // ainda pode assumir

        $perfil->update(['limite_avaliacoes' => null]); // sem limite nunca bloqueia
        $this->assertFalse($perfil->atingiuLimite(99));
    }

    public function test_marca_avaliador_como_demo_e_a_lista_reflete(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $ana = $this->avaliador($a->id, 'Ana');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertJsonPath('data.0.is_demo', false);

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$ana->id}/demo", ['is_demo' => true])
            ->assertOk()
            ->assertJsonPath('data.is_demo', true);
        $this->assertDatabaseHas('users', ['id' => $ana->id, 'is_demo' => true]);

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertJsonPath('data.0.is_demo', true);
    }

    public function test_limpar_dados_de_teste_apaga_so_dos_demo(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $demo = $this->avaliador($a->id, 'Demo');
        $demo->update(['is_demo' => true]);
        $real = $this->avaliador($a->id, 'Real');

        $orient = User::factory()->create();
        $p = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id]);
        Avaliacao::create(['projeto_id' => $p->id, 'avaliador_id' => $demo->id, 'status' => 'concluida', 'nota' => 8]);
        Avaliacao::create(['projeto_id' => $p->id, 'avaliador_id' => $real->id, 'status' => 'concluida', 'nota' => 9]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson('/api/v1/admin/avaliacao/testes')
            ->assertOk()
            ->assertJsonPath('data.apagadas', 1);

        $this->assertDatabaseMissing('avaliacoes', ['avaliador_id' => $demo->id]);
        $this->assertDatabaseHas('avaliacoes', ['avaliador_id' => $real->id]);
    }

    public function test_demo_exige_que_o_alvo_seja_avaliador(): void
    {
        $orientador = User::factory()->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/avaliacao/avaliadores/{$orientador->id}/demo", ['is_demo' => true])
            ->assertStatus(404);
    }

    public function test_nao_admin_nao_acessa_avaliacao_online(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')->assertStatus(403);
        $this->getJson('/api/v1/admin/avaliacao/projetos')->assertStatus(403);
    }

    public function test_resumo_por_area_conta_projetos_por_faixa_de_avaliacoes(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);
        $orient = User::factory()->create();

        $avaliadores = [
            $this->avaliador($a->id, 'Av1'),
            $this->avaliador($a->id, 'Av2'),
            $this->avaliador($a->id, 'Av3'),
        ];

        // Área A: um com 0, um com 2 e um com 3 avaliações concluídas.
        $semAvaliacao = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'A-zero']);
        $comDuas = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'A-duas']);
        $comTres = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'A-tres']);
        // Área B: um com 1.
        $comUma = Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $b->id, 'titulo' => 'B-uma']);

        foreach (array_slice($avaliadores, 0, 2) as $av) {
            Avaliacao::create(['projeto_id' => $comDuas->id, 'avaliador_id' => $av->id, 'status' => 'concluida', 'nota' => 8]);
        }
        foreach ($avaliadores as $av) {
            Avaliacao::create(['projeto_id' => $comTres->id, 'avaliador_id' => $av->id, 'status' => 'concluida', 'nota' => 8]);
        }
        Avaliacao::create(['projeto_id' => $comUma->id, 'avaliador_id' => $avaliadores[0]->id, 'status' => 'concluida', 'nota' => 8]);
        // Em andamento não conta como concluída.
        Avaliacao::create(['projeto_id' => $semAvaliacao->id, 'avaliador_id' => $avaliadores[0]->id, 'status' => 'em_andamento']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/projetos')
            ->assertOk()
            ->assertJsonPath('meta.min_por_projeto', 3)
            ->assertJsonPath('meta.resumo_areas.0.area', 'Área A')
            ->assertJsonPath('meta.resumo_areas.0.zero', 1)
            ->assertJsonPath('meta.resumo_areas.0.uma', 0)
            ->assertJsonPath('meta.resumo_areas.0.duas', 1)
            ->assertJsonPath('meta.resumo_areas.0.tres_ou_mais', 1)
            ->assertJsonPath('meta.resumo_areas.0.total', 3)
            ->assertJsonPath('meta.resumo_areas.0.completos', 1)
            // O card destacado soma as áreas: 4 projetos, 1 sem avaliação, 1 com
            // uma, 1 com duas e 1 com três (o único que bateu o mínimo).
            ->assertJsonPath('meta.resumo_geral.total', 4)
            ->assertJsonPath('meta.resumo_geral.zero', 1)
            ->assertJsonPath('meta.resumo_geral.uma', 1)
            ->assertJsonPath('meta.resumo_geral.duas', 1)
            ->assertJsonPath('meta.resumo_geral.tres_ou_mais', 1)
            ->assertJsonPath('meta.resumo_geral.completos', 1)
            ->assertJsonPath('meta.resumo_areas.1.area', 'Área B')
            ->assertJsonPath('meta.resumo_areas.1.uma', 1);
    }

    public function test_resumo_por_area_respeita_os_filtros_da_tabela(): void
    {
        $a = Area::create(['nome' => 'Área A']);
        $b = Area::create(['nome' => 'Área B']);
        $orient = User::factory()->create();

        // As duas categorias são explícitas: a factory sorteia, e o filtro abaixo
        // depende de os dois projetos estarem em categorias diferentes.
        Projeto::factory()->submetido()->create([
            'user_id' => $orient->id, 'area_id' => $a->id, 'titulo' => 'Da A', 'categoria' => 'fetec_jr',
        ]);
        Projeto::factory()->submetido()->create([
            'user_id' => $orient->id, 'area_id' => $b->id, 'titulo' => 'Da B', 'categoria' => 'fetecms',
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/avaliacao/projetos?area_id={$b->id}")
            ->assertOk()
            ->assertJsonCount(1, 'meta.resumo_areas')
            ->assertJsonPath('meta.resumo_areas.0.area', 'Área B');

        $this->getJson('/api/v1/admin/avaliacao/projetos?categoria=fetecms')
            ->assertOk()
            ->assertJsonCount(1, 'meta.resumo_areas')
            ->assertJsonPath('meta.resumo_areas.0.area', 'Área B');
    }
}
