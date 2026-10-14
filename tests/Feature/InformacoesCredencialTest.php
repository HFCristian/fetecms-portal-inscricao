<?php

namespace Tests\Feature;

use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 192 — informações adicionais da credencial ou do prêmio: texto longo
 * da organização, lido pelos admins e pelo comitê, fora da lista da cerimônia.
 */
class InformacoesCredencialTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_cadastra_com_informacoes_e_elas_voltam_na_lista(): void
    {
        $this->admin();
        $texto = "Só projetos do ensino médio.\nO órgão custeia a viagem de 2 estudantes.";

        $this->postJson('/api/v1/admin/presencial/credenciais', [
            'nome' => 'MOSTRATEC 2027', 'informacoes' => "  {$texto}  ",
        ])->assertCreated()->assertJsonPath('data.0.informacoes', $texto);
    }

    public function test_edita_so_as_informacoes_sem_mexer_no_resto_e_em_branco_apaga(): void
    {
        $this->admin();
        $credencial = Credencial::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'FEBRACE 2027', 'orgao' => 'USP', 'vagas' => 4,
        ]);

        $this->patchJson("/api/v1/admin/presencial/credenciais/{$credencial->id}", ['informacoes' => 'Critérios: inovação.'])
            ->assertOk();
        $credencial->refresh();
        $this->assertSame('Critérios: inovação.', $credencial->informacoes);
        $this->assertSame('USP', $credencial->orgao);
        $this->assertSame(4, $credencial->vagas);

        $this->patchJson("/api/v1/admin/presencial/credenciais/{$credencial->id}", ['informacoes' => '   '])->assertOk();
        $this->assertNull($credencial->refresh()->informacoes);
    }

    public function test_texto_longo_demais_e_recusado(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/presencial/credenciais', ['nome' => 'X', 'informacoes' => str_repeat('a', 5001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('informacoes');
    }

    public function test_o_comite_le_as_informacoes_e_a_lista_da_cerimonia_nao_as_leva(): void
    {
        Credencial::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'MOSTRATEC 2027', 'informacoes' => 'Texto interno dos critérios',
        ]);
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/comite/premiacao')
            ->assertOk()
            ->assertJsonPath('data.0.informacoes', 'Texto interno dos critérios');

        $this->assertStringNotContainsString(
            'Texto interno dos critérios',
            $this->get('/api/v1/admin/comite/premiacao/arquivo')->getContent(),
        );
    }
}
