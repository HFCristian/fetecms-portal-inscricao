<?php

namespace Tests\Feature;

use App\Enums\AbaAdmin;
use App\Enums\StatusPresenca;
use App\Models\ContaTemporaria;
use App\Models\Edicao;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Presença das contas temporárias: a pessoa se anuncia no primeiro acesso do
 * turno e o admin do setor aprova ou rejeita (com motivo).
 */
class PresencaContaTemporariaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function conta(array $extra = [], string $setor = ContaTemporaria::SETOR_CREDENCIAMENTO): ContaTemporaria
    {
        return app(ContaTemporariaService::class)->criar(array_merge([
            'name' => 'Bruna Atendente',
            'email' => 'bruna@balcao.test',
            'password' => 'senha-do-balcao',
            'cpf' => '52998224725',
            'curso' => 'Ciência da Computação',
            'horas' => 5,
        ], $extra), null, $setor);
    }

    public function test_conta_nova_nao_abre_a_aba_antes_de_marcar_presenca(): void
    {
        $conta = $this->conta();
        Sanctum::actingAs($conta->user);

        // Ter crachá não é estar de plantão.
        $this->assertSame([], $conta->user->abasPermitidas());
        $this->assertFalse($conta->user->podeAbrirAba(AbaAdmin::Credenciamento));

        $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertForbidden();
    }

    public function test_pessoa_marca_presenca_e_fica_aguardando(): void
    {
        $conta = $this->conta();
        Sanctum::actingAs($conta->user);

        $this->getJson('/api/v1/contas-temporarias/presenca')
            ->assertOk()
            ->assertJsonPath('data.precisa_marcar', true)
            ->assertJsonPath('data.status', null);

        $this->postJson('/api/v1/contas-temporarias/presenca')
            ->assertOk()
            ->assertJsonPath('data.status', 'pendente')
            ->assertJsonPath('data.aprovada', false);

        // Marcada, mas ainda não aprovada: a aba continua fechada.
        $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertForbidden();
    }

    public function test_marcar_duas_vezes_nao_reabre_a_decisao(): void
    {
        $conta = $this->conta();
        Sanctum::actingAs($conta->user);

        $this->postJson('/api/v1/contas-temporarias/presenca')->assertOk();
        $this->postJson('/api/v1/contas-temporarias/presenca')
            ->assertStatus(422)
            ->assertJsonValidationErrors('presenca');
    }

    public function test_conta_agendada_nao_marca_presenca_antes_da_hora(): void
    {
        $conta = $this->conta(['valido_de' => now()->addDay()->toDateTimeString()]);
        Sanctum::actingAs($conta->user);

        $this->getJson('/api/v1/contas-temporarias/presenca')
            ->assertOk()
            ->assertJsonPath('data.precisa_marcar', false)
            ->assertJsonPath('data.agendada', true);

        $this->postJson('/api/v1/contas-temporarias/presenca')
            ->assertStatus(422)
            ->assertJsonValidationErrors('presenca');
    }

    public function test_admin_do_setor_aprova_e_a_aba_abre(): void
    {
        $conta = $this->conta();
        Sanctum::actingAs($conta->user);
        $this->postJson('/api/v1/contas-temporarias/presenca')->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/presenca", ['aprovar' => true])
            ->assertOk()
            ->assertJsonPath('data.contas.0.presenca', 'aprovada');

        Sanctum::actingAs($conta->user->fresh());
        $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertOk();
    }

    public function test_rejeitar_exige_motivo_e_encerra_o_acesso(): void
    {
        $conta = $this->conta();
        Sanctum::actingAs($conta->user);
        $this->postJson('/api/v1/contas-temporarias/presenca')->assertOk();

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/presenca", ['aprovar' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/presenca", [
            'aprovar' => false,
            'motivo' => 'Não é a pessoa escalada para este turno.',
        ])
            ->assertOk()
            ->assertJsonPath('data.contas.0.presenca', 'rejeitada')
            ->assertJsonPath('data.contas.0.ativa', false);

        $conta->refresh();

        $this->assertSame('Não é a pessoa escalada para este turno.', $conta->presenca_motivo);
        $this->assertSame($admin->id, $conta->presenca_decidida_por);
        $this->assertFalse($conta->user->fresh()->is_active);
    }

    public function test_a_pessoa_rejeitada_ve_o_motivo(): void
    {
        $conta = $this->conta();
        Sanctum::actingAs($conta->user);
        $this->postJson('/api/v1/contas-temporarias/presenca')->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/presenca", [
            'aprovar' => false, 'motivo' => 'Turno já coberto.',
        ])->assertOk();

        // A conta fica inativa, mas o motivo precisa chegar a quem está no balcão.
        $conta->user->update(['is_active' => true]);
        Sanctum::actingAs($conta->user->fresh());

        $this->getJson('/api/v1/contas-temporarias/presenca')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejeitada')
            ->assertJsonPath('data.motivo', 'Turno já coberto.');
    }

    public function test_nao_decide_presenca_que_ninguem_marcou(): void
    {
        $conta = $this->conta();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/presenca", ['aprovar' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('presenca');
    }

    public function test_cada_setor_decide_so_a_presenca_das_contas_dele(): void
    {
        $doAlmoxarifado = $this->conta(
            ['email' => 'caio@fetec.test'],
            ContaTemporaria::SETOR_ALMOXARIFADO,
        );
        Sanctum::actingAs($doAlmoxarifado->user);
        $this->postJson('/api/v1/contas-temporarias/presenca')->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());

        // Do credenciamento, a linha do almoxarifado nem existe.
        $this->patchJson("/api/v1/admin/credenciamento/contas/{$doAlmoxarifado->id}/presenca", ['aprovar' => true])
            ->assertNotFound();

        $this->patchJson("/api/v1/admin/almoxarifado/contas/{$doAlmoxarifado->id}/presenca", ['aprovar' => true])
            ->assertOk();
    }

    public function test_voluntario_presencial_tambem_passa_pela_presenca(): void
    {
        $voluntario = $this->conta(
            ['email' => 'joana@fetec.test', 'turnos' => [[
                'inicio' => now()->subHour()->toDateTimeString(),
                'fim' => now()->addHours(4)->toDateTimeString(),
            ]]],
            ContaTemporaria::SETOR_PRESENCIAL,
        );

        Sanctum::actingAs($voluntario->user);
        $this->assertSame([], $voluntario->user->abasPermitidas());
        $this->postJson('/api/v1/contas-temporarias/presenca')->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson("/api/v1/admin/presencial/contas/{$voluntario->id}/presenca", ['aprovar' => true])
            ->assertOk();

        $this->assertSame(['avaliacao_presencial'], $voluntario->user->fresh()->abasPermitidas());
    }

    public function test_quem_nao_e_conta_temporaria_nao_marca_presenca(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/contas-temporarias/presenca')->assertOk()->assertJsonPath('data', null);
        $this->postJson('/api/v1/contas-temporarias/presenca')->assertForbidden();
    }

    public function test_a_conta_ve_a_propria_presenca_no_auth_me(): void
    {
        $conta = $this->conta();
        $conta->forceFill(['presenca_status' => StatusPresenca::Pendente, 'presenca_em' => now()])->save();
        Sanctum::actingAs($conta->user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.conta_temporaria', true)
            ->assertJsonPath('data.presenca.status', 'pendente')
            ->assertJsonCount(0, 'data.abas');
    }
}
