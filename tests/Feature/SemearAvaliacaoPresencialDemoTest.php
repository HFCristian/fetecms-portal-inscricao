<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorTurnoPresencial;
use App\Models\Edicao;
use App\Models\ItemChecagemEstande;
use App\Models\ListaFinal;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 172 — `php artisan demo:avaliacao-presencial` deixa o
 * `avaliador@fetecms.test` pronto para ensaiar a avaliação presencial e a
 * distribuição por turno, sem tocar em nada de verdade.
 */
class SemearAvaliacaoPresencialDemoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
        $this->travelTo(now()->setDateTime(2026, 10, 20, 9, 0));

        // O catálogo já semeia a edição padrão: o ensaio usa ela, com a agenda.
        $this->edicao = Edicao::padrao();
        $this->edicao->update([
            'evento_de' => '2026-10-20 07:00', 'evento_ate' => '2026-10-21 18:00',
            'horarios_turnos' => ['A' => ['inicio' => '08:00', 'fim' => '12:00'], 'B' => ['inicio' => '13:30', 'fim' => '17:30']],
        ]);
    }

    public function test_prepara_o_avaliador_e_projetos_prontos_na_lista_demo(): void
    {
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();

        $avaliador = User::where('email', 'avaliador@fetecms.test')->sole();
        $this->assertSame(Role::Avaliador, $avaliador->role);
        $this->assertTrue($avaliador->is_demo);
        $this->assertTrue($avaliador->avaliadorProfile->presencial);
        // Os quatro turnos da agenda ainda não terminaram às 9h do primeiro dia.
        $this->assertSame(4, AvaliadorTurnoPresencial::where('user_id', $avaliador->id)->count());
        $this->assertSame(3, ItemChecagemEstande::count());

        $lista = ListaFinal::vigente($this->edicao, true);
        $this->assertSame(3, $lista->projetos()->count());
        // A oficial continua sem existir: o ensaio não publica finalista.
        $this->assertNull(ListaFinal::vigente($this->edicao));
    }

    public function test_rodar_de_novo_nao_duplica(): void
    {
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();

        $this->assertSame(3, ListaFinal::vigente($this->edicao, true)->projetos()->count());
        $this->assertSame(4, AvaliadorTurnoPresencial::count());
        $this->assertSame(1, User::where('email', 'avaliador@fetecms.test')->count());
    }

    public function test_o_avaliador_demo_avalia_um_estande_no_modo_de_teste(): void
    {
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();
        $avaliador = User::where('email', 'avaliador@fetecms.test')->sole();
        Sanctum::actingAs($avaliador);

        $painel = $this->getJson('/api/v1/avaliador/presencial/avaliacoes?teste=1')
            ->assertOk()
            ->assertJsonPath('data.aberto', true)
            ->assertJsonPath('data.modo_teste', true)
            ->assertJsonCount(3, 'data.disponiveis')
            ->json('data');

        $projetoId = $painel['disponiveis'][0]['id'];
        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$projetoId}", ['teste' => 1])
            ->assertOk()
            ->json('data.id');

        $respostas = collect($painel['rubrica']['secoes'])->flatMap(fn ($s) => $s['perguntas'])
            ->mapWithKeys(fn ($p) => [$p['chave'] => 8])->all();
        $itens = collect($painel['itens'])->mapWithKeys(fn ($i) => [$i['id'] => 'presente'])->all();

        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", [
            'respostas' => $respostas, 'itens' => $itens, 'teste' => 1,
        ])->assertOk()->assertJsonPath('data.nota', 8);

        $this->assertSame(1, AvaliacaoPresencial::where('status', 'concluida')->count());
    }

    public function test_a_distribuicao_do_admin_demo_alcanca_o_avaliador(): void
    {
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();
        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $this->postJson('/api/v1/admin/presencial/distribuicao/distribuir', ['dia' => '2026-10-20', 'turno' => 'A', 'teste' => 1])
            ->assertOk()
            ->assertJsonPath('meta.resultado.designadas', 3);

        $avaliador = User::where('email', 'avaliador@fetecms.test')->sole();
        $this->assertSame(3, AvaliacaoPresencial::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_recusa_email_de_outro_papel(): void
    {
        User::factory()->create(['email' => 'avaliador@fetecms.test', 'role' => Role::Orientador->value]);

        $this->artisan('demo:avaliacao-presencial')->assertFailed();
    }
}
