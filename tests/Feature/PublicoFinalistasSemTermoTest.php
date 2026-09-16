<?php

namespace Tests\Feature;

use App\Enums\PublicoMala;
use App\Enums\Role;
use App\Enums\TipoDocumento;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use App\Services\PublicoUsuariosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Público "finalistas sem o termo de responsabilidade" — a cobrança do
 * documento antes do evento, na mala direta e nos avisos.
 */
class PublicoFinalistasSemTermoTest extends TestCase
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

    private function orientadorCom(string $nome): array
    {
        $user = User::factory()->create(['role' => Role::Orientador->value, 'name' => $nome]);
        $projeto = Projeto::factory()->submetido()->create(['user_id' => $user->id]);

        return [$user, $projeto];
    }

    private function publicar(array $projetos): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'vigente' => true, 'versao' => 1,
        ]);
        $lista->projetos()->attach(collect($projetos)->mapWithKeys(fn ($p) => [$p->id => ['manual' => false]])->all());

        return $lista;
    }

    private function servico(): PublicoUsuariosService
    {
        return app(PublicoUsuariosService::class);
    }

    public function test_alcanca_so_finalista_que_ainda_deve_o_termo(): void
    {
        [$semTermo, $projetoSemTermo] = $this->orientadorCom('Sem termo');
        [$comTermo, $projetoComTermo] = $this->orientadorCom('Com termo');
        [$naoFinalista] = $this->orientadorCom('Não finalista');

        $this->publicar([$projetoSemTermo, $projetoComTermo]);

        ProjetoDocumento::factory()->create([
            'projeto_id' => $projetoComTermo->id,
            'tipo' => TipoDocumento::TermoResponsabilidade->value,
        ]);

        $nomes = $this->servico()->query(PublicoMala::FinalistasSemTermo)->pluck('name')->all();

        $this->assertSame(['Sem termo'], $nomes);
        $this->assertTrue($this->servico()->alcanca($semTermo, [PublicoMala::FinalistasSemTermo]));
        $this->assertFalse($this->servico()->alcanca($comTermo, [PublicoMala::FinalistasSemTermo]));
        $this->assertFalse($this->servico()->alcanca($naoFinalista, [PublicoMala::FinalistasSemTermo]));
    }

    public function test_sem_lista_final_o_publico_fica_vazio(): void
    {
        $this->orientadorCom('Qualquer um');

        // Sem lista publicada não há finalista — e o público não pode virar
        // "todo mundo" por falta de filtro.
        $this->assertSame(0, $this->servico()->query(PublicoMala::FinalistasSemTermo)->count());
    }

    public function test_outro_anexo_do_projeto_nao_conta_como_termo(): void
    {
        [, $projeto] = $this->orientadorCom('Sem termo');
        $this->publicar([$projeto]);

        ProjetoDocumento::factory()->create([
            'projeto_id' => $projeto->id,
            'tipo' => TipoDocumento::PlanoPesquisa->value,
        ]);

        $this->assertSame(1, $this->servico()->query(PublicoMala::FinalistasSemTermo)->count());
    }

    public function test_conta_inativa_ou_demo_fica_de_fora(): void
    {
        [$inativo, $p1] = $this->orientadorCom('Inativo');
        [$demo, $p2] = $this->orientadorCom('Demo');
        $this->publicar([$p1, $p2]);

        $inativo->update(['is_active' => false]);
        $demo->update(['is_demo' => true]);

        $this->assertSame(0, $this->servico()->query(PublicoMala::FinalistasSemTermo)->count());
    }

    public function test_publico_aparece_nas_opcoes_da_mala_direta_e_dos_avisos(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $valores = fn (array $opcoes) => array_column($opcoes, 'value');

        $this->assertContains(
            'finalistas_sem_termo',
            $valores($this->getJson('/api/v1/admin/mala-direta/opcoes')->assertOk()->json('data.publicos')),
        );

        $this->assertContains(
            'finalistas_sem_termo',
            $valores($this->getJson('/api/v1/admin/avisos/opcoes')->assertOk()->json('data.publicos')),
        );
    }
}
