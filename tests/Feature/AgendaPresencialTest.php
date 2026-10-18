<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Turno;
use App\Models\Edicao;
use App\Models\User;
use App\Support\JanelaTurnos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 224 — Parametrização → Datas e períodos → turnos da avaliação
 * presencial: o horário de cada turno em cada dia do evento, e o da avaliação
 * dentro dele.
 */
class AgendaPresencialTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/agenda-presencial';

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 10, 19, 9, 0));

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => '2026-10-20 07:00', 'evento_ate' => '2026-10-21 18:00',
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /** @return array<string, string> */
    private function turno(string $inicio, string $fim, string $avaliacaoInicio = '', string $avaliacaoFim = ''): array
    {
        return ['inicio' => $inicio, 'fim' => $fim, 'avaliacao_inicio' => $avaliacaoInicio, 'avaliacao_fim' => $avaliacaoFim];
    }

    public function test_cada_dia_tem_os_seus_turnos_e_a_sua_avaliacao(): void
    {
        $this->putJson(self::URL, ['agenda' => [
            '2026-10-20' => [
                'A' => $this->turno('08:00', '12:00', '08:30', '12:15'),
                'B' => $this->turno('13:30', '17:30'),
            ],
            // No segundo dia só há o turno da tarde.
            '2026-10-21' => [
                'A' => $this->turno('', ''),
                'B' => $this->turno('13:00', '17:00', '13:00', '17:00'),
            ],
        ]])->assertOk()
            ->assertJsonPath('meta.message', 'Horários dos turnos salvos.')
            ->assertJsonCount(2, 'data.dias')
            ->assertJsonPath('data.agenda.2026-10-20.A.avaliacao_inicio', '08:30')
            // Avaliação em branco: do início do turno até 30 minutos depois do fim.
            ->assertJsonPath('data.agenda.2026-10-20.B.avaliacao_inicio', '13:30')
            ->assertJsonPath('data.agenda.2026-10-20.B.avaliacao_fim', '18:00')
            ->assertJsonPath('data.agenda.2026-10-21.A', null)
            ->assertJsonPath('data.agenda.2026-10-21.B.inicio', '13:00')
            ->assertJsonCount(3, 'data.ocorrencias');

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.agenda.2026-10-20.A.avaliacao_fim', '12:15');

        $janela = JanelaTurnos::daEdicao($this->edicao->fresh());
        $this->assertFalse($janela->existe('2026-10-21', Turno::A));
        $this->assertSame('2026-10-20 12:15', $janela->prazo('2026-10-20', Turno::A)->format('Y-m-d H:i'));
    }

    public function test_comeca_no_inicio_da_avaliacao_e_termina_no_fim_dela(): void
    {
        // Turno 08:00–12:00, avaliação 08:30–12:15.
        $this->edicao->update(['agenda_presencial' => ['2026-10-20' => ['A' => $this->turno('08:00', '12:00', '08:30', '12:15')]]]);
        $janela = fn () => JanelaTurnos::daEdicao($this->edicao->fresh());
        $momento = fn (int $h, int $m) => now()->setDateTime(2026, 10, 20, $h, $m);

        // Antes da avaliação, nada abre — mesmo com a equipe no estande.
        $this->assertSame('futuro', $janela()->ocorrencia('2026-10-20', Turno::A, $momento(8, 15))['situacao']);
        $this->assertFalse($janela()->aberta('2026-10-20', Turno::A, $momento(8, 15)));

        $this->assertTrue($janela()->emAndamento('2026-10-20', Turno::A, $momento(9, 0)));

        // Depois do fim do turno: só termina o que abriu.
        $this->assertSame('margem', $janela()->ocorrencia('2026-10-20', Turno::A, $momento(12, 5))['situacao']);
        $this->assertFalse($janela()->emAndamento('2026-10-20', Turno::A, $momento(12, 5)));
        $this->assertTrue($janela()->aberta('2026-10-20', Turno::A, $momento(12, 5)));

        $this->assertSame('encerrado', $janela()->ocorrencia('2026-10-20', Turno::A, $momento(12, 16))['situacao']);
    }

    public function test_avaliacao_que_acaba_antes_do_turno_fecha_antes(): void
    {
        $this->edicao->update(['agenda_presencial' => ['2026-10-20' => ['A' => $this->turno('08:00', '12:00', '08:00', '11:00')]]]);
        $janela = JanelaTurnos::daEdicao($this->edicao->fresh());

        $this->assertSame('encerrado', $janela->ocorrencia('2026-10-20', Turno::A, now()->setDateTime(2026, 10, 20, 11, 30))['situacao']);
    }

    public function test_horario_invalido_e_recusado_dizendo_onde(): void
    {
        $this->putJson(self::URL, ['agenda' => ['2026-10-20' => ['A' => $this->turno('12:00', '08:00')]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('agenda.2026-10-20.A.fim')
            ->assertJsonPath('errors', fn ($e) => str_contains(implode(' ', array_merge(...array_values($e))), '20/10, Turno A (matutino): o turno precisa terminar depois de começar.'));

        // Só uma ponta da avaliação.
        $this->putJson(self::URL, ['agenda' => ['2026-10-20' => ['A' => $this->turno('08:00', '12:00', '08:30')]]])
            ->assertStatus(422)->assertJsonValidationErrors('agenda.2026-10-20.A.avaliacao_inicio');

        // Avaliação fora do turno.
        $this->putJson(self::URL, ['agenda' => ['2026-10-20' => ['A' => $this->turno('08:00', '12:00', '13:00', '14:00')]]])
            ->assertStatus(422)->assertJsonValidationErrors('agenda.2026-10-20.A.avaliacao_inicio');

        // Dia fora do período do evento.
        $this->putJson(self::URL, ['agenda' => ['2026-10-25' => ['A' => $this->turno('08:00', '12:00')]]])
            ->assertStatus(422)->assertJsonValidationErrors('agenda.2026-10-25.A');

        $this->assertNull($this->edicao->fresh()->agenda_presencial);
    }

    public function test_sem_periodo_do_evento_nao_ha_dias(): void
    {
        $this->edicao->update(['evento_de' => null, 'evento_ate' => null]);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data.dias');
        $this->putJson(self::URL, ['agenda' => []])->assertStatus(422)->assertJsonValidationErrors('agenda');
    }

    public function test_so_a_aba_parametrizacao(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Avaliador->value]));

        $this->getJson(self::URL)->assertForbidden();
    }
}
