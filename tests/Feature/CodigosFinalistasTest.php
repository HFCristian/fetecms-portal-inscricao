<?php

namespace Tests\Feature;

use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\MalaDireta;
use App\Models\MalaDiretaDestinatario;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ListaFinalService;
use App\Services\MalaDiretaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 159 — código do projeto: fixado na lista final e enviado por e-mail à
 * equipe de cada finalista.
 */
class CodigosFinalistasTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    private User $admin;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista oficial', 'vigente' => true, 'demo' => false, 'versao' => 1,
        ]);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function finalista(string $titulo, bool $naLista = true): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'categoria' => 'fetecms', 'area_id' => $this->area->id,
        ]);
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'email' => mb_strtolower(str_replace(' ', '', $titulo)).'@aluno.test']);

        if ($naLista) {
            $this->lista->projetos()->attach($projeto->id);
        }

        return $projeto;
    }

    /** @return array<int, string> */
    private function codigos(): array
    {
        return app(ListaFinalService::class)->codigosDaLista($this->lista->fresh());
    }

    public function test_sem_fixar_incluir_um_projeto_empurra_a_numeracao(): void
    {
        $b = $this->finalista('Bioplástico');
        $this->assertSame('FET.AGR-001', $this->codigos()[$b->id]);

        $a = $this->finalista('Abelhas');
        $this->assertSame('FET.AGR-002', $this->codigos()[$b->id]); // mudou
        $this->assertSame('FET.AGR-001', $this->codigos()[$a->id]);
    }

    public function test_depois_de_fixar_o_codigo_nao_muda_e_quem_entra_ganha_o_proximo(): void
    {
        $b = $this->finalista('Bioplástico');
        $c = $this->finalista('Compostagem');

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$this->lista->id}/codigos/fixar")
            ->assertOk()
            ->assertJsonPath('data.lista.codigos_congelados_em', fn ($v) => $v !== null);

        // Entra depois, e na ordem alfabética viria antes dos dois.
        $a = $this->finalista('Abelhas', naLista: false);
        app(ListaFinalService::class)->adicionarProjeto($this->lista->fresh(), $a, $this->admin, 'Credencial de feira afiliada.');

        $codigos = $this->codigos();
        $this->assertSame('FET.AGR-001', $codigos[$b->id]);
        $this->assertSame('FET.AGR-002', $codigos[$c->id]);
        $this->assertSame('FET.AGR-003', $codigos[$a->id]);

        // O TXT da lista sai com os mesmos códigos, na ordem deles.
        $itens = app(ListaFinalService::class)->itensDaLista($this->lista->fresh());
        $this->assertSame(['FET.AGR-001', 'FET.AGR-002', 'FET.AGR-003'], array_column($itens, 'codigo'));
        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::ListaFinalCodigosFixados)->exists());
    }

    public function test_enviar_fixa_e_dispara_a_mala_para_a_equipe_com_o_codigo(): void
    {
        $b = $this->finalista('Bioplástico');

        $resposta = $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$this->lista->id}/codigos/enviar")
            ->assertCreated();

        $mala = MalaDireta::findOrFail($resposta->json('meta.mala_id'));
        $this->assertSame(['finalistas'], $mala->publicos);
        $this->assertStringContainsString('{{projetos}}', $mala->corpo);
        $this->assertSame($mala->id, $this->lista->fresh()->codigos_mala_id);
        $this->assertNotNull($this->lista->fresh()->codigos_congelados_em);

        $aluno = MalaDiretaDestinatario::where('email', 'bioplástico@aluno.test')->firstOrFail();
        $corpo = app(MalaDiretaService::class)->personalizar($mala->corpo, $aluno);
        $this->assertStringContainsString('FET.AGR-001 - Bioplástico', $corpo);

        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::ListaFinalCodigosEnviados)->exists());
    }

    public function test_previa_rascunho_e_lista_substituida_nao_enviam(): void
    {
        $this->finalista('Bioplástico');
        $this->lista->update(['rascunho' => true]);

        $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$this->lista->id}/codigos")
            ->assertOk()
            ->assertJsonPath('data.pode_enviar', false);

        $this->postJson("/api/v1/admin/avaliacao/listas-finais/{$this->lista->id}/codigos/enviar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lista');

        $this->assertSame(0, MalaDireta::count());
    }

    public function test_o_modelo_do_email_e_editavel(): void
    {
        $this->putJson('/api/v1/admin/modelos-email/codigo_projeto', [
            'assunto' => 'Seu código',
            'corpo' => 'Oi, {{nome}}: {{projetos}}',
        ])->assertOk();

        $this->finalista('Bioplástico');

        $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$this->lista->id}/codigos")
            ->assertOk()
            ->assertJsonPath('data.assunto', 'Seu código');
    }
}
