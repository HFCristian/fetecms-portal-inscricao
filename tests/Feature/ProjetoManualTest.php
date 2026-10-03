<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 157 — cadastro manual de projeto: quem vai ao evento sem ter passado
 * pela inscrição entra direto na lista final, fora da avaliação online.
 */
class ProjetoManualTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private User $admin;

    private Area $area;

    private Instituicao $escola;

    private ListaFinal $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->escola = Instituicao::create(['nome' => 'EE Maria Constança']);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista oficial', 'vigente' => true, 'demo' => false, 'versao' => 1,
        ]);

        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        // Listas (alunos) são trocadas inteiras; o resto é mesclado.
        return array_replace([
            'titulo' => 'Biofiltro de casca de arroz',
            'categoria' => 'fetecms',
            'instituicao_id' => $this->escola->id,
            'area_id' => $this->area->id,
            'origem' => 'finalista',
            'orientador' => ['nome' => 'Marta Orientadora', 'email' => 'marta@escola.test'],
            'alunos' => [
                ['nome' => 'Ana Paula'],
                ['nome' => 'Bruno Lima', 'email' => 'bruno@escola.test', 'cpf' => '111.444.777-35'],
            ],
            'justificativa' => 'Equipe enviada pela feira regional só com os nomes.',
        ], $extra);
    }

    public function test_cadastra_com_orientador_novo_e_entra_na_lista_final(): void
    {
        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.projetos.0.titulo', 'Biofiltro de casca de arroz')
            ->assertJsonPath('data.projetos.0.finalista', true)
            ->assertJsonPath('data.projetos.0.origem', 'finalista');

        $projeto = Projeto::where('titulo', 'Biofiltro de casca de arroz')->firstOrFail();
        $this->assertTrue($projeto->cadastro_manual);
        $this->assertSame('submetido', $projeto->status->value);
        $this->assertSame($this->edicao->id, $projeto->edicao_id);
        $this->assertCount(2, $projeto->alunos);
        // Sem CPF nem e-mail: a organização só tinha o nome.
        $this->assertNull($projeto->alunos->firstWhere('nome', 'Ana Paula')->cpf);
        $this->assertSame('11144477735', $projeto->alunos->firstWhere('nome', 'Bruno Lima')->cpf);

        // A conta do orientador nasce ativa, de orientador, com perfil.
        $orientador = $projeto->user;
        $this->assertSame(Role::Orientador, $orientador->role);
        $this->assertSame('marta@escola.test', $orientador->email);
        $this->assertNotNull($orientador->orientadorProfile);

        // Entrou na lista (marcado como manual) e a versão subiu.
        $this->assertTrue($this->lista->projetos()->whereKey($projeto->id)->exists());
        $this->assertSame(2, (int) $this->lista->fresh()->versao);

        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::ProjetoCadastroManual)->exists());
        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::ListaFinalProjetoAdicionado)->exists());
    }

    public function test_usa_a_conta_de_orientador_que_ja_tem_o_email(): void
    {
        $existente = User::factory()->create(['email' => 'marta@escola.test', 'role' => Role::Orientador]);

        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload())->assertCreated();

        $this->assertSame($existente->id, Projeto::firstOrFail()->user_id);
        $this->assertSame(1, User::where('email', 'marta@escola.test')->count());
    }

    public function test_email_de_avaliador_nao_vira_orientador(): void
    {
        User::factory()->avaliador()->create(['email' => 'marta@escola.test']);

        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('orientador.email');

        $this->assertSame(0, Projeto::count());
    }

    public function test_sem_lista_final_publicada_nao_cadastra(): void
    {
        $this->lista->update(['vigente' => false]);

        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lista');
    }

    public function test_credencial_de_feira_afiliada_e_ja_credenciado(): void
    {
        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload([
            'origem' => 'credencial',
            'feira_afiliada_nome' => 'Feira de Ciências de Dourados',
            'numero_credencial' => 'FCD-07',
            'credenciado' => true,
        ]))->assertCreated()->assertJsonPath('data.projetos.0.credenciado', true);

        $projeto = Projeto::firstOrFail();
        $this->assertTrue($projeto->feira_afiliada);
        $this->assertSame('Feira de Ciências de Dourados', $projeto->feira_afiliada_nome);
        $this->assertSame('FCD-07', $projeto->numero_credencial);
        $this->assertNotNull($projeto->credenciamento->finalizado_em);
        $this->assertCount(3, $projeto->credenciamento->pessoas); // 2 alunos + orientador
    }

    public function test_credencial_sem_o_nome_da_feira_e_recusada(): void
    {
        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload(['origem' => 'credencial']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('feira_afiliada_nome');
    }

    public function test_respeita_o_teto_de_estudantes_da_categoria(): void
    {
        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload([
            'categoria' => 'fetec_jr',
            'alunos' => [['nome' => 'A'], ['nome' => 'B'], ['nome' => 'C'], ['nome' => 'D']],
        ]))->assertStatus(422)->assertJsonValidationErrors('alunos');
    }

    public function test_fica_fora_da_avaliacao_online(): void
    {
        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload())->assertCreated();

        $this->assertSame(0, Projeto::avaliacaoOnline()->count());
        $this->getJson('/api/v1/admin/avaliacao/projetos')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_atualiza_a_equipe_e_registra_o_que_mudou(): void
    {
        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload())->assertCreated();
        $projeto = Projeto::firstOrFail();
        $ana = $projeto->alunos->firstWhere('nome', 'Ana Paula');

        $this->putJson("/api/v1/admin/avaliacao/projetos-manuais/{$projeto->id}", $this->payload([
            'titulo' => 'Biofiltro de casca de arroz parboilizado',
            'alunos' => [
                ['id' => $ana->id, 'nome' => 'Ana Paula Souza'],
                ['nome' => 'Caio Novo'],
            ],
            'coorientador' => ['nome' => 'Rita Coorientadora'],
            'justificativa' => 'Nome completo chegou depois.',
        ]))->assertOk();

        $projeto->refresh()->load('alunos', 'coorientador');
        $this->assertSame('Biofiltro de casca de arroz parboilizado', $projeto->titulo);
        $this->assertEqualsCanonicalizing(['Ana Paula Souza', 'Caio Novo'], $projeto->alunos->pluck('nome')->all());
        $this->assertSame($ana->id, $projeto->alunos->firstWhere('nome', 'Ana Paula Souza')->id);
        $this->assertSame('Rita Coorientadora', $projeto->coorientador->nome);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::ProjetoManualAlterado)->firstOrFail();
        $this->assertStringContainsString('título', $registro->detalhes['resumo']);
        $this->assertStringContainsString('estudantes', $registro->detalhes['resumo']);
    }

    public function test_exclui_e_tira_da_lista(): void
    {
        $this->postJson('/api/v1/admin/avaliacao/projetos-manuais', $this->payload())->assertCreated();
        $projeto = Projeto::firstOrFail();

        $this->deleteJson("/api/v1/admin/avaliacao/projetos-manuais/{$projeto->id}", ['justificativa' => 'Cadastro duplicado.'])
            ->assertOk()
            ->assertJsonCount(0, 'data.projetos');

        $this->assertSoftDeleted($projeto);
        $this->assertFalse($this->lista->projetos()->whereKey($projeto->id)->exists());
    }

    public function test_projeto_da_inscricao_nao_e_editado_por_aqui(): void
    {
        $projeto = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);

        $this->getJson("/api/v1/admin/avaliacao/projetos-manuais/{$projeto->id}")->assertNotFound();
    }
}
