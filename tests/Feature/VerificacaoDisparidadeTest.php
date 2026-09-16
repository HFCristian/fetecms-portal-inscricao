<?php

namespace Tests\Feature;

use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Models\VerificacaoDisparidade;
use App\Services\RegistroAtividadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ranking dos projetos → Verificar disparidade: os projetos em que a maior e a
 * menor nota se afastaram pelo menos a diferença pedida pelo admin.
 */
class VerificacaoDisparidadeTest extends TestCase
{
    use RefreshDatabase;

    private Area $exatas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exatas = Area::create(['nome' => 'Exatas']);
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    /**
     * Projeto submetido com uma avaliação concluída por nota informada.
     *
     * @param  list<float>  $notas
     */
    private function projeto(string $titulo, array $notas, ?User $orientador = null): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => ($orientador ?? User::factory()->create())->id,
            'titulo' => $titulo,
            'area_id' => $this->exatas->id,
        ]);

        foreach ($notas as $nota) {
            Avaliacao::create([
                'projeto_id' => $projeto->id,
                'avaliador_id' => User::factory()->avaliador()->create()->id,
                'status' => StatusAvaliacao::Concluida,
                'nota' => $nota,
                'concluida_em' => now(),
            ]);
        }

        return $projeto;
    }

    private function comoAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_lista_so_os_projetos_acima_da_diferenca_pedida(): void
    {
        // 9,50 e 4,50 → amplitude 5,00.
        $this->projeto('Discordância total', [9.5, 4.5]);
        // 7,00 e 6,80 → amplitude 0,20.
        $this->projeto('Consenso', [7.0, 6.8]);

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 2])
            ->assertCreated()
            ->assertJsonCount(1, 'data.itens')
            ->assertJsonPath('data.itens.0.titulo', 'Discordância total')
            ->assertJsonPath('data.itens.0.amplitude', 5)
            ->assertJsonPath('data.itens.0.nota_min', 4.5)
            ->assertJsonPath('data.itens.0.nota_max', 9.5)
            ->assertJsonPath('data.itens.0.media', 7)
            ->assertJsonPath('data.total', 1);
    }

    public function test_aceita_diferenca_com_duas_casas(): void
    {
        $this->projeto('Disputa no topo', [9.75, 9.5]);   // amplitude 0,25

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 0.25])
            ->assertCreated()
            ->assertJsonCount(1, 'data.itens');

        // Um centésimo acima do corte já deixa o projeto de fora.
        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 0.26])
            ->assertCreated()
            ->assertJsonCount(0, 'data.itens');
    }

    public function test_projeto_com_uma_avaliacao_so_nao_entra(): void
    {
        $this->projeto('Sozinho na fila', [10.0]);

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 0.01])
            ->assertCreated()
            ->assertJsonCount(0, 'data.itens');
    }

    public function test_projeto_de_orientador_demo_fica_de_fora(): void
    {
        $demo = User::factory()->create(['is_demo' => true]);
        $this->projeto('Projeto de ensaio', [10.0, 2.0], $demo);

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 1])
            ->assertCreated()
            ->assertJsonCount(0, 'data.itens');
    }

    public function test_ordena_do_mais_disparo_para_o_menos(): void
    {
        $this->projeto('Diferença média', [8.0, 5.0]);     // 3,00
        $this->projeto('Diferença grande', [10.0, 1.0]);   // 9,00

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 1])
            ->assertCreated()
            ->assertJsonPath('data.itens.0.titulo', 'Diferença grande')
            ->assertJsonPath('data.itens.1.titulo', 'Diferença média');
    }

    public function test_lista_fica_registrada_e_congelada(): void
    {
        $projeto = $this->projeto('Discordância', [9.0, 4.0]);
        $admin = $this->comoAdmin();

        $id = $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 2])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('verificacao_disparidade_projetos', [
            'verificacao_id' => $id,
            'projeto_id' => $projeto->id,
            'titulo' => 'Discordância',
        ]);

        // A verificação é o retrato de um momento: uma avaliação nova depois
        // dela não reescreve o que a lista consultada naquele dia dizia.
        Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create()->id,
            'status' => StatusAvaliacao::Concluida,
            'nota' => 6.5,
            'concluida_em' => now(),
        ]);

        $this->getJson("/api/v1/admin/avaliacao/disparidades/{$id}")
            ->assertOk()
            ->assertJsonPath('data.itens.0.avaliacoes', 2)
            ->assertJsonPath('data.itens.0.amplitude', 5);

        $this->assertDatabaseHas('registros_atividade', [
            'tipo' => TipoRegistro::AvaliacaoDisparidadeVerificada->value,
            'user_id' => $admin->id,
        ]);
    }

    public function test_historico_lista_as_verificacoes_da_edicao(): void
    {
        $this->projeto('Discordância', [9.0, 4.0]);
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 1])->assertCreated();
        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 3])->assertCreated();

        $this->getJson('/api/v1/admin/avaliacao/disparidades')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // A mais nova primeiro.
            ->assertJsonPath('data.0.diferenca', 3)
            ->assertJsonPath('data.1.diferenca', 1);

        $this->assertSame(2, VerificacaoDisparidade::count());
    }

    public function test_diferenca_e_obrigatoria_e_limitada_a_duas_casas(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/disparidades', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('diferenca');

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 1.234])
            ->assertStatus(422)
            ->assertJsonValidationErrors('diferenca');

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('diferenca');
    }

    public function test_orientador_nao_acessa(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/avaliacao/disparidades')->assertForbidden();
        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 1])->assertForbidden();
    }

    public function test_registro_descreve_o_corte_e_o_total(): void
    {
        $this->projeto('Discordância', [9.0, 4.0]);
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/disparidades', ['diferenca' => 2])->assertCreated();

        $registro = RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoDisparidadeVerificada->value)->firstOrFail();

        $this->assertSame(
            'diferença de 2,00 ponto(s) · 1 projeto(s)',
            app(RegistroAtividadeService::class)->descreverDetalhes($registro),
        );
    }
}
