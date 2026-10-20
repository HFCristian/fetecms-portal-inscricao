<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\TipoRegistro;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\Cidade;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\Estado;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\SaidaAntecipada;
use App\Models\TurnoApresentacao;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mapa do Evento → **Marcar saída** (Sprint 243): a equipe chegou e foi embora
 * antes do fim do turno.
 *
 * O que estes testes seguram:
 * - só sai quem chegou (estande no mapa + credenciamento concluído);
 * - a saída pinta o estande de "saiu" por cima de qualquer estágio, mas só no
 *   dia em que aconteceu — no dia seguinte a equipe pode voltar;
 * - marcar de novo no mesmo dia corrige a hora; desfazer apaga;
 * - as duas ações ficam em Registros → Mapa do evento;
 * - a lista filtrável e o CSV enxergam a saída.
 */
class MapaSaidaAntecipadaTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Cidade $cidade;

    protected function setUp(): void
    {
        parent::setUp();

        // Relógio parado no meio da tarde: a saída é por dia, e "40 minutos
        // atrás" rodando logo depois da meia-noite cairia no dia anterior.
        $this->travelTo(now()->setTime(14, 0));

        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $this->cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $estado->id, 'capital' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subDays(2)->startOfDay(),
            'evento_ate' => now()->addDay()->endOfDay(),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function noEstande(string $titulo, Turno $turno, int $numero): Projeto
    {
        $instituicao = Instituicao::create(['nome' => 'EE '.$titulo, 'cidade_id' => $this->cidade->id]);

        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'categoria' => Categoria::Fetecms,
            'area_id' => $this->area->id,
            'instituicao_id' => $instituicao->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);

        TurnoApresentacao::create(['edicao_id' => Edicao::atual()?->id, 'projeto_id' => $projeto->id, 'turno' => $turno->value]);
        EstandeProjeto::create([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'turno' => $turno->value,
            'numero' => $numero,
        ]);

        return $projeto;
    }

    private function credenciar(Projeto $projeto, ?Carbon $quando = null): void
    {
        Credenciamento::create([
            'projeto_id' => $projeto->id,
            'credenciado_por' => User::factory()->admin()->create()->id,
            'finalizado_em' => $quando ?? now()->subHours(3),
        ]);
    }

    private function situacao(string $turno = 'A', ?string $dia = null): array
    {
        return $this->getJson('/api/v1/admin/mapa/planta/situacao?turno='.$turno.($dia ? '&dia='.$dia : ''))
            ->assertOk()->json('data');
    }

    public function test_marcar_saida_pinta_o_estande_por_cima_do_estagio_e_registra(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto);
        $admin = $this->admin();

        $this->assertSame('credenciado', $this->situacao()['estandes'][42]['situacao']);

        $this->postJson('/api/v1/admin/mapa/saidas', [
            'projeto_id' => $projeto->id,
            'observacao' => 'Aluna passou mal; a equipe foi embora.',
        ])->assertCreated()->assertJsonPath('meta.message', fn ($m) => str_contains($m, 'saída registrada'));

        $estande = $this->situacao()['estandes'][42];
        $this->assertSame('saiu', $estande['situacao']);
        $this->assertSame('Saiu antes do horário', $estande['situacao_label']);
        $this->assertSame('Aluna passou mal; a equipe foi embora.', $estande['saida']['observacao']);
        $this->assertSame($admin->name, $estande['saida']['registrado_por']);
        // O credenciamento continua dito: a cor muda, o fato não some.
        $this->assertTrue($estande['credenciado']);
        $this->assertSame(1, $this->situacao()['resumo']['saiu']);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::MapaSaidaAntecipada->value)->sole();
        $this->assertSame($projeto->id, $registro->projeto_id);
        $this->assertSame('Estande 042 · Turno A (matutino)', $registro->detalhes['de']);
        $this->assertStringStartsWith('Saiu às ', $registro->detalhes['para']);
        $this->assertSame(TipoRegistro::SECAO_MAPA, $registro->tipo->secao());
    }

    public function test_so_sai_quem_chegou_e_esta_no_mapa(): void
    {
        $semCredenciamento = $this->noEstande('Ainda não chegou', Turno::A, 41);
        $foraDoMapa = Projeto::factory()->submetido()->create(['edicao_id' => Edicao::atual()?->id]);
        $this->admin();

        $this->postJson('/api/v1/admin/mapa/saidas', ['projeto_id' => $semCredenciamento->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['projeto_id']);
        $this->postJson('/api/v1/admin/mapa/saidas', ['projeto_id' => $foraDoMapa->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['projeto_id']);

        $this->assertSame(0, SaidaAntecipada::count());
    }

    public function test_horario_no_futuro_ou_antes_do_credenciamento_e_recusado(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto, now()->subHour());
        $this->admin();

        $this->postJson('/api/v1/admin/mapa/saidas', [
            'projeto_id' => $projeto->id,
            'saiu_em' => now()->addHour()->format('Y-m-d\TH:i'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['saiu_em']);

        $this->postJson('/api/v1/admin/mapa/saidas', [
            'projeto_id' => $projeto->id,
            'saiu_em' => now()->subHours(2)->format('Y-m-d\TH:i'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['saiu_em']);

        $this->postJson('/api/v1/admin/mapa/saidas', ['projeto_id' => $projeto->id, 'saiu_em' => 'ontem'])
            ->assertUnprocessable()->assertJsonValidationErrors(['saiu_em']);
    }

    public function test_marcar_de_novo_no_mesmo_dia_corrige_a_hora(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto);
        $this->admin();

        $primeira = now()->subMinutes(40)->format('Y-m-d\TH:i');
        $segunda = now()->subMinutes(10)->format('Y-m-d\TH:i');

        $this->postJson('/api/v1/admin/mapa/saidas', ['projeto_id' => $projeto->id, 'saiu_em' => $primeira])->assertCreated();
        $this->postJson('/api/v1/admin/mapa/saidas', ['projeto_id' => $projeto->id, 'saiu_em' => $segunda])->assertCreated();

        $this->assertSame(1, SaidaAntecipada::count());
        $this->assertSame(substr($segunda, 11), $this->situacao()['estandes'][42]['saida']['hora']);
    }

    public function test_a_saida_vale_so_no_dia_em_que_aconteceu(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto, now()->subDays(2));
        $this->admin();

        $ontem = now()->subDay()->setTime(15, 0);
        $this->postJson('/api/v1/admin/mapa/saidas', [
            'projeto_id' => $projeto->id,
            'saiu_em' => $ontem->format('Y-m-d\TH:i'),
        ])->assertCreated();

        // Ontem, o estande aparece como "saiu"; hoje, a equipe voltou.
        $this->assertSame('saiu', $this->situacao('A', $ontem->toDateString())['estandes'][42]['situacao']);
        $hoje = $this->situacao()['estandes'][42];
        $this->assertSame('credenciado', $hoje['situacao']);
        $this->assertNull($hoje['saida']);
    }

    public function test_desfazer_apaga_a_marcacao_e_registra(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::B, 7);
        $this->credenciar($projeto);
        $this->admin();

        $this->postJson('/api/v1/admin/mapa/saidas', ['projeto_id' => $projeto->id])->assertCreated();
        $id = $this->situacao('B')['estandes'][7]['saida']['id'];

        $this->deleteJson('/api/v1/admin/mapa/saidas/'.$id)->assertOk()->assertJsonPath('meta.message', 'Saída desfeita.');

        $this->assertSame(0, SaidaAntecipada::count());
        $this->assertSame('credenciado', $this->situacao('B')['estandes'][7]['situacao']);
        $desfeita = RegistroAtividade::where('tipo', TipoRegistro::MapaSaidaDesfeita->value)->sole();
        $this->assertSame('Estande 007 · Turno B (vespertino)', $desfeita->detalhes['para']);
    }

    public function test_lista_filtra_e_csv_mostra_a_saida(): void
    {
        $saiu = $this->noEstande('Foi embora', Turno::A, 42);
        $ficou = $this->noEstande('Ficou', Turno::A, 43);
        $this->credenciar($saiu);
        $this->credenciar($ficou);
        $this->admin();

        $hora = now()->subMinutes(5);
        $this->postJson('/api/v1/admin/mapa/saidas', [
            'projeto_id' => $saiu->id,
            'saiu_em' => $hora->format('Y-m-d\TH:i'),
        ])->assertCreated();

        $sim = $this->getJson('/api/v1/admin/mapa/planta/lista?turno=A&criterio=saida&valor=sim')->assertOk();
        $this->assertSame(['Foi embora'], array_column($sim->json('data.linhas'), 'titulo'));
        $this->assertSame('saiu antes do horário', $sim->json('data.valor_label'));

        $nao = $this->getJson('/api/v1/admin/mapa/planta/lista?turno=A&criterio=saida&valor=nao')->assertOk();
        $this->assertSame(['Ficou'], array_column($nao->json('data.linhas'), 'titulo'));

        // O filtro oferece as duas respostas, com "saiu" já escolhido.
        $criterio = collect($this->getJson('/api/v1/admin/mapa/planta')->assertOk()->json('meta.filtros.criterios'))
            ->firstWhere('value', 'saida');
        $this->assertSame('opcoes', $criterio['tipo']);
        $this->assertSame('sim', $criterio['padrao']);
        $this->assertSame(['sim', 'nao'], array_column($criterio['valores'], 'value'));

        $csv = $this->get('/api/v1/admin/mapa/planta/lista/csv?turno=A&criterio=saida&valor=sim')->assertOk()->getContent();
        $this->assertStringContainsString('Saiu antes do horário', $csv);
        $this->assertStringContainsString('"'.$hora->format('H:i').'"', $csv);
    }

    public function test_admin_sem_a_aba_mapa_nao_marca_saida(): void
    {
        $projeto = $this->noEstande('Bioplástico', Turno::A, 42);
        $this->credenciar($projeto);

        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::atual()?->id]);
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/mapa/saidas', ['projeto_id' => $projeto->id])->assertForbidden();
        $this->assertSame(0, SaidaAntecipada::count());
    }
}
