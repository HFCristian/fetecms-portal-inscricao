<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Cidade;
use App\Models\Coorientador;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Avaliação presencial → Credenciais: as vagas de premiação, a quem foram
 * dadas e a lista da cerimônia.
 */
class CredenciaisPremiacaoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Projeto $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDays(2),
        ]);

        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $estado->id, 'capital' => true]);
        $escola = Instituicao::create(['nome' => 'EE Maria Constança', 'cidade_id' => $cidade->id]);

        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value, 'name' => 'Marta Orientadora'])->id,
            'titulo' => 'Bioplástico de mandioca',
            'area_id' => Area::create(['nome' => 'Ciências Agrárias'])->id,
            'instituicao_id' => $escola->id,
        ]);

        Aluno::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Ana Paula']);
    }

    private function publicar(array $projetos): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'vigente' => true, 'versao' => 1,
        ]);
        $lista->projetos()->attach(collect($projetos)->mapWithKeys(fn ($p) => [$p->id => ['manual' => false]])->all());

        return $lista;
    }

    private function comoAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function credencial(array $over = []): Credencial
    {
        return Credencial::create(array_merge([
            'edicao_id' => $this->edicao->id,
            'nome' => 'MOSTRATEC 2027',
            'orgao' => 'FUNDECT',
            'vagas' => 2,
        ], $over));
    }

    public function test_cadastra_credencial_com_vagas(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/credenciais', [
            'nome' => 'MOSTRATEC 2027', 'orgao' => 'FUNDECT', 'vagas' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.0.nome', 'MOSTRATEC 2027')
            ->assertJsonPath('data.0.vagas', 3)
            ->assertJsonPath('data.0.usadas', 0)
            ->assertJsonPath('data.0.disponiveis', 3);
    }

    public function test_credencial_sem_vagas_nao_tem_teto(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/credenciais', ['nome' => 'Menção honrosa'])
            ->assertCreated()
            ->assertJsonPath('data.0.vagas', null)
            ->assertJsonPath('data.0.disponiveis', null);
    }

    public function test_anexa_a_credencial_a_um_finalista(): void
    {
        $this->publicar([$this->projeto]);
        $credencial = $this->credencial();
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", [
            'projeto_id' => $this->projeto->id,
            'observacao' => 'Melhor nota da categoria.',
        ])
            ->assertOk()
            ->assertJsonPath('data.0.usadas', 1)
            ->assertJsonPath('data.0.projetos.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('data.0.projetos.0.observacao', 'Melhor nota da categoria.');
    }

    public function test_projeto_nao_finalista_nao_recebe_credencial(): void
    {
        $fora = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
        ]);
        $this->publicar([$this->projeto]);
        $credencial = $this->credencial();
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", [
            'projeto_id' => $fora->id,
        ])->assertStatus(422)->assertJsonValidationErrors('projeto_id');
    }

    public function test_o_teto_de_vagas_e_respeitado(): void
    {
        $outro = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
        ]);
        $terceiro = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
        ]);
        $this->publicar([$this->projeto, $outro, $terceiro]);
        $credencial = $this->credencial(['vagas' => 2]);
        $this->comoAdmin();

        foreach ([$this->projeto, $outro] as $p) {
            $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $p->id])
                ->assertOk();
        }

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $terceiro->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto_id');
    }

    public function test_mesmo_projeto_nao_recebe_a_mesma_credencial_duas_vezes(): void
    {
        $this->publicar([$this->projeto]);
        $credencial = $this->credencial();
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $this->projeto->id])
            ->assertOk();
        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $this->projeto->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto_id');
    }

    public function test_retira_a_credencial_do_projeto(): void
    {
        $this->publicar([$this->projeto]);
        $credencial = $this->credencial();
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $this->projeto->id])
            ->assertOk();

        $this->deleteJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos/{$this->projeto->id}")
            ->assertOk()
            ->assertJsonPath('data.0.usadas', 0);
    }

    public function test_credencial_ja_dada_nao_e_excluida_nem_encolhe(): void
    {
        $this->publicar([$this->projeto]);
        $credencial = $this->credencial(['vagas' => 2]);
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $this->projeto->id])
            ->assertOk();

        $this->deleteJson("/api/v1/admin/presencial/credenciais/{$credencial->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('credencial');

        // Reduzir as vagas abaixo do que já foi dado esconderia uma premiação.
        $this->patchJson("/api/v1/admin/presencial/credenciais/{$credencial->id}", ['vagas' => 0])
            ->assertStatus(422);
    }

    public function test_credencial_desativada_nao_recebe_projeto(): void
    {
        $this->publicar([$this->projeto]);
        $credencial = $this->credencial();
        $this->comoAdmin();

        $this->patchJson("/api/v1/admin/presencial/credenciais/{$credencial->id}", ['ativa' => false])->assertOk();

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $this->projeto->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credencial');
    }

    public function test_lista_de_premiacao_sai_em_txt_com_a_equipe(): void
    {
        Coorientador::factory()->create(['projeto_id' => $this->projeto->id, 'nome' => 'Carlos Pereira']);
        $this->publicar([$this->projeto]);
        $credencial = $this->credencial();
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $this->projeto->id])
            ->assertOk();

        $txt = $this->get('/api/v1/admin/presencial/credenciais/premiacao/arquivo')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('LISTA DE PREMIAÇÃO', $txt);
        $this->assertStringContainsString('MOSTRATEC 2027 — FUNDECT', $txt);
        $this->assertStringContainsString('Bioplástico de mandioca', $txt);
        $this->assertStringContainsString('EE Maria Constança / Campo Grande - MS', $txt);
        $this->assertStringContainsString('Ana Paula', $txt);
        $this->assertStringContainsString('Marta Orientadora - Orientador(a)', $txt);
        $this->assertStringContainsString('Carlos Pereira - Coorientador(a)', $txt);
    }

    public function test_credencial_sem_projeto_aparece_na_lista_como_vazia(): void
    {
        $this->publicar([$this->projeto]);
        $this->credencial();
        $this->comoAdmin();

        $this->assertStringContainsString(
            '(nenhum projeto credenciado)',
            $this->get('/api/v1/admin/presencial/credenciais/premiacao/arquivo')->getContent(),
        );
    }

    public function test_candidatos_sao_os_finalistas_com_o_que_ja_receberam(): void
    {
        $this->publicar([$this->projeto]);
        $credencial = $this->credencial();
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/presencial/credenciais/{$credencial->id}/projetos", ['projeto_id' => $this->projeto->id])
            ->assertOk();

        $this->getJson('/api/v1/admin/presencial/credenciais')
            ->assertOk()
            ->assertJsonPath('meta.candidatos.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('meta.candidatos.0.credenciais.0', 'MOSTRATEC 2027');
    }
}
