<?php

namespace Tests\Feature;

use App\Enums\StatusPresenca;
use App\Models\ContaTemporaria;
use App\Models\Edicao;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Voluntários da avaliação presencial: contas temporárias com vários turnos de
 * trabalho definidos de uma vez.
 */
class VoluntariosPresenciaisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function comoAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Joana Voluntária',
            'email' => 'joana@fetecms.test',
            'password' => 'senha-do-balcao',
            'password_confirmation' => 'senha-do-balcao',
            'cpf' => '52998224725',
            'curso' => 'Engenharia',
        ], $over);
    }

    public function test_cadastra_voluntario_com_varios_turnos(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/contas', $this->payload([
            'turnos' => [
                ['inicio' => now()->addDays(2)->setTime(8, 0)->toDateTimeString(), 'fim' => now()->addDays(2)->setTime(12, 0)->toDateTimeString()],
                ['inicio' => now()->addDays(3)->setTime(13, 0)->toDateTimeString(), 'fim' => now()->addDays(3)->setTime(18, 0)->toDateTimeString()],
            ],
        ]))->assertCreated()->assertJsonCount(2, 'data.contas.0.turnos');

        $conta = ContaTemporaria::sole();

        $this->assertSame('avaliacao_presencial', $conta->setor);
        $this->assertSame(2, $conta->turnos()->count());

        // A janela vira o envelope: do primeiro início ao último fim.
        $this->assertTrue($conta->valido_de->equalTo(now()->addDays(2)->setTime(8, 0)));
        $this->assertTrue($conta->expira_em->equalTo(now()->addDays(3)->setTime(18, 0)));
    }

    public function test_a_conta_do_voluntario_abre_so_a_aba_presencial(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/contas', $this->payload([
            'turnos' => [['inicio' => now()->subHour()->toDateTimeString(), 'fim' => now()->addHours(4)->toDateTimeString()]],
        ]))->assertCreated();

        $conta = ContaTemporaria::sole();
        // A presença aprovada é o que abre a aba (Sprint 136).
        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();

        $this->assertSame(['avaliacao_presencial'], $conta->user->fresh()->abasPermitidas());
    }

    public function test_entre_turnos_a_conta_nao_abre(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/contas', $this->payload([
            'turnos' => [
                ['inicio' => now()->subDays(2)->setTime(8, 0)->toDateTimeString(), 'fim' => now()->subDays(2)->setTime(12, 0)->toDateTimeString()],
                ['inicio' => now()->addDay()->setTime(8, 0)->toDateTimeString(), 'fim' => now()->addDay()->setTime(12, 0)->toDateTimeString()],
            ],
        ]))->assertCreated();

        $conta = ContaTemporaria::sole();
        $servico = app(ContaTemporariaService::class);

        // Dentro do envelope, fora dos turnos: a conta existe e não abre.
        $this->assertFalse($conta->vencida());
        $this->assertFalse($conta->dentroDeTurno());
        $this->assertStringContainsString('próximo turno', (string) $servico->impedimentoDeLogin($conta->user));
    }

    public function test_durante_o_turno_a_conta_abre(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/contas', $this->payload([
            'turnos' => [['inicio' => now()->subHour()->toDateTimeString(), 'fim' => now()->addHours(3)->toDateTimeString()]],
        ]))->assertCreated();

        $conta = ContaTemporaria::sole();

        $this->assertTrue($conta->dentroDeTurno());
        $this->assertNull(app(ContaTemporariaService::class)->impedimentoDeLogin($conta->user));
    }

    public function test_conta_sem_turnos_continua_valendo_pela_janela(): void
    {
        $this->comoAdmin();

        // O balcão do credenciamento não usa turnos — e nada muda para ele.
        $this->postJson('/api/v1/admin/credenciamento/contas', $this->payload(['horas' => 5]))
            ->assertCreated();

        $conta = ContaTemporaria::sole();

        $this->assertSame(0, $conta->turnos()->count());
        $this->assertTrue($conta->dentroDeTurno());
        $this->assertNull(app(ContaTemporariaService::class)->impedimentoDeLogin($conta->user));
    }

    public function test_turnos_sobrepostos_sao_recusados(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/contas', $this->payload([
            'turnos' => [
                ['inicio' => now()->addDay()->setTime(8, 0)->toDateTimeString(), 'fim' => now()->addDay()->setTime(14, 0)->toDateTimeString()],
                ['inicio' => now()->addDay()->setTime(12, 0)->toDateTimeString(), 'fim' => now()->addDay()->setTime(18, 0)->toDateTimeString()],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('turnos');
    }

    public function test_turno_invertido_e_recusado(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/contas', $this->payload([
            'turnos' => [
                ['inicio' => now()->addDay()->setTime(18, 0)->toDateTimeString(), 'fim' => now()->addDay()->setTime(8, 0)->toDateTimeString()],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('turnos.0.fim');
    }

    public function test_renovar_troca_a_escala_quando_ela_e_informada(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/contas', $this->payload([
            'turnos' => [['inicio' => now()->addDay()->setTime(8, 0)->toDateTimeString(), 'fim' => now()->addDay()->setTime(12, 0)->toDateTimeString()]],
        ]))->assertCreated();

        $conta = ContaTemporaria::sole();

        // Sem citar turnos, a escala fica como está.
        $this->patchJson("/api/v1/admin/presencial/contas/{$conta->id}/renovar", ['horas' => 10])->assertOk();
        $this->assertSame(1, $conta->fresh()->turnos()->count());

        $this->patchJson("/api/v1/admin/presencial/contas/{$conta->id}/renovar", [
            'turnos' => [
                ['inicio' => now()->addDays(4)->setTime(8, 0)->toDateTimeString(), 'fim' => now()->addDays(4)->setTime(12, 0)->toDateTimeString()],
                ['inicio' => now()->addDays(5)->setTime(8, 0)->toDateTimeString(), 'fim' => now()->addDays(5)->setTime(12, 0)->toDateTimeString()],
            ],
        ])->assertOk();

        $this->assertSame(2, $conta->fresh()->turnos()->count());
    }

    public function test_as_listas_dos_setores_sao_independentes(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/presencial/contas', $this->payload([
            'turnos' => [['inicio' => now()->addDay()->toDateTimeString(), 'fim' => now()->addDay()->addHours(4)->toDateTimeString()]],
        ]))->assertCreated();

        $this->getJson('/api/v1/admin/presencial/contas')->assertOk()->assertJsonCount(1, 'data.contas');
        $this->getJson('/api/v1/admin/credenciamento/contas')->assertOk()->assertJsonCount(0, 'data.contas');
    }
}
