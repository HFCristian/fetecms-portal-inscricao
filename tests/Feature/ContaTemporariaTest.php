<?php

namespace Tests\Feature;

use App\Enums\AbaAdmin;
use App\Models\ContaTemporaria;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 82 — contas temporárias de credenciamento: acesso de prazo curto para
 * quem atende o balcão sem fazer parte da organização.
 *
 * Sprint 87 — a janela passou a ser contada em **horas** (padrão 5) e ganhou um
 * **início agendável**: a conta pode nascer pronta para abrir só na hora do
 * evento.
 */
class ContaTemporariaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // O catálogo traz a edição padrão, que os escopos de admin exigem.
        $this->seed(CatalogoSeeder::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Bruna Atendente',
            'email' => 'bruna@balcao.test',
            'password' => 'senha-do-balcao',
            'password_confirmation' => 'senha-do-balcao',
            'cpf' => '52998224725',
            'curso' => 'Ciência da Computação',
            'horas' => 3,
        ], $extra);
    }

    private function criar(array $extra = []): ContaTemporaria
    {
        return app(ContaTemporariaService::class)->criar($this->payload($extra));
    }

    public function test_admin_cria_conta_temporaria_com_cpf_curso_e_prazo(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/credenciamento/contas', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.contas.0.nome', 'Bruna Atendente')
            ->assertJsonPath('data.contas.0.curso', 'Ciência da Computação')
            // O CPF é gravado só com dígitos e volta com máscara.
            ->assertJsonPath('data.contas.0.cpf', '529.982.247-25')
            ->assertJsonPath('data.contas.0.ativa', true)
            ->assertJsonPath('data.contas.0.vencida', false);

        $this->assertDatabaseHas('contas_temporarias', ['cpf' => '52998224725']);
        $this->assertDatabaseHas('users', ['email' => 'bruna@balcao.test', 'role' => 'admin']);
    }

    public function test_cpf_invalido_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/credenciamento/contas', $this->payload(['cpf' => '11111111111']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpf');
    }

    /**
     * A trava dura: a conta abre a aba Credenciamento e nada mais, mesmo sem
     * escopo nenhum atribuído (que, para um admin comum, seria acesso total).
     */
    public function test_conta_temporaria_so_abre_a_aba_credenciamento(): void
    {
        $conta = $this->criar();
        $user = $conta->user->fresh();

        $this->assertSame([AbaAdmin::Credenciamento->value], $user->abasPermitidas());
        $this->assertTrue($user->podeAbrirAba(AbaAdmin::Credenciamento));
        $this->assertFalse($user->podeAbrirAba(AbaAdmin::Administradores));
        $this->assertFalse($user->podeAbrirAba(AbaAdmin::Projetos));

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertOk();
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->getJson('/api/v1/admin/admins')->assertForbidden();
    }

    /** Nem um escopo de acesso total dado por engano fura a trava. */
    public function test_escopo_amplo_nao_amplia_uma_conta_temporaria(): void
    {
        $conta = $this->criar();
        $total = EscopoAdmin::create(['nome' => 'Acesso total', 'abas' => AbaAdmin::valores()]);
        $conta->user->escopos()->attach($total->id, ['edicao_id' => Edicao::padrao()->id]);

        $user = $conta->user->fresh();
        $this->assertSame([AbaAdmin::Credenciamento->value], $user->abasPermitidas());

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_conta_vencida_e_desativada_ao_listar(): void
    {
        $conta = $this->criar();
        $conta->update(['expira_em' => now()->subMinute()]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/credenciamento/contas')
            ->assertOk()
            ->assertJsonPath('data.contas.0.vencida', true)
            ->assertJsonPath('data.contas.0.ativa', false)
            ->assertJsonPath('data.contas.0.horas_restantes', 0);

        $this->assertFalse($conta->user->fresh()->is_active);
    }

    /** O prazo vale mesmo que ninguém abra a tela de contas. */
    public function test_conta_vencida_nao_faz_login(): void
    {
        $conta = $this->criar();
        $conta->update(['expira_em' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bruna@balcao.test',
            'password' => 'senha-do-balcao',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertFalse($conta->user->fresh()->is_active);
    }

    public function test_renovar_devolve_o_acesso_sem_recadastrar(): void
    {
        $conta = $this->criar();
        $conta->update(['expira_em' => now()->subDay()]);
        app(ContaTemporariaService::class)->expirarVencidas();
        $this->assertFalse($conta->user->fresh()->is_active);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/renovar", ['horas' => 5])
            ->assertOk()
            ->assertJsonPath('data.contas.0.ativa', true)
            ->assertJsonPath('data.contas.0.vencida', false)
            ->assertJsonPath('meta.message', 'Acesso renovado.');

        // Nada do cadastro mudou — só o prazo.
        $conta->refresh();
        $this->assertSame('52998224725', $conta->cpf);
        $this->assertSame('Ciência da Computação', $conta->curso);
        $this->assertTrue($conta->expira_em->isFuture());
    }

    /** Renovada, a conta volta a autenticar. */
    public function test_conta_renovada_faz_login_de_novo(): void
    {
        $conta = $this->criar();
        $conta->update(['expira_em' => now()->subDay()]);
        app(ContaTemporariaService::class)->expirarVencidas();

        app(ContaTemporariaService::class)->renovar($conta, ['horas' => 5]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bruna@balcao.test',
            'password' => 'senha-do-balcao',
        ])->assertOk();
    }

    public function test_prazo_no_passado_e_recusado(): void
    {
        $conta = $this->criar();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/renovar", [
            'expira_em' => now()->subDay()->format('Y-m-d\TH:i'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expira_em');
    }

    public function test_admin_encerra_o_acesso_antes_do_prazo(): void
    {
        $conta = $this->criar();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/desativar")
            ->assertOk()
            ->assertJsonPath('data.contas.0.ativa', false)
            // Encerrar não mexe no prazo: renovar segue sendo o caminho de volta.
            ->assertJsonPath('data.contas.0.vencida', false);

        $this->assertFalse($conta->user->fresh()->is_active);
    }

    /**
     * A conta temporária tem a aba Credenciamento, e as rotas de contas moram
     * nela — sem uma trava explícita ela renovaria o próprio prazo e criaria
     * colegas, o que esvaziaria o "temporário".
     */
    public function test_conta_temporaria_nao_administra_contas_temporarias(): void
    {
        $conta = $this->criar();
        Sanctum::actingAs($conta->user->fresh());

        $this->getJson('/api/v1/admin/credenciamento/contas')->assertForbidden();

        $this->postJson('/api/v1/admin/credenciamento/contas', $this->payload([
            'email' => 'outra@balcao.test',
        ]))->assertForbidden();

        // Nem o próprio prazo ela estica.
        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/renovar", ['horas' => 90])
            ->assertForbidden();
        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/desativar")
            ->assertForbidden();

        // Mas o balcão em si continua aberto para ela.
        $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertOk();
    }

    // --- Sprint 87: janela em horas e agendamento ---

    /** Sem nada informado, a conta vale o padrão de 5 horas a contar de agora. */
    public function test_prazo_padrao_e_de_cinco_horas(): void
    {
        $conta = app(ContaTemporariaService::class)->criar(
            array_diff_key($this->payload(), ['horas' => null]),
        );

        $this->assertNull($conta->valido_de);
        $this->assertEqualsWithDelta(
            ContaTemporariaService::HORAS_PADRAO,
            now()->floatDiffInHours($conta->expira_em),
            0.05,
        );
    }

    /** As horas contam a partir do INÍCIO agendado, não do cadastro. */
    public function test_horas_contam_a_partir_do_inicio_agendado(): void
    {
        $inicio = now()->addDays(2)->startOfHour();

        $conta = $this->criar([
            'valido_de' => $inicio->format('Y-m-d\TH:i'),
            'horas' => 5,
        ]);

        $this->assertTrue($conta->agendada());
        $this->assertEqualsWithDelta(
            5.0,
            $conta->valido_de->floatDiffInHours($conta->expira_em),
            0.05,
        );
    }

    /** Agendada, a conta aparece na lista como tal — e continua ativa. */
    public function test_conta_agendada_aparece_na_lista_sem_ser_desativada(): void
    {
        $conta = $this->criar(['valido_de' => now()->addDay()->format('Y-m-d\TH:i')]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/credenciamento/contas')
            ->assertOk()
            ->assertJsonPath('data.contas.0.agendada', true)
            ->assertJsonPath('data.contas.0.vencida', false)
            // Quem bloqueia é a janela, não o interruptor: desativar a conta
            // agendada a faria parecer encerrada.
            ->assertJsonPath('data.contas.0.ativa', true);

        $this->assertTrue($conta->user->fresh()->is_active);
    }

    /** Antes da hora marcada o login é recusado, com a data na mensagem. */
    public function test_conta_agendada_nao_faz_login_antes_da_hora(): void
    {
        $conta = $this->criar(['valido_de' => now()->addDay()->format('Y-m-d\TH:i')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bruna@balcao.test',
            'password' => 'senha-do-balcao',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Agendar não desativa: a conta ainda vai valer.
        $this->assertTrue($conta->user->fresh()->is_active);
    }

    /** Chegada a hora, ela entra sozinha — ninguém precisa liberar nada. */
    public function test_conta_agendada_faz_login_depois_da_hora(): void
    {
        $conta = $this->criar(['valido_de' => now()->addDay()->format('Y-m-d\TH:i')]);

        $this->travel(25)->hours();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bruna@balcao.test',
            'password' => 'senha-do-balcao',
        ])->assertOk();

        $this->assertTrue($conta->fresh()->emVigor());
    }

    /** Renovar também reagenda: janela nova, começo novo. */
    public function test_renovar_reagenda_o_inicio(): void
    {
        $conta = $this->criar();
        Sanctum::actingAs(User::factory()->admin()->create());

        $inicio = now()->addDays(3)->startOfHour();

        $this->patchJson("/api/v1/admin/credenciamento/contas/{$conta->id}/renovar", [
            'valido_de' => $inicio->format('Y-m-d\TH:i'),
            'horas' => 8,
        ])
            ->assertOk()
            ->assertJsonPath('data.contas.0.agendada', true);

        $conta->refresh();
        $this->assertSame($inicio->format('Y-m-d H:i'), $conta->valido_de->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta(8.0, $conta->valido_de->floatDiffInHours($conta->expira_em), 0.05);
    }

    /** Início no passado é o mesmo que "vale desde já" — não fica agendada. */
    public function test_inicio_no_passado_vira_acesso_imediato(): void
    {
        $conta = $this->criar(['valido_de' => now()->subDay()->format('Y-m-d\TH:i')]);

        $this->assertNull($conta->valido_de);
        $this->assertTrue($conta->emVigor());
    }

    /** Fim antes do início não passa: seria uma janela vazia. */
    public function test_fim_antes_do_inicio_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/credenciamento/contas', $this->payload([
            'valido_de' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'expira_em' => now()->addDay()->format('Y-m-d\TH:i'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expira_em');
    }

    // ------------------------------------------------------------------ //
    // Sprint 105 — o setor: credenciamento e almoxarifado                 //
    // ------------------------------------------------------------------ //

    public function test_cada_aba_lista_e_administra_so_as_contas_dela(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/credenciamento/contas', $this->payload([
            'name' => 'Bruna do Credenciamento', 'email' => 'bruna@fetec.test',
        ]))->assertCreated();

        $this->postJson('/api/v1/admin/almoxarifado/contas', $this->payload([
            'name' => 'Caio do Almoxarifado', 'email' => 'caio@fetec.test',
        ]))->assertCreated();

        $credenciamento = $this->getJson('/api/v1/admin/credenciamento/contas')->assertOk()->json('data.contas');
        $almoxarifado = $this->getJson('/api/v1/admin/almoxarifado/contas')->assertOk()->json('data.contas');

        $this->assertSame(['Bruna do Credenciamento'], array_column($credenciamento, 'nome'));
        $this->assertSame(['Caio do Almoxarifado'], array_column($almoxarifado, 'nome'));

        // Renovar a conta da outra aba nem encontra a linha.
        $doAlmoxarifado = ContaTemporaria::where('setor', 'almoxarifado')->firstOrFail();
        $this->patchJson("/api/v1/admin/credenciamento/contas/{$doAlmoxarifado->id}/renovar", ['horas' => 3])
            ->assertNotFound();
    }

    public function test_a_conta_do_almoxarifado_abre_so_a_aba_do_almoxarifado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/almoxarifado/contas', $this->payload([
            'name' => 'Caio do Almoxarifado', 'email' => 'caio@fetec.test',
        ]))->assertCreated();

        $conta = ContaTemporaria::where('setor', 'almoxarifado')->firstOrFail();
        Sanctum::actingAs($conta->user);

        $this->assertSame(['almoxarifado'], $conta->user->abasPermitidas());
        $this->getJson('/api/v1/admin/credenciamento/finalistas')->assertForbidden();
        $this->getJson('/api/v1/admin/almoxarifado/registros')->assertOk();
        // E continua sem administrar contas — inclusive as do próprio setor.
        $this->getJson('/api/v1/admin/almoxarifado/contas')->assertForbidden();
    }

    public function test_as_contas_existentes_continuam_no_credenciamento(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/credenciamento/contas', $this->payload())->assertCreated();

        $conta = ContaTemporaria::firstOrFail();

        $this->assertSame('credenciamento', $conta->setor);
        $this->assertSame(['credenciamento'], $conta->user->abasPermitidas());
    }
}
