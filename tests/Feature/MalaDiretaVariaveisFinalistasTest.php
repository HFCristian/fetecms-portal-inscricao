<?php

namespace Tests\Feature;

use App\Enums\Turno;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\MalaDireta;
use App\Models\MalaDiretaDestinatario;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Services\MalaDiretaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 185 — `{{codigo_projeto}}` e `{{turno}}` na mala direta: só saem se
 * todo destinatário for da equipe de um projeto finalista, e se todo projeto
 * envolvido já tiver código e turno.
 */
class MalaDiretaVariaveisFinalistasTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    private User $marta;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Oficial', 'vigente' => true, 'demo' => false, 'versao' => 1,
        ]);
        $this->marta = User::factory()->create(['name' => 'Marta Orientadora', 'email' => 'marta@escola.test']);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function finalista(User $orientador, string $titulo, ?Turno $turno = Turno::A): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id, 'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'categoria' => 'fetecms',
            'area_id' => Area::firstOrCreate(['nome' => 'Ciências Agrárias'], ['sigla' => 'AGR'])->id,
        ]);
        $this->lista->projetos()->attach($projeto->id);

        if ($turno !== null) {
            TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno->value]);
        }

        return $projeto;
    }

    private function disparar(array $criterio, string $corpo = 'Seu código: {{codigo_projeto}} — {{turno}}')
    {
        return $this->postJson('/api/v1/admin/mala-direta', $criterio + [
            'nome' => 'Credenciamento', 'justificativa' => 'Aviso aos finalistas.',
            'assunto' => 'Credenciamento', 'corpo' => $corpo,
        ]);
    }

    public function test_finalista_recebe_o_codigo_e_o_turno_do_seu_projeto(): void
    {
        $projeto = $this->finalista($this->marta, 'Biofiltro');
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana', 'email' => 'ana@aluno.test']);

        $this->disparar(['finalistas' => true])->assertCreated();

        $ana = MalaDiretaDestinatario::where('email', 'ana@aluno.test')->firstOrFail();
        $corpo = app(MalaDiretaService::class)->personalizar(MalaDireta::firstOrFail()->corpo, $ana);

        $this->assertSame('Seu código: FET.AGR-001 — Turno A (matutino)', $corpo);
    }

    public function test_quem_esta_em_dois_finalistas_recebe_os_dois(): void
    {
        $this->finalista($this->marta, 'Biofiltro', Turno::A);
        $this->finalista($this->marta, 'Compostagem', Turno::B);

        $this->disparar(['publicos' => ['orientadores_finalistas']])->assertCreated();

        $marta = MalaDiretaDestinatario::where('email', 'marta@escola.test')->firstOrFail();
        $corpo = app(MalaDiretaService::class)->personalizar(MalaDireta::firstOrFail()->corpo, $marta);

        $this->assertSame(
            'Seu código: FET.AGR-001, FET.AGR-002 — FET.AGR-001: Turno A (matutino); FET.AGR-002: Turno B (vespertino)',
            $corpo,
        );
    }

    public function test_grupo_que_nao_e_100_por_cento_finalista_bloqueia_o_envio(): void
    {
        $this->finalista($this->marta, 'Biofiltro');
        User::factory()->create(['name' => 'Rui', 'email' => 'rui@escola.test']); // orientador sem finalista

        $this->disparar(['publicos' => ['orientadores']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('corpo');
        $this->assertSame(0, MalaDireta::count());

        // A mesma mala sem as variáveis sai normalmente.
        $this->disparar(['publicos' => ['orientadores']], 'Olá, {{nome}}.')->assertCreated();
    }

    public function test_email_avulso_so_passa_se_for_de_alguem_da_equipe_finalista(): void
    {
        $projeto = $this->finalista($this->marta, 'Biofiltro');
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'email' => 'ana@aluno.test']);

        $this->disparar(['destinatarios' => [['email' => 'ANA@aluno.test']]])->assertCreated();

        $this->disparar(['destinatarios' => [['email' => 'imprensa@jornal.test']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['corpo']);
    }

    public function test_projeto_sem_turno_ou_sem_codigo_bloqueia_quem_usa_a_variavel(): void
    {
        $semTurno = $this->finalista($this->marta, 'Biofiltro', null);

        $this->disparar(['finalistas' => true], 'Turno: {{turno}}')
            ->assertStatus(422)
            ->assertJsonPath('errors.corpo.0', '1 projeto(s) finalista(s) ainda sem turno (Mapa do Evento → Turnos): Biofiltro.');

        // Só o código: o turno não faz falta.
        $this->disparar(['finalistas' => true], 'Código: {{codigo_projeto}}')->assertCreated();

        // Sem código (o admin apagou na lista), {{codigo_projeto}} também barra.
        $this->lista->forceFill(['codigos_congelados_em' => now()])->save();
        $this->lista->projetos()->updateExistingPivot($semTurno->id, ['codigo' => '']);
        $this->disparar(['finalistas' => true], 'Código: {{codigo_projeto}}')
            ->assertStatus(422)
            ->assertJsonPath('errors.corpo.0', '1 projeto(s) finalista(s) ainda sem código: Biofiltro.');
    }

    public function test_previa_conta_o_que_impediria_as_variaveis(): void
    {
        $this->finalista($this->marta, 'Biofiltro', null);
        User::factory()->create(['email' => 'rui@escola.test']);

        $this->postJson('/api/v1/admin/mala-direta/previa', ['publicos' => ['orientadores']])
            ->assertOk()
            ->assertJsonPath('meta.pendencias_finalistas.nao_finalistas', 1)
            ->assertJsonPath('meta.pendencias_finalistas.exemplos', ['rui@escola.test'])
            ->assertJsonPath('meta.pendencias_finalistas.sem_turno', ['Biofiltro'])
            ->assertJsonPath('meta.pendencias_finalistas.sem_codigo', []);
    }

    public function test_envio_de_teste_usa_valores_de_exemplo(): void
    {
        $this->postJson('/api/v1/admin/mala-direta/teste', [
            'assunto' => 'Credenciamento', 'corpo' => '{{codigo_projeto}} / {{turno}}',
            'destinatarios' => [['email' => 'conferencia@org.test']],
        ])->assertCreated();

        $destinatario = MalaDiretaDestinatario::where('email', 'conferencia@org.test')->firstOrFail();
        $this->assertSame(
            'FET.AGR-001 (exemplo) / Turno A (matutino) (exemplo)',
            app(MalaDiretaService::class)->personalizar($destinatario->mala->corpo, $destinatario),
        );
    }
}
