<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoCredencial;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 190 — Comitê especial → Premiação: quem administra o comitê
 * correlaciona as credenciais e prêmios da feira com os projetos premiados, na
 * **mesma base** de Avaliação presencial → Credenciais e Prêmios, e baixa a
 * lista de premiação no mesmo formato.
 */
class ComitePremiacaoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Projeto $finalista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);

        $this->finalista = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value, 'name' => 'Marta Orientadora'])->id,
            'titulo' => 'Bioplástico de mandioca',
            'area_id' => Area::create(['nome' => 'Ciências Agrárias'])->id,
            'edicao_id' => $this->edicao->id,
        ]);
        Aluno::factory()->create(['projeto_id' => $this->finalista->id, 'nome' => 'Ana Paula']);

        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'vigente' => true, 'versao' => 1,
        ]);
        $lista->projetos()->attach($this->finalista->id);
    }

    /** Um admin cujo escopo abre **apenas** a aba Comitê. */
    private function adminDoComite(): User
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
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

    public function test_lista_so_as_credenciais_ativas_e_os_finalistas(): void
    {
        $this->credencial();
        $this->credencial(['nome' => 'Destaque da feira', 'tipo' => TipoCredencial::Premio]);
        $this->credencial(['nome' => 'Bolsa cancelada', 'ativa' => false]);
        $this->adminDoComite();

        $resposta = $this->getJson('/api/v1/admin/comite/premiacao')->assertOk();

        $nomes = array_column($resposta->json('data'), 'nome');
        sort($nomes);
        $this->assertSame(['Destaque da feira', 'MOSTRATEC 2027'], $nomes);
        $resposta->assertJsonPath('meta.candidatos.0.titulo', 'Bioplástico de mandioca');
    }

    /**
     * A base é uma só: o que o comitê premia aparece na Avaliação presencial
     * (e, por ela, no cerimonial e na lista de premiação).
     */
    public function test_premia_e_retira_na_mesma_base_da_avaliacao_presencial(): void
    {
        $credencial = $this->credencial();
        $admin = $this->adminDoComite();

        $this->postJson("/api/v1/admin/comite/premiacao/{$credencial->id}/projetos", ['projeto_id' => $this->finalista->id])
            ->assertOk()
            ->assertJsonPath('data.0.projetos.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('meta.message', 'Projeto premiado.');

        $this->assertDatabaseHas('credencial_projeto', [
            'credencial_id' => $credencial->id,
            'projeto_id' => $this->finalista->id,
            'atribuida_por' => $admin->id,
        ]);

        $this->deleteJson("/api/v1/admin/comite/premiacao/{$credencial->id}/projetos/{$this->finalista->id}")
            ->assertOk()
            ->assertJsonPath('data.0.projetos', []);

        $this->assertDatabaseMissing('credencial_projeto', ['credencial_id' => $credencial->id]);
    }

    public function test_as_regras_da_atribuicao_valem_tambem_aqui(): void
    {
        $credencial = $this->credencial(['vagas' => 1]);
        $desativada = $this->credencial(['nome' => 'Bolsa cancelada', 'ativa' => false]);
        $foraDaLista = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        $this->adminDoComite();

        // Só finalista.
        $this->postJson("/api/v1/admin/comite/premiacao/{$credencial->id}/projetos", ['projeto_id' => $foraDaLista->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto_id');

        // Credencial desativada não é dada (nem pela URL).
        $this->postJson("/api/v1/admin/comite/premiacao/{$desativada->id}/projetos", ['projeto_id' => $this->finalista->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credencial');

        // O teto de vagas.
        $this->postJson("/api/v1/admin/comite/premiacao/{$credencial->id}/projetos", ['projeto_id' => $this->finalista->id])
            ->assertOk();
        $outro = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        ListaFinal::vigente()->projetos()->attach($outro->id);
        $this->postJson("/api/v1/admin/comite/premiacao/{$credencial->id}/projetos", ['projeto_id' => $outro->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('projeto_id');
    }

    public function test_baixa_a_lista_de_premiacao_no_mesmo_formato(): void
    {
        $credencial = $this->credencial();
        $this->credencial(['nome' => 'Destaque da feira', 'orgao' => null, 'tipo' => TipoCredencial::Premio]);
        $credencial->projetos()->attach($this->finalista->id, ['atribuida_em' => now()]);
        $this->adminDoComite();

        $txt = $this->get('/api/v1/admin/comite/premiacao/arquivo')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="lista-premiacao.txt"')
            ->getContent();

        $this->assertStringContainsString('LISTA DE PREMIAÇÃO', $txt);
        $this->assertStringContainsString('== [CREDENCIAL] MOSTRATEC 2027 — FUNDECT', $txt);
        $this->assertStringContainsString('Bioplástico de mandioca', $txt);
        $this->assertStringContainsString('Marta Orientadora - Orientador(a)', $txt);
        $this->assertStringContainsString('== [PRÊMIO] DESTAQUE DA FEIRA', $txt);

        // É o mesmo arquivo da Avaliação presencial, byte a byte.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->assertSame($txt, $this->get('/api/v1/admin/presencial/credenciais/premiacao/arquivo')->getContent());
    }

    public function test_o_comite_nao_cadastra_credencial(): void
    {
        $credencial = $this->credencial();
        $this->adminDoComite();

        $this->postJson('/api/v1/admin/presencial/credenciais', ['nome' => 'Nova'])->assertForbidden();
        $this->patchJson("/api/v1/admin/presencial/credenciais/{$credencial->id}", ['ativa' => false])->assertForbidden();
    }

    public function test_quem_nao_tem_a_aba_comite_nao_entra(): void
    {
        $credencial = $this->credencial();
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/comite/premiacao')->assertForbidden();
        $this->get('/api/v1/admin/comite/premiacao/arquivo')->assertForbidden();
        $this->postJson("/api/v1/admin/comite/premiacao/{$credencial->id}/projetos", ['projeto_id' => $this->finalista->id])
            ->assertForbidden();
    }
}
