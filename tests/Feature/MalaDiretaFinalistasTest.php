<?php

namespace Tests\Feature;

use App\Jobs\EnviarMalaDireta;
use App\Mail\MalaDiretaMensagem;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Coorientador;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\MalaDireta;
use App\Models\MalaDiretaDestinatario;
use App\Models\Projeto;
use App\Models\User;
use App\Services\MalaDiretaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 158 — caixa "Somente finalistas" na mala direta: a equipe inteira
 * (estudantes, orientador e coorientador) dos projetos da lista final vigente.
 */
class MalaDiretaFinalistasTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista oficial', 'vigente' => true, 'demo' => false, 'versao' => 1,
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function finalista(User $orientador, string $titulo): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id, 'titulo' => $titulo, 'edicao_id' => $this->edicao->id,
            'categoria' => 'fetecms', 'area_id' => Area::firstOrCreate(['nome' => 'Ciências Agrárias'], ['sigla' => 'AGR'])->id,
        ]);
        $this->lista->projetos()->attach($projeto->id);

        return $projeto;
    }

    public function test_previa_traz_a_equipe_inteira_sem_repetir_quem_esta_em_dois_projetos(): void
    {
        $marta = User::factory()->create(['name' => 'Marta Orientadora', 'email' => 'marta@escola.test']);
        $a = $this->finalista($marta, 'Biofiltro');
        $b = $this->finalista($marta, 'Bioplástico');
        Aluno::factory()->create(['projeto_id' => $a->id, 'nome' => 'Ana', 'email' => 'ana@aluno.test']);
        Aluno::factory()->create(['projeto_id' => $b->id, 'nome' => 'Beto', 'email' => 'beto@aluno.test']);
        // Cadastro manual: estudante só com o nome fica de fora, mas é contado.
        Aluno::factory()->create(['projeto_id' => $b->id, 'nome' => 'Sem Email', 'email' => null, 'cpf' => null]);
        Coorientador::factory()->create(['projeto_id' => $a->id, 'nome' => 'Caio', 'email' => 'caio@co.test']);

        // Fora da lista: não recebe.
        $fora = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        Aluno::factory()->create(['projeto_id' => $fora->id, 'email' => 'fora@aluno.test']);

        $resposta = $this->postJson('/api/v1/admin/mala-direta/previa', ['finalistas' => true])->assertOk();

        $porEmail = collect($resposta->json('data'))->keyBy('email');
        $this->assertEqualsCanonicalizing(
            ['marta@escola.test', 'ana@aluno.test', 'beto@aluno.test', 'caio@co.test'],
            $porEmail->keys()->all(),
        );
        $this->assertSame(2, $porEmail['marta@escola.test']['projetos_total']);
        $this->assertSame('estudante', $porEmail['ana@aluno.test']['papel']);
        $this->assertSame('coorientador', $porEmail['caio@co.test']['papel']);
        $this->assertSame(1, $resposta->json('meta.finalistas.sem_email'));
        $this->assertSame(4, $resposta->json('meta.finalistas.pessoas'));
    }

    public function test_disparo_grava_a_origem_e_personaliza_os_projetos(): void
    {
        Mail::fake();
        $marta = User::factory()->create(['name' => 'Marta Orientadora', 'email' => 'marta@escola.test']);
        $projeto = $this->finalista($marta, 'Biofiltro <de> arroz');
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana', 'email' => 'ana@aluno.test']);

        $this->postJson('/api/v1/admin/mala-direta', [
            'nome' => 'Credenciamento',
            'justificativa' => 'Aviso aos finalistas sobre o credenciamento.',
            'assunto' => 'Credenciamento',
            'corpo' => '<p>Olá, {{nome}}! Seu projeto: {{projetos}}</p>',
            'formato' => 'html',
            'finalistas' => true,
        ])->assertCreated()
            ->assertJsonPath('data.publicos', ['finalistas'])
            ->assertJsonPath('data.publicos_labels', ['Finalistas (equipe inteira)']);

        $mala = MalaDireta::firstOrFail();
        $this->assertSame(2, $mala->destinatarios()->count());
        $ana = MalaDiretaDestinatario::where('email', 'ana@aluno.test')->firstOrFail();
        $this->assertSame(['finalistas'], $ana->origens);

        // O título entra escapado no HTML e com o código da lista.
        $corpo = app(MalaDiretaService::class)->personalizar($mala->corpo, $ana, html: true);
        $this->assertStringContainsString('Ana', $corpo);
        $this->assertStringContainsString('FET.AGR-001 - Biofiltro &lt;de&gt; arroz', $corpo);

        (new EnviarMalaDireta($ana->id))->handle(app(MalaDiretaService::class));
        Mail::assertSent(MalaDiretaMensagem::class, fn ($m) => $m->hasTo('ana@aluno.test'));
    }

    public function test_sem_lista_final_publicada_nao_ha_destinatario(): void
    {
        $this->lista->update(['vigente' => false]);

        $this->postJson('/api/v1/admin/mala-direta', [
            'nome' => 'Credenciamento', 'justificativa' => 'Aviso aos finalistas.',
            'assunto' => 'Credenciamento', 'corpo' => 'Olá', 'finalistas' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('publicos');
    }

    public function test_opcoes_dizem_de_qual_lista_sao_os_finalistas(): void
    {
        $this->getJson('/api/v1/admin/mala-direta/opcoes')
            ->assertOk()
            ->assertJsonPath('data.finalistas.lista', 'Lista oficial (v1)')
            ->assertJsonPath('data.variaveis.3.chave', 'projetos');
    }
}
