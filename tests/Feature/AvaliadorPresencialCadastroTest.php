<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use App\Services\FilaAvaliadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 191 — Avaliação presencial → Cadastro de avaliadores: a organização
 * cria a conta de quem avalia só no dia da feira, com a senha gerada, e essa
 * conta fica fora da avaliação online.
 */
class AvaliadorPresencialCadastroTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/presencial/avaliadores-cadastrados';

    private Edicao $edicao;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'avaliacao_liberada_em' => now()->subDay(),
        ]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function dados(array $over = []): array
    {
        return array_merge([
            'name' => 'Rosa Pantaneira',
            'email' => '  rosa.pantaneira@ufms.br ',
            'cpf' => '529.982.247-25',
            'titulacao' => 'Doutorado (concluído)',
            'area_id' => $this->area->id,
        ], $over);
    }

    public function test_admin_cadastra_so_com_os_campos_do_presencial_e_recebe_a_senha_uma_vez(): void
    {
        $admin = $this->admin();

        $resposta = $this->postJson(self::URL, $this->dados())->assertCreated();

        $senha = $resposta->json('meta.senha');
        $this->assertSame(10, strlen($senha));
        $resposta->assertJsonPath('data.nome', 'Rosa Pantaneira')
            ->assertJsonPath('data.area', 'Ciências Agrárias')
            ->assertJsonPath('data.cadastrado_por', $admin->name);

        $user = User::where('email', 'rosa.pantaneira@ufms.br')->firstOrFail();
        $this->assertSame(Role::Avaliador, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($senha, $user->password));

        $perfil = $user->avaliadorProfile;
        $this->assertSame('52998224725', $perfil->cpf);
        $this->assertTrue($perfil->apenas_presencial);
        // Já nasce com o "sim" da aba Presencial: a conta existe para isso.
        $this->assertTrue($perfil->presencial);
        $this->assertSame($admin->id, $perfil->cadastrado_por);
        $this->assertNull($perfil->camiseta);
        $this->assertNull($perfil->cidade_id);
        $this->assertTrue($user->ehAvaliadorSoPresencial());
    }

    public function test_subarea_nova_entra_no_catalogo(): void
    {
        $this->admin();

        $this->postJson(self::URL, $this->dados(['subarea_nome' => 'Agroecologia']))->assertCreated()
            ->assertJsonPath('data.subarea', 'Agroecologia');

        $this->assertTrue(Subarea::where('nome', 'Agroecologia')->where('area_id', $this->area->id)->exists());
    }

    public function test_valida_como_o_cadastro_publico_inclusive_a_exclusao_mutua(): void
    {
        $this->admin();
        OrientadorProfile::factory()->create(['cpf' => '52998224725']);
        User::factory()->create(['email' => 'ja.existe@ufms.br']);

        $this->postJson(self::URL, $this->dados())
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpf');

        $this->postJson(self::URL, $this->dados(['cpf' => '11144477735', 'email' => 'ja.existe@ufms.br']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson(self::URL, $this->dados(['cpf' => '11144477735', 'titulacao' => 'Graduação', 'area_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['titulacao', 'area_id']);
    }

    public function test_lista_so_os_cadastrados_para_o_presencial(): void
    {
        $this->admin();
        AvaliadorProfile::factory()->create(['user_id' => User::factory()->avaliador()->create(['name' => 'Avaliador da online'])->id]);
        $this->postJson(self::URL, $this->dados())->assertCreated();

        $nomes = array_column($this->getJson(self::URL)->assertOk()->json('data'), 'nome');

        $this->assertSame(['Rosa Pantaneira'], $nomes);
        $this->assertSame([], $this->getJson(self::URL.'?q=ninguem')->json('data'));
    }

    public function test_gera_senha_nova_e_a_anterior_deixa_de_valer(): void
    {
        $this->admin();
        $antiga = $this->postJson(self::URL, $this->dados())->json('meta.senha');
        $user = User::where('email', 'rosa.pantaneira@ufms.br')->firstOrFail();

        $nova = $this->postJson(self::URL."/{$user->id}/senha")->assertOk()->json('meta.senha');

        $this->assertNotSame($antiga, $nova);
        $this->assertTrue(Hash::check($nova, $user->refresh()->password));
        $this->assertFalse(Hash::check($antiga, $user->password));
    }

    public function test_senha_nova_so_para_conta_do_presencial(): void
    {
        $this->admin();
        $daOnline = AvaliadorProfile::factory()->create()->user;

        $this->postJson(self::URL."/{$daOnline->id}/senha")
            ->assertStatus(422)
            ->assertJsonValidationErrors('avaliador');
    }

    // --- Fora da avaliação online -----------------------------------------

    private function soPresencial(): User
    {
        $user = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $this->area->id,
            'presencial' => true, 'apenas_presencial' => true,
        ]);

        return $user;
    }

    public function test_a_fila_online_nao_alcanca_quem_e_so_do_presencial(): void
    {
        Projeto::factory()->submetido()->create(['area_id' => $this->area->id, 'edicao_id' => $this->edicao->id]);
        $user = $this->soPresencial();

        $this->assertSame(0, app(FilaAvaliadorService::class)->repor($user));
        $this->assertSame(0, Avaliacao::where('avaliador_id', $user->id)->count());
    }

    public function test_designacao_online_nao_oferece_nem_aceita_quem_e_so_do_presencial(): void
    {
        $this->admin();
        $projeto = Projeto::factory()->submetido()->create(['area_id' => $this->area->id, 'edicao_id' => $this->edicao->id]);
        $soPresencial = $this->soPresencial();
        $daOnline = AvaliadorProfile::factory()->create(['area_id' => $this->area->id])->user;

        $opcoes = array_column($this->getJson('/api/v1/admin/avaliacao/avaliadores/opcoes')->json('data'), 'id');
        $this->assertContains($daOnline->id, $opcoes);
        $this->assertNotContains($soPresencial->id, $opcoes);

        // A área inteira: só quem é da online recebe.
        $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/designar", ['tipo' => 'area', 'alvo_id' => $this->area->id])
            ->assertOk();
        $this->assertTrue(Avaliacao::where('avaliador_id', $daOnline->id)->exists());
        $this->assertFalse(Avaliacao::where('avaliador_id', $soPresencial->id)->exists());

        // Nem pela designação em massa, trocando o id no payload.
        $this->postJson('/api/v1/admin/avaliacao/designacoes/designar', [
            'projeto_ids' => [$projeto->id], 'avaliador_ids' => [$soPresencial->id],
        ])->assertStatus(422)->assertJsonValidationErrors('avaliador_ids');
    }

    public function test_quem_e_so_do_presencial_nao_desiste_da_intencao(): void
    {
        $user = $this->soPresencial();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/avaliador/presencial')->assertOk()
            ->assertJsonPath('data.pode_alterar', false)
            ->assertJsonPath('data.apenas_presencial', true);

        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => false])
            ->assertStatus(422);
        $this->assertTrue($user->avaliadorProfile->refresh()->presencial);
    }

    public function test_so_quem_abre_a_aba_avaliacao_presencial(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, $this->dados())->assertForbidden();
    }
}
