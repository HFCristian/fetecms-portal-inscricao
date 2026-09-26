<?php

namespace Tests\Feature;

use App\Enums\PublicoMala;
use App\Models\Area;
use App\Models\AvaliadorProfile;
use App\Models\User;
use App\Services\PublicoUsuariosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Idiomas em que o avaliador se declara apto a avaliar (Sprint 154).
 *
 * Ao contrário da camiseta, **ao menos um é obrigatório**: a feira recebe
 * projeto de fora, e a organização precisa saber a quem mandar esse trabalho.
 * Quem já estava cadastrado antes do campo fica com a lista vazia — é o que os
 * testes de recorte cobram aqui, porque um público que alcançasse essas contas
 * diria que elas responderam quando não responderam.
 */
class AvaliadorIdiomasTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->area = Area::create(['nome' => 'Exatas']);
    }

    /** @param  array<string, mixed>  $extra */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Avaliadora Ana',
            'email' => 'ana@exemplo.test',
            'cpf' => '52998224725',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'titulacao' => 'Mestrado (em andamento)',
            'idiomas' => ['pt', 'en'],
            'area_id' => $this->area->id,
        ], $extra);
    }

    /** @param  list<string>  $idiomas */
    private function avaliadorCom(array $idiomas, string $nome = 'Avaliador'): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome]);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $this->area->id, 'idiomas' => $idiomas,
        ]);

        return $user;
    }

    // --- Cadastro ---

    public function test_cadastro_grava_os_idiomas_marcados(): void
    {
        $this->cadastrarAvaliadorPelaApi($this->payload())->assertStatus(201);

        $this->assertSame(
            ['pt', 'en'],
            User::where('email', 'ana@exemplo.test')->firstOrFail()->avaliadorProfile->idiomas,
        );
    }

    public function test_cadastro_sem_idioma_e_recusado(): void
    {
        $this->cadastrarAvaliadorPelaApi($this->payload(['idiomas' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('idiomas');

        $this->cadastrarAvaliadorPelaApi($this->payload(['idiomas' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('idiomas');
    }

    public function test_codigo_desconhecido_e_descartado_e_sozinho_derruba_o_cadastro(): void
    {
        // Código fora da lista é defeito de cliente, não erro de quem preenche:
        // a normalização o descarta e o que veio de válido continua valendo.
        $this->cadastrarAvaliadorPelaApi($this->payload(['idiomas' => ['pt', 'fr']]))
            ->assertStatus(201);

        $this->assertSame(
            ['pt'],
            User::where('email', 'ana@exemplo.test')->firstOrFail()->avaliadorProfile->idiomas,
        );

        // Sobrando nada de válido, porém, o cadastro não passa: é o mesmo caso
        // de quem não marcou idioma nenhum.
        $this->cadastrarAvaliadorPelaApi($this->payload(['email' => 'outra@exemplo.test', 'idiomas' => ['fr']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('idiomas');
    }

    public function test_repetido_e_fora_de_ordem_chega_normalizado(): void
    {
        // Duas pessoas que marcaram os mesmos idiomas precisam gravar o mesmo
        // valor, independentemente da ordem em que clicaram nas caixas.
        $this->cadastrarAvaliadorPelaApi($this->payload(['idiomas' => ['en', 'pt', 'en']]))
            ->assertStatus(201);

        $this->assertSame(
            ['pt', 'en'],
            User::where('email', 'ana@exemplo.test')->firstOrFail()->avaliadorProfile->idiomas,
        );
    }

    // --- Perfil ---

    public function test_perfil_mostra_os_idiomas_e_as_opcoes(): void
    {
        Sanctum::actingAs($this->avaliadorCom(['es']));

        $this->getJson('/api/v1/avaliador/perfil')
            ->assertOk()
            ->assertJsonPath('data.idiomas', ['es'])
            ->assertJsonPath('data.idiomas_opcoes.0.value', 'pt')
            ->assertJsonPath('data.idiomas_opcoes.1.label', 'Espanhol');
    }

    public function test_avaliador_troca_os_proprios_idiomas(): void
    {
        $user = $this->avaliadorCom(['pt']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/avaliador/perfil/idiomas', ['idiomas' => ['en', 'pt']])
            ->assertOk()
            ->assertJsonPath('data.idiomas', ['pt', 'en']);

        $this->assertSame(['pt', 'en'], $user->avaliadorProfile->fresh()->idiomas);
    }

    public function test_perfil_nao_aceita_ficar_sem_idioma(): void
    {
        $user = $this->avaliadorCom(['pt']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/avaliador/perfil/idiomas', ['idiomas' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idiomas');

        // Recusado significa **nada mudou**: a lista anterior continua valendo.
        $this->assertSame(['pt'], $user->avaliadorProfile->fresh()->idiomas);
    }

    public function test_avaliador_antigo_vem_sem_idioma_declarado(): void
    {
        $user = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $this->area->id, 'idiomas' => null,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/avaliador/perfil')->assertOk()->assertJsonPath('data.idiomas', []);
    }

    // --- Admin: tabela, filtro e CSV ---

    public function test_tabela_do_admin_traz_os_idiomas_e_filtra_por_um(): void
    {
        $this->avaliadorCom(['pt', 'en'], 'Ana Bilíngue');
        $this->avaliadorCom(['pt'], 'Bruno Só Português');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores')
            ->assertOk()
            ->assertJsonPath('data.0.idiomas', ['pt', 'en'])
            ->assertJsonPath('data.0.idiomas_label', 'Português, Inglês');

        $resposta = $this->getJson('/api/v1/admin/avaliacao/avaliadores?idioma=en')->assertOk();
        $this->assertCount(1, $resposta->json('data'));
        $this->assertSame('Ana Bilíngue', $resposta->json('data.0.nome'));
    }

    public function test_filtro_com_idioma_desconhecido_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/avaliadores?idioma=fr')
            ->assertStatus(422)
            ->assertJsonValidationErrors('idioma');
    }

    public function test_csv_de_avaliadores_leva_a_coluna_de_idiomas(): void
    {
        $this->avaliadorCom(['es'], 'Carla');
        Sanctum::actingAs(User::factory()->admin()->create());

        $csv = $this->get('/api/v1/admin/avaliacao/avaliadores/exportar')->assertOk()->getContent();

        $this->assertStringContainsString('Idiomas', $csv);
        $this->assertStringContainsString('Espanhol', $csv);
    }

    // --- Públicos e painel ---

    public function test_publico_por_idioma_alcanca_so_quem_declarou(): void
    {
        $ana = $this->avaliadorCom(['pt', 'en'], 'Ana');
        $bruno = $this->avaliadorCom(['pt'], 'Bruno');

        $ingles = app(PublicoUsuariosService::class)
            ->query(PublicoMala::AvaliadoresIngles)->pluck('id')->all();

        $this->assertContains($ana->id, $ingles);
        $this->assertNotContains($bruno->id, $ingles);
    }

    public function test_publico_por_idioma_nao_alcanca_quem_nunca_respondeu(): void
    {
        $user = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'area_id' => $this->area->id, 'idiomas' => null,
        ]);

        $portugues = app(PublicoUsuariosService::class)
            ->query(PublicoMala::AvaliadoresPortugues)->pluck('id')->all();

        $this->assertNotContains($user->id, $portugues);
    }

    public function test_painel_conta_cada_idioma_e_a_soma_pode_passar_do_total(): void
    {
        $this->avaliadorCom(['pt', 'en'], 'Ana');
        $this->avaliadorCom(['pt'], 'Bruno');
        // Conta de ensaio fica fora, como em todos os demais cards.
        $demo = User::factory()->avaliador()->create(['is_demo' => true]);
        AvaliadorProfile::factory()->create([
            'user_id' => $demo->id, 'area_id' => $this->area->id, 'idiomas' => ['es'],
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $painel = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.avaliadores_idiomas');

        $this->assertSame(2, $painel['total']);
        $this->assertSame(['pt', 'es', 'en'], array_column($painel['idiomas'], 'codigo'));
        $this->assertSame([2, 0, 1], array_column($painel['idiomas'], 'total'));
    }
}
