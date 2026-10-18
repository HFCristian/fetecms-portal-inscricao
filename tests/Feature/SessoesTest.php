<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SessoesService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A faxina e o raio-X das sessões (Sprint 244).
 *
 * O que estes testes seguram:
 * - `sessoes:limpar` apaga só o que passou do tempo de vida (5 h por padrão),
 *   com e sem login, e o cache vencido — nunca a sessão em uso;
 * - a faxina está agendada;
 * - `sessoes:diagnostico` conta as sessões e aponta a conta usada em muitos
 *   aparelhos, a tabela sem faxina e o tempo de vida fora do combinado.
 */
class SessoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O .env de quem roda os testes não decide: o combinado é 5 h no banco.
        config(['session.driver' => 'database', 'session.lifetime' => 300, 'cache.default' => 'database']);
    }

    private function sessao(?User $user, int $minutosParada, string $ip = '10.0.0.1'): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user?->id,
            'ip_address' => $ip,
            'user_agent' => 'Teste',
            'payload' => base64_encode('payload'),
            'last_activity' => now()->subMinutes($minutosParada)->getTimestamp(),
        ]);

        return $id;
    }

    public function test_limpar_apaga_so_as_sessoes_paradas_ha_mais_de_5_horas(): void
    {
        $user = User::factory()->create();
        $velhaComLogin = $this->sessao($user, 301);
        $velhaSemLogin = $this->sessao(null, 60 * 24);
        $emUso = $this->sessao($user, 2);
        $quaseNoLimite = $this->sessao(null, 299);

        DB::table('cache')->insert([
            ['key' => 'vencida', 'value' => 's:1:"x";', 'expiration' => now()->subMinute()->getTimestamp()],
            ['key' => 'valendo', 'value' => 's:1:"x";', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $this->artisan('sessoes:limpar')
            ->expectsOutputToContain('2 sessão(ões) parada(s) há mais de 5 h apagada(s); 1 entrada(s) vencida(s) do cache.')
            ->assertSuccessful();

        $restantes = DB::table('sessions')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$emUso, $quaseNoLimite], $restantes);
        $this->assertNotContains($velhaComLogin, $restantes);
        $this->assertNotContains($velhaSemLogin, $restantes);
        $this->assertSame(['valendo'], DB::table('cache')->pluck('key')->all());
    }

    public function test_limpar_aceita_outro_corte_em_horas_e_recusa_valor_invalido(): void
    {
        $this->sessao(null, 90);
        $this->sessao(null, 10);

        $this->artisan('sessoes:limpar', ['--horas' => '1'])
            ->expectsOutputToContain('1 sessão(ões) parada(s) há mais de 1 h apagada(s)')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('sessions')->count());

        $this->artisan('sessoes:limpar', ['--horas' => 'zero'])->assertFailed();
    }

    public function test_limpar_apaga_em_lotes_sem_deixar_sobra(): void
    {
        $linhas = [];
        for ($i = 0; $i < 5003; $i++) {
            $linhas[] = [
                'id' => Str::random(40), 'user_id' => null, 'ip_address' => '10.0.0.9', 'user_agent' => null,
                'payload' => 'x', 'last_activity' => now()->subHours(6)->getTimestamp(),
            ];
        }
        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table('sessions')->insert($lote);
        }

        $this->assertSame(5003, app(SessoesService::class)->limpar());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_fora_do_banco_a_faxina_nao_faz_nada(): void
    {
        config(['session.driver' => 'file']);
        $this->sessao(null, 600);

        $this->artisan('sessoes:limpar')->expectsOutputToContain('não estão no banco')->assertSuccessful();
        $this->assertSame(1, DB::table('sessions')->count());
    }

    public function test_a_faxina_esta_agendada(): void
    {
        $comandos = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

        $this->assertStringContainsString('sessoes:limpar', $comandos);
    }

    public function test_o_tempo_de_vida_padrao_e_5_horas(): void
    {
        // Sem SESSION_LIFETIME no ambiente, o config cai em 300; o .env.example já nasce assim.
        $this->assertStringContainsString("env('SESSION_LIFETIME', 300)", file_get_contents(base_path('config/session.php')));
        $this->assertStringContainsString('SESSION_LIFETIME=300', file_get_contents(base_path('.env.example')));
    }

    public function test_diagnostico_conta_as_sessoes_e_aponta_conta_em_muitos_aparelhos(): void
    {
        $compartilhada = User::factory()->create(['email' => 'balcao@fetecms.test']);
        for ($i = 0; $i < SessoesService::APARELHOS_POR_CONTA; $i++) {
            $this->sessao($compartilhada, 5 + $i, '10.0.0.'.$i);
        }
        $this->sessao(null, 1);
        $this->sessao(null, 400);

        $d = app(SessoesService::class)->diagnostico();

        $this->assertSame(6, $d['sessoes']['total']);
        $this->assertSame(4, $d['sessoes']['autenticadas']);
        $this->assertSame(1, $d['sessoes']['por_faixa']['expiradas']);
        $this->assertSame([['email' => 'balcao@fetecms.test', 'total' => 4]], $d['sessoes']['contas_com_mais_sessoes']);
        $this->assertSame(12, strlen($d['config']['app_key']));
        $this->assertStringNotContainsString((string) config('app.key'), json_encode($d));

        $this->artisan('sessoes:diagnostico')
            ->expectsOutputToContain('balcao@fetecms.test usou 4 sessões na última hora')
            ->assertSuccessful();
    }

    public function test_diagnostico_aponta_tempo_de_vida_fora_do_combinado_e_tabela_sem_faxina(): void
    {
        config(['session.lifetime' => 120]);

        $linhas = [];
        for ($i = 0; $i <= SessoesService::EXPIRADAS_TOLERADAS; $i++) {
            $linhas[] = [
                'id' => Str::random(40), 'user_id' => null, 'ip_address' => '10.0.1.1', 'user_agent' => null,
                'payload' => 'x', 'last_activity' => now()->subHours(3)->getTimestamp(),
            ];
        }
        DB::table('sessions')->insert($linhas);

        $alertas = implode("\n", app(SessoesService::class)->diagnostico()['alertas']);

        $this->assertStringContainsString('SESSION_LIFETIME está em 120 min', $alertas);
        $this->assertStringContainsString('ainda estão na tabela', $alertas);
    }

    public function test_diagnostico_sem_nada_a_apontar(): void
    {
        $this->sessao(User::factory()->create(), 1);

        $this->artisan('sessoes:diagnostico')
            ->expectsOutputToContain('Nenhum ponto de atenção')
            ->assertSuccessful();
    }
}
