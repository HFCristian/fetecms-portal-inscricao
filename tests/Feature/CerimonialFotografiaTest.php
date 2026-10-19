<?php

namespace Tests\Feature;

use App\Enums\StatusPresenca;
use App\Enums\TipoRegistro;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\ContaTemporaria;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\EstandeProjeto;
use App\Models\FotografiaEstande;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 247 — Cerimonial → Fotografias de estandes: o mapa com "registrado",
 * "em registro" e "sem registro", a reserva de quem está fotografando, o
 * registro (câmera ou arquivo de até 100 MB), a substituição e a exclusão.
 */
class CerimonialFotografiaTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function noEstande(string $titulo, int $numero, Turno $turno = Turno::A): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'area_id' => $this->area->id, 'edicao_id' => $this->edicao->id,
        ]);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno->value, 'numero' => $numero]);

        return $projeto;
    }

    private function admin(string $nome = 'Organização'): User
    {
        $admin = User::factory()->admin()->create(['name' => $nome]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function voluntario(string $email = 'foto@fetec.test'): User
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Fotógrafo Voluntário',
            'email' => $email,
            'password' => 'senha-da-foto',
            'cpf' => '52998224725',
            'curso' => 'Fotografia',
            'horas' => 5,
        ], null, ContaTemporaria::SETOR_CERIMONIAL);
        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();

        return $conta->refresh()->user;
    }

    private function enviar(Projeto $projeto, ?UploadedFile $arquivo = null)
    {
        return $this->post("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}", [
            'arquivo' => $arquivo ?? UploadedFile::fake()->create('estande.jpg', 200, 'image/jpeg'),
        ], ['Accept' => 'application/json']);
    }

    private function situacao(int $numero, string $turno = 'A'): ?array
    {
        return $this->getJson("/api/v1/admin/cerimonial/fotografias?turno={$turno}")->assertOk()->json("data.estandes.{$numero}");
    }

    public function test_o_mapa_mostra_cada_estande_do_turno_sem_registro(): void
    {
        $this->noEstande('Bioplástico', 42);
        $this->noEstande('Drone da tarde', 42, Turno::B);
        $this->admin();

        $dados = $this->getJson('/api/v1/admin/cerimonial/fotografias?turno=A')->assertOk()->json('data');

        $this->assertSame('Bioplástico', $dados['estandes'][42]['titulo']);
        $this->assertSame('sem_registro', $dados['estandes'][42]['situacao']);
        $this->assertSame(['registrado' => 0, 'em_registro' => 0, 'sem_registro' => 1], $dados['resumo']);
        $this->assertSame(100, $dados['max_mb']);
        $this->assertNotEmpty($dados['layout']);

        $this->assertSame('Drone da tarde', $this->situacao(42, 'B')['titulo']);
    }

    public function test_confirmar_o_estande_o_deixa_em_registro_e_barra_o_segundo_fotografo(): void
    {
        $projeto = $this->noEstande('Bioplástico', 42);
        $this->admin('Ana Fotógrafa');

        $this->postJson("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}/reserva")
            ->assertOk()->assertJsonPath('data.situacao', 'em_registro')->assertJsonPath('data.em_registro.meu', true);

        $this->admin('Bruno');
        $linha = $this->situacao(42);
        $this->assertSame('em_registro', $linha['situacao']);
        $this->assertSame('Ana Fotógrafa', $linha['em_registro']['por']);
        $this->assertFalse($linha['em_registro']['meu']);

        $this->postJson("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}/reserva")
            ->assertStatus(422)->assertJsonValidationErrors('projeto');
        $this->enviar($projeto)->assertStatus(422);
    }

    public function test_a_reserva_vence_em_quinze_minutos(): void
    {
        $projeto = $this->noEstande('Bioplástico', 42);
        $this->admin('Ana');
        $this->postJson("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}/reserva")->assertOk();

        $this->travel(16)->minutes();

        $this->admin('Bruno');
        $this->assertSame('sem_registro', $this->situacao(42)['situacao']);
        $this->postJson("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}/reserva")->assertOk();
    }

    public function test_cancelar_devolve_o_estande(): void
    {
        $projeto = $this->noEstande('Bioplástico', 42);
        $this->admin();
        $this->postJson("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}/reserva")->assertOk();

        $this->deleteJson("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}/reserva")->assertNoContent();

        $this->assertSame('sem_registro', $this->situacao(42)['situacao']);
        $this->assertSame(0, FotografiaEstande::count());
    }

    public function test_registra_a_fotografia_guarda_o_arquivo_e_entra_em_registros(): void
    {
        $projeto = $this->noEstande('Bioplástico', 42);
        $this->admin('Ana');
        $this->postJson("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}/reserva")->assertOk();

        $this->enviar($projeto)->assertOk()
            ->assertJsonPath('data.situacao', 'registrado')
            ->assertJsonPath('data.foto.registrado_por', 'Ana');

        $foto = FotografiaEstande::firstOrFail();
        Storage::disk('local')->assertExists($foto->caminho);
        $this->assertNull($foto->em_registro_por);
        $this->assertSame('registrado', $this->situacao(42)['situacao']);

        $this->get("/api/v1/admin/cerimonial/fotografias/{$foto->id}/arquivo")->assertOk();

        $registro = RegistroAtividade::where('tipo', TipoRegistro::CerimonialFotografiaRegistrada->value)->firstOrFail();
        $this->assertSame('042 · '.Turno::A->label(), $registro->detalhes['estande']);
    }

    public function test_registrar_de_novo_substitui_e_apaga_o_arquivo_antigo(): void
    {
        $projeto = $this->noEstande('Bioplástico', 42);
        $this->admin();
        $this->enviar($projeto)->assertOk();
        $antigo = FotografiaEstande::firstOrFail()->caminho;

        $this->enviar($projeto, UploadedFile::fake()->create('outra.png', 50, 'image/png'))->assertOk();

        $foto = FotografiaEstande::firstOrFail();
        $this->assertNotSame($antigo, $foto->caminho);
        $this->assertSame('outra.png', $foto->nome_original);
        Storage::disk('local')->assertMissing($antigo);
        Storage::disk('local')->assertExists($foto->caminho);
        $this->assertSame(1, FotografiaEstande::count());
    }

    public function test_aceita_ate_100_mb_e_so_imagem(): void
    {
        $projeto = $this->noEstande('Bioplástico', 42);
        $this->admin();

        $this->enviar($projeto, UploadedFile::fake()->create('grande.jpg', 102401, 'image/jpeg'))
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $this->enviar($projeto, UploadedFile::fake()->create('planilha.pdf', 10, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $this->enviar($projeto, UploadedFile::fake()->create('quase.jpg', 102400, 'image/jpeg'))->assertOk();
    }

    public function test_projeto_sem_estande_nao_tem_fotografia(): void
    {
        $projeto = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        $this->admin();

        $this->postJson("/api/v1/admin/cerimonial/fotografias/projetos/{$projeto->id}/reserva")
            ->assertStatus(422)->assertJsonValidationErrors('projeto');
    }

    public function test_excluir_apaga_a_foto_e_libera_o_estande(): void
    {
        $projeto = $this->noEstande('Bioplástico', 42);
        $this->admin();
        $this->enviar($projeto)->assertOk();
        $foto = FotografiaEstande::firstOrFail();

        $this->deleteJson("/api/v1/admin/cerimonial/fotografias/{$foto->id}")->assertNoContent();

        Storage::disk('local')->assertMissing($foto->caminho);
        $this->assertSame('sem_registro', $this->situacao(42)['situacao']);
        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::CerimonialFotografiaExcluida->value)->exists());
    }

    public function test_o_voluntario_registra_mas_so_exclui_o_que_ele_mesmo_registrou(): void
    {
        $doVoluntario = $this->noEstande('Bioplástico', 42);
        $daOrganizacao = $this->noEstande('Horta', 43);

        $this->admin('Organização');
        $this->enviar($daOrganizacao)->assertOk();

        Sanctum::actingAs($this->voluntario());
        $this->postJson("/api/v1/admin/cerimonial/fotografias/projetos/{$doVoluntario->id}/reserva")->assertOk();
        $this->enviar($doVoluntario)->assertOk()->assertJsonPath('data.foto.pode_excluir', true);

        $alheia = FotografiaEstande::where('projeto_id', $daOrganizacao->id)->firstOrFail();
        $this->assertFalse($this->situacao(43)['foto']['pode_excluir']);
        $this->deleteJson("/api/v1/admin/cerimonial/fotografias/{$alheia->id}")->assertForbidden();

        $propria = FotografiaEstande::where('projeto_id', $doVoluntario->id)->firstOrFail();
        $this->deleteJson("/api/v1/admin/cerimonial/fotografias/{$propria->id}")->assertNoContent();
    }

    public function test_a_foto_segue_o_projeto_quando_ele_muda_de_estande(): void
    {
        $projeto = $this->noEstande('Bioplástico', 42);
        $this->admin();
        $this->enviar($projeto)->assertOk();

        EstandeProjeto::where('projeto_id', $projeto->id)->update(['numero' => 50]);

        $this->assertSame('registrado', $this->situacao(50)['situacao']);
        $this->assertNull($this->situacao(42));
    }

    public function test_admin_sem_a_aba_cerimonial_nao_entra(): void
    {
        $this->noEstande('Bioplástico', 42);
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/cerimonial/fotografias')->assertForbidden();
    }
}
