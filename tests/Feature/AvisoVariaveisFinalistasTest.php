<?php

namespace Tests\Feature;

use App\Enums\ModeloEmail;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Services\AvisoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 186 — `{{codigo_projeto}}` e `{{turno}}` também nos avisos (resolvidos
 * para quem lê) e no modelo de e-mail "Código do projeto".
 */
class AvisoVariaveisFinalistasTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->lista = ListaFinal::create(['edicao_id' => $this->edicao->id, 'nome' => 'Oficial', 'vigente' => true, 'versao' => 1]);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function finalista(string $nome, string $titulo, ?Turno $turno = Turno::B): User
    {
        $orientador = User::factory()->create(['name' => $nome]);
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id, 'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'categoria' => 'fetecms',
            'area_id' => Area::firstOrCreate(['nome' => 'Ciências Agrárias'], ['sigla' => 'AGR'])->id,
        ]);
        $this->lista->projetos()->attach($projeto->id);
        if ($turno !== null) {
            TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno->value]);
        }

        return $orientador;
    }

    private function publicar(array $publicos, string $mensagem = 'Projeto {{codigo_projeto}}, {{turno}}.')
    {
        return $this->postJson('/api/v1/admin/avisos', [
            'titulo' => 'Credenciamento', 'mensagem' => $mensagem, 'publicos' => $publicos,
        ]);
    }

    public function test_cada_finalista_le_o_proprio_codigo_e_turno(): void
    {
        $marta = $this->finalista('Marta', 'Abelhas', Turno::A);
        $rui = $this->finalista('Rui', 'Biofiltro', Turno::B);

        $this->publicar(['orientadores_finalistas'])->assertCreated();

        $servico = app(AvisoService::class);
        $this->assertSame('Projeto FET.AGR-001, Turno A (matutino).', $servico->paraTela($servico->paraUsuario($marta), $marta)['mensagem']);
        $this->assertSame('Projeto FET.AGR-002, Turno B (vespertino).', $servico->paraTela($servico->paraUsuario($rui), $rui)['mensagem']);
    }

    public function test_publico_que_nao_e_so_de_finalistas_e_recusado(): void
    {
        $this->finalista('Marta', 'Abelhas');

        $this->publicar(['orientadores'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mensagem');
        $this->publicar(['orientadores_finalistas', 'avaliadores'])->assertStatus(422);
        // Sem público, o aviso vai para todos os orientadores: também recusado.
        $this->publicar([])->assertStatus(422);

        $this->publicar(['orientadores'], 'Sem variável de finalista.')->assertCreated();
    }

    public function test_projeto_finalista_sem_turno_bloqueia_o_aviso_com_turno(): void
    {
        $this->finalista('Marta', 'Abelhas', null);

        $this->publicar(['orientadores_finalistas'], 'Seu turno: {{turno}}')
            ->assertStatus(422)
            ->assertJsonPath('errors.mensagem.0', '1 projeto(s) finalista(s) ainda sem turno (Mapa do Evento → Turnos): Abelhas.');

        $this->publicar(['orientadores_finalistas'], 'Seu código: {{codigo_projeto}}')->assertCreated();
    }

    public function test_previa_do_admin_mostra_valores_de_exemplo(): void
    {
        $this->postJson('/api/v1/admin/avisos/previa', [
            'titulo' => 'x', 'mensagem' => '{{codigo_projeto}} / {{turno}}', 'publicos' => ['orientadores_finalistas'],
        ])->assertOk()->assertJsonPath('data.mensagem', 'FET.AGR-001 (exemplo) / Turno A (matutino) (exemplo)');
    }

    public function test_opcoes_do_aviso_marcam_as_variaveis_de_finalista(): void
    {
        $variaveis = collect($this->getJson('/api/v1/admin/avisos/opcoes')->assertOk()->json('data.variaveis'))->keyBy('chave');

        $this->assertTrue($variaveis['codigo_projeto']['somente_finalistas']);
        $this->assertTrue($variaveis['turno']['somente_finalistas']);
    }

    public function test_modelo_codigo_do_projeto_aceita_codigo_e_turno(): void
    {
        $chaves = array_column(ModeloEmail::CodigoProjeto->variaveis(), 'chave');

        $this->assertContains('codigo_projeto', $chaves);
        $this->assertContains('turno', $chaves);
        $this->assertNotContains('turno', array_column(ModeloEmail::ProjetoSubmetido->variaveis(), 'chave'));
    }
}
