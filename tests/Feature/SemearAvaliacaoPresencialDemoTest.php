<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TipoDocumento;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorLiberacaoPresencial;
use App\Models\Edicao;
use App\Models\ItemConferencia;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use App\Services\DadosDemoService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('local');
        $this->seed(CatalogoSeeder::class);
        $this->travelTo(now()->setDateTime(2026, 10, 20, 9, 0));

        // O catálogo já semeia a edição padrão: o ensaio usa ela, com a agenda.
        $this->edicao = Edicao::padrao();
        $this->edicao->update([
            'evento_de' => '2026-10-20 07:00', 'evento_ate' => '2026-10-21 18:00',
            'agenda_presencial' => self::agendaPresencial(['A' => ['inicio' => '08:00', 'fim' => '12:00'], 'B' => ['inicio' => '13:30', 'fim' => '17:30']], '2026-10-20', '2026-10-21'),
        ]);
    }

    public function test_prepara_o_avaliador_e_projetos_prontos_na_lista_demo(): void
    {
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();

        $avaliador = User::where('email', 'avaliador@fetecms.test')->sole();
        $this->assertSame(Role::Avaliador, $avaliador->role);
        $this->assertTrue($avaliador->is_demo);
        $this->assertTrue($avaliador->avaliadorProfile->presencial);
        // Liberado para o evento, como a organização faria em Validação de avaliadores.
        $this->assertTrue(AvaliadorLiberacaoPresencial::where('edicao_id', $this->edicao->id)->where('user_id', $avaliador->id)->exists());
        // Os três itens do ensaio, além dos itens do sistema (os termos).
        $this->assertSame(3, ItemConferencia::where('na_checagem', true)->whereNull('chave')->count());

        $lista = ListaFinal::vigente($this->edicao, true);
        $this->assertSame(3, $lista->projetos()->count());
        // A oficial continua sem existir: o ensaio não publica finalista.
        $this->assertNull(ListaFinal::vigente($this->edicao));
    }

    public function test_cada_projeto_leva_os_documentos_do_seu_cenario(): void
    {
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();

        $nomes = fn (string $titulo) => ProjetoDocumento::whereHas('projeto', fn ($q) => $q->where('titulo', $titulo))
            ->orderBy('id')->pluck('nome_original')->all();

        // Completo, sem ética.
        $this->assertSame(
            ['Projeto de Pesquisa.pdf', 'Diário de Bordo.pdf', 'Pasta de Referências.pdf', 'Banner.pdf'],
            $nomes('Horta vertical irrigada por gotejamento solar'),
        );
        // Pesquisa com estudantes: o TCLE/TALE vai como termo de ética.
        $this->assertContains('TCLE e TALE.pdf', $nomes('Jogo de tabuleiro para ensinar frações'));
        $this->assertSame(1, ProjetoDocumento::where('tipo', TipoDocumento::TermoEtica)->count());
        // Sem diário de bordo, de propósito.
        $this->assertNotContains('Diário de Bordo.pdf', $nomes('Filtro de água com carvão de casca de coco'));

        // São PDFs de verdade, no disco privado dos anexos.
        foreach (ProjetoDocumento::all() as $documento) {
            $this->assertSame('local', $documento->disk);
            $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($documento->path));
            $this->assertSame(strlen(Storage::disk('local')->get($documento->path)), $documento->tamanho_bytes);
        }

        // Rodar de novo regrava, não duplica.
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();
        $this->assertSame(12, ProjetoDocumento::count());
    }

    public function test_apagar_o_orientador_demo_leva_os_arquivos_junto(): void
    {
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();
        $caminhos = ProjetoDocumento::pluck('path')->all();

        app(DadosDemoService::class)->excluirConta(User::where('email', 'orientador.presencial@fetecms.test')->sole());

        $this->assertSame(0, Projeto::withoutGlobalScopes()->count());
        foreach ($caminhos as $caminho) {
            Storage::disk('local')->assertMissing($caminho);
        }
    }

    public function test_rodar_de_novo_nao_duplica(): void
    {
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();
        $this->artisan('demo:avaliacao-presencial')->assertSuccessful();

        $this->assertSame(3, ListaFinal::vigente($this->edicao, true)->projetos()->count());
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
        // A leitura do wizard traz os documentos do estande (o primeiro é a horta, estande 901).
        $id = $this->postJson("/api/v1/avaliador/presencial/avaliacoes/projetos/{$projetoId}", ['teste' => 1])
            ->assertOk()
            ->assertJsonCount(4, 'data.projeto.documentos')
            ->assertJsonPath('data.projeto.documentos.0.nome_original', 'Projeto de Pesquisa.pdf')
            ->json('data.id');

        // "Bom" na escala e Sim no resto: 80% dos 8,500 mais os 1,500 dos documentos.
        $respostas = collect($painel['rubrica']['secoes'])->flatMap(fn ($s) => $s['perguntas'])
            ->mapWithKeys(fn ($p) => [$p['chave'] => $p['tipo'] === 'escala' ? 8 : 'sim'])->all();
        $this->postJson("/api/v1/avaliador/presencial/avaliacoes/{$id}/concluir", [
            'respostas' => $respostas, 'teste' => 1,
        ])->assertOk()->assertJsonPath('data.nota', 8.3);

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
