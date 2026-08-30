<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\TipoDocumento;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\RegistroAtividade;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 64 — "Projetos em rascunho": o admin termina e submete a inscrição que
 * o orientador deixou pela metade, mesmo com o prazo vencido, com justificativa
 * obrigatória e cada alteração na trilha (Registros → Rascunhos).
 */
class AdminRascunhoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    /** Rascunho que já satisfaz todo o checklist — só falta alguém submeter. */
    private function rascunhoCompleto(User $dono, array $over = []): Projeto
    {
        $area = Area::first();
        $estado = Estado::where('uf', 'MS')->first();

        $projeto = Projeto::factory()->create(array_merge([
            'user_id' => $dono->id,
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

    /** Fecha a janela de inscrição: é o cenário em que a tela existe. */
    private function encerrarInscricoes(): void
    {
        Edicao::create([
            'nome' => 'XVI FETECMS',
            'ano' => 2026,
            'inscricoes_abertas' => true,
            'submissoes_ate' => now()->subDay(),
        ]);
    }

    public function test_lista_rascunhos_com_dono_equipe_e_pendencias(): void
    {
        $dono = User::factory()->create(['name' => 'Ana Orientadora']);
        $completo = $this->rascunhoCompleto($dono);
        $incompleto = Projeto::factory()->create([
            'user_id' => $dono->id,
            'titulo' => 'Faltando tudo',
            'categoria' => null,
        ]);
        // Submetido não é rascunho: fica fora da lista.
        Projeto::factory()->submetido()->create(['user_id' => $dono->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $resposta = $this->getJson('/api/v1/admin/projetos-rascunho')->assertOk();

        $ids = array_column($resposta->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$completo->id, $incompleto->id], $ids);

        $porId = collect($resposta->json('data'))->keyBy('id');
        $this->assertTrue($porId[$completo->id]['pronto']);
        $this->assertSame(0, $porId[$completo->id]['pendencias']);
        $this->assertSame(1, $porId[$completo->id]['alunos']);
        $this->assertSame('Ana Orientadora', $porId[$completo->id]['orientador']);
        $this->assertFalse($porId[$incompleto->id]['pronto']);
        $this->assertGreaterThan(0, $porId[$incompleto->id]['pendencias']);
    }

    public function test_lista_filtra_por_busca_area_e_categoria(): void
    {
        $dono = User::factory()->create(['name' => 'Carlos Silva']);
        $area = Area::first();
        $outraArea = Area::where('id', '!=', $area->id)->first();

        $alvo = Projeto::factory()->create([
            'user_id' => $dono->id,
            'titulo' => 'Energia Solar na Escola',
            'area_id' => $area->id,
            'categoria' => Categoria::FetecJr,
        ]);
        Projeto::factory()->create([
            'user_id' => User::factory()->create(['name' => 'Outro'])->id,
            'titulo' => 'Robótica Educacional',
            'area_id' => $outraArea->id,
            'categoria' => Categoria::Fetecms,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/projetos-rascunho?busca=solar')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alvo->id);

        // A busca também alcança o nome do orientador.
        $this->getJson('/api/v1/admin/projetos-rascunho?busca=carlos')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alvo->id);

        $this->getJson("/api/v1/admin/projetos-rascunho?area_id={$area->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alvo->id);

        $this->getJson('/api/v1/admin/projetos-rascunho?categoria='.Categoria::FetecJr->value)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alvo->id);
    }

    public function test_orientador_nao_acessa_a_lista(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/projetos-rascunho')->assertForbidden();
    }

    public function test_admin_submete_rascunho_depois_do_prazo_com_justificativa(): void
    {
        $this->encerrarInscricoes();
        $dono = User::factory()->create();
        $projeto = $this->rascunhoCompleto($dono);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter", [
            'justificativa' => 'Orientador perdeu o prazo por problema no envio; autorizado pela coordenação.',
        ])->assertOk();

        $this->assertDatabaseHas('projetos', ['id' => $projeto->id, 'status' => 'submetido']);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::RascunhoSubmissao)->first();
        $this->assertNotNull($registro);
        $this->assertSame($projeto->id, $registro->projeto_id);
        $this->assertSame($admin->email, $registro->autor_email);
        $this->assertSame($dono->email, $registro->dono_email);
        $this->assertStringContainsString('coordenação', $registro->detalhes['justificativa']);
        $this->assertSame(TipoRegistro::SECAO_RASCUNHOS, $registro->tipo->secao());
    }

    public function test_submissao_do_admin_sem_justificativa_e_recusada(): void
    {
        $this->encerrarInscricoes();
        $projeto = $this->rascunhoCompleto(User::factory()->create());
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")
            ->assertStatus(422)
            ->assertJsonValidationErrors('justificativa');

        $this->assertDatabaseHas('projetos', ['id' => $projeto->id, 'status' => 'rascunho']);
    }

    public function test_orientador_submete_o_proprio_projeto_sem_justificativa(): void
    {
        $dono = User::factory()->create();
        $projeto = $this->rascunhoCompleto($dono);
        Sanctum::actingAs($dono);

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter")->assertOk();

        $this->assertDatabaseHas('projetos', ['id' => $projeto->id, 'status' => 'submetido']);
        $this->assertDatabaseMissing('registros_atividade', ['tipo' => TipoRegistro::RascunhoSubmissao->value]);
    }

    public function test_resumo_avisa_que_o_admin_precisa_justificar(): void
    {
        $projeto = $this->rascunhoCompleto(User::factory()->create());

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/v1/projetos/{$projeto->id}/resumo")
            ->assertOk()
            ->assertJsonPath('data.exige_justificativa', true)
            ->assertJsonPath('data.pode_submeter', true);

        Sanctum::actingAs($projeto->user);
        $this->getJson("/api/v1/projetos/{$projeto->id}/resumo")
            ->assertOk()
            ->assertJsonPath('data.exige_justificativa', false);
    }

    public function test_alteracoes_do_admin_no_rascunho_viram_registro(): void
    {
        $this->encerrarInscricoes();
        $dono = User::factory()->create();
        $projeto = $this->rascunhoCompleto($dono, ['titulo' => 'Título antigo']);
        $outraArea = Area::where('id', '!=', $projeto->area_id)->first();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/projetos/{$projeto->id}", [
            'titulo' => 'Título corrigido',
            'categoria' => Categoria::FetecJr->value,
            'area_id' => $outraArea->id,
            'pais' => 'BR',
        ])->assertOk();

        $registros = RegistroAtividade::where('tipo', TipoRegistro::RascunhoAlteracao)->get();
        $campos = $registros->pluck('detalhes.campo')->all();

        $this->assertContains('Título', $campos);
        $this->assertContains('Categoria', $campos);
        $this->assertContains('Área', $campos);

        $titulo = $registros->firstWhere('detalhes.campo', 'Título');
        $this->assertSame('Título antigo', $titulo->detalhes['de']);
        $this->assertSame('Título corrigido', $titulo->detalhes['para']);
        $this->assertSame($admin->email, $titulo->autor_email);

        // FK vira nome legível, não o id cru.
        $area = $registros->firstWhere('detalhes.campo', 'Área');
        $this->assertSame($outraArea->nome, $area->detalhes['para']);
    }

    public function test_orientador_editando_o_proprio_rascunho_nao_gera_registro(): void
    {
        $dono = User::factory()->create();
        $projeto = $this->rascunhoCompleto($dono, ['titulo' => 'Antes']);
        Sanctum::actingAs($dono);

        $this->putJson("/api/v1/projetos/{$projeto->id}", [
            'titulo' => 'Depois',
            'pais' => 'BR',
        ])->assertOk();

        $this->assertDatabaseMissing('registros_atividade', ['tipo' => TipoRegistro::RascunhoAlteracao->value]);
    }

    public function test_equipe_e_anexos_mexidos_pelo_admin_entram_na_trilha(): void
    {
        $this->encerrarInscricoes();
        $projeto = $this->rascunhoCompleto(User::factory()->create(), ['categoria' => Categoria::FetecJr]);
        $aluno = $projeto->alunos()->first();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // O CPF da factory é numérico aleatório; o FormRequest valida dígito,
        // então a edição manda um CPF válido junto.
        $this->putJson("/api/v1/alunos/{$aluno->id}", array_merge(
            $aluno->only([
                'nome', 'email', 'telefone', 'genero', 'etnia', 'camiseta',
                'instituicao_id', 'modalidade', 'ano_escolar', 'periodo',
            ]),
            [
                'nome' => 'Nome Corrigido',
                'cpf' => '529.982.247-25',
                'data_nascimento' => $aluno->data_nascimento?->format('Y-m-d'),
            ],
        ))->assertOk();

        $this->deleteJson("/api/v1/projetos/{$projeto->id}/coorientador")->assertOk();

        $registros = RegistroAtividade::where('tipo', TipoRegistro::RascunhoAlteracao)->get();
        $campos = $registros->pluck('detalhes.campo')->all();

        $this->assertContains('Aluno · '.$aluno->nome, $campos);
        $this->assertContains('Coorientador', $campos);
    }

    public function test_secao_rascunhos_aparece_na_trilha_do_admin(): void
    {
        $this->encerrarInscricoes();
        $projeto = $this->rascunhoCompleto(User::factory()->create());
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/projetos/{$projeto->id}/submeter", [
            'justificativa' => 'Inscrição validada presencialmente pela organização.',
        ])->assertOk();

        $resposta = $this->getJson('/api/v1/admin/registros?secao='.TipoRegistro::SECAO_RASCUNHOS)
            ->assertOk()
            ->assertJsonPath('meta.secao', TipoRegistro::SECAO_RASCUNHOS);

        $tipos = array_column($resposta->json('data'), 'tipo');
        $this->assertContains(TipoRegistro::RascunhoSubmissao->value, $tipos);

        // A seção é fechada: nada de submissão comum vazando para cá.
        $this->assertNotContains(TipoRegistro::Submissao->value, $tipos);
    }
}
