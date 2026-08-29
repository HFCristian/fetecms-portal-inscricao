<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Abertura das inscrições: antes da data nada de projeto (a área do orientador
 * fica só de leitura) e nem de cadastro novo de orientador. O admin passa por
 * cima. Passado o prazo, o cadastro volta a ser permitido — só o projeto que
 * continua travado (ver PrazoInscricoesTest).
 */
class JanelaInscricoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    private function definirJanela(?string $de = null, ?string $ate = null): void
    {
        Edicao::atual()->update(['submissoes_de' => $de, 'submissoes_ate' => $ate]);
    }

    private function cadastro(array $overrides = []): array
    {
        return array_merge([
            'name' => 'João da Silva Santos',
            'email' => 'joao@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
            'genero' => 'M',
            'camiseta' => 'G',
        ], $overrides);
    }

    public function test_antes_da_abertura_orientador_nao_cria_projeto(): void
    {
        $this->definirJanela(de: now()->addDays(3)->toDateTimeString());
        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador]));

        $this->postJson('/api/v1/projetos', ['titulo' => 'Novo projeto'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INSCRICOES_NAO_INICIADAS');
    }

    public function test_antes_da_abertura_cadastro_de_orientador_fica_fechado(): void
    {
        $this->definirJanela(de: now()->addDays(3)->toDateTimeString());

        $this->cadastrarOrientadorPelaApi($this->cadastro())
            ->assertStatus(422)
            ->assertJsonPath('code', 'INSCRICOES_NAO_INICIADAS');

        $this->assertDatabaseMissing('users', ['email' => 'joao@escola.ms.gov.br']);
    }

    public function test_antes_da_abertura_cadastro_de_avaliador_continua_liberado(): void
    {
        $this->definirJanela(de: now()->addDays(3)->toDateTimeString());

        $this->cadastrarAvaliadorPelaApi([
            'name' => 'Maria Avaliadora',
            'email' => 'maria@ufms.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
            'genero' => 'F',
            'titulacao' => 'Mestrado (concluído)',
            'instituicao_vinculo' => 'UFMS',
            'area_id' => Area::first()->id,
        ])->assertCreated();
    }

    public function test_depois_da_abertura_o_fluxo_volta_ao_normal(): void
    {
        $this->definirJanela(de: now()->subDay()->toDateTimeString());

        $this->cadastrarOrientadorPelaApi($this->cadastro())->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador]));
        $this->postJson('/api/v1/projetos', ['titulo' => 'Novo projeto'])->assertCreated();
    }

    public function test_depois_do_prazo_o_cadastro_de_conta_continua_permitido(): void
    {
        $this->definirJanela(de: now()->subDays(10)->toDateTimeString(), ate: now()->subDay()->toDateTimeString());

        $this->cadastrarOrientadorPelaApi($this->cadastro())->assertCreated();
    }

    public function test_admin_passa_por_cima_da_abertura(): void
    {
        $this->definirJanela(de: now()->addDays(3)->toDateTimeString());
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/projetos', ['titulo' => 'Projeto do admin'])->assertCreated();
    }

    public function test_admin_define_e_remove_a_data_de_abertura(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/inscricoes/inicio', ['inicio' => '2026-09-01T08:00'])
            ->assertOk()
            ->assertJsonPath('data.inicio_label', '01/09/2026 08:00')
            ->assertJsonPath('data.nao_iniciadas', true)
            ->assertJsonPath('data.abertas', false);

        $this->patchJson('/api/v1/admin/inscricoes/inicio', ['inicio' => null])
            ->assertOk()
            ->assertJsonPath('data.inicio_input', null)
            ->assertJsonPath('data.abertas', true);
    }

    public function test_abertura_precisa_ser_antes_do_prazo(): void
    {
        $this->definirJanela(ate: '2026-09-30 23:59');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/inscricoes/inicio', ['inicio' => '2026-10-05T08:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('inicio');

        $this->patchJson('/api/v1/admin/inscricoes/prazo', ['prazo' => '2026-08-01T08:00'])
            ->assertOk(); // prazo depois da abertura (que não existe ainda) continua valendo
    }

    public function test_prazo_precisa_ser_depois_da_abertura(): void
    {
        $this->definirJanela(de: '2026-09-01 08:00');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/inscricoes/prazo', ['prazo' => '2026-08-20T08:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prazo');
    }

    public function test_status_publico_informa_a_janela_sem_login(): void
    {
        $this->definirJanela(de: now()->addDay()->toDateTimeString());

        $this->getJson('/api/v1/inscricoes/publico')
            ->assertOk()
            ->assertJsonPath('data.nao_iniciadas', true)
            ->assertJsonPath('data.abertas', false)
            ->assertJsonPath('data.inicio_label', fn ($v) => $v !== null);
    }
}
