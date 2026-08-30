<?php

namespace Tests\Feature;

use App\Enums\AbaAdmin;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 67 — escopos de admin: perfis nomeados com as abas que abrem,
 * atribuídos a cada administrador POR EDIÇÃO e aplicados no backend.
 */
class EscopoAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function escopo(string $nome, array $abas): EscopoAdmin
    {
        return EscopoAdmin::create(['nome' => $nome, 'abas' => $abas]);
    }

    /** Um admin com escopo restrito + outro que segura a aba "Administradores". */
    private function comEscopo(array $abas): User
    {
        User::factory()->admin()->create(); // o guardião do acesso total
        $admin = User::factory()->admin()->create();
        $escopo = $this->escopo('Restrito', $abas);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::padrao()->id]);

        return $admin;
    }

    public function test_admin_sem_escopo_tem_acesso_total(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->assertSame(AbaAdmin::valores(), $admin->abasPermitidas());

        $this->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->getJson('/api/v1/admin/registros?secao=inscricoes')->assertOk();
        $this->getJson('/api/v1/admin/mala-direta')->assertOk();
    }

    public function test_escopo_restrito_barra_as_abas_de_fora(): void
    {
        $admin = $this->comEscopo([AbaAdmin::Projetos->value, AbaAdmin::Registros->value]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->getJson('/api/v1/admin/registros?secao=inscricoes')->assertOk();

        // Fora do escopo: 403 com a explicação, não um 404 mudo.
        $this->getJson('/api/v1/admin/mala-direta')
            ->assertForbidden()
            ->assertJsonPath('message', 'Seu escopo de administrador não inclui esta área do portal.');
        $this->getJson('/api/v1/admin/conversas')->assertForbidden();
        $this->getJson('/api/v1/admin/avaliacao/ranking')->assertForbidden();
    }

    public function test_o_payload_do_usuario_traz_as_abas_do_menu(): void
    {
        $admin = $this->comEscopo([AbaAdmin::Comunicacao->value]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.abas', [AbaAdmin::Comunicacao->value]);
    }

    public function test_o_escopo_vale_por_edicao(): void
    {
        User::factory()->admin()->create(); // guardião
        $admin = User::factory()->admin()->create();
        $outra = Edicao::create(['nome' => 'XVII FETECMS', 'ano' => 2027, 'inscricoes_abertas' => true]);

        // Só comunicação na edição padrão; nada atribuído na outra (= acesso total).
        $admin->escopos()->attach(
            $this->escopo('Comunicação', [AbaAdmin::Comunicacao->value])->id,
            ['edicao_id' => Edicao::padrao()->id],
        );

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();

        $this->putJson('/api/v1/edicoes/atual', ['edicao_id' => $outra->id])->assertOk();
        $this->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_admin_cria_e_edita_escopos(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/escopos', [
            'nome' => 'Comunicação',
            'abas' => [AbaAdmin::Comunicacao->value, AbaAdmin::Suporte->value],
        ])->assertCreated();

        $escopo = EscopoAdmin::where('nome', 'Comunicação')->first();
        $this->assertSame([AbaAdmin::Comunicacao->value, AbaAdmin::Suporte->value], $escopo->abas);

        $this->putJson("/api/v1/admin/escopos/{$escopo->id}", [
            'nome' => 'Comunicação e suporte',
            'abas' => [AbaAdmin::Comunicacao->value],
        ])->assertOk();

        $this->assertSame('Comunicação e suporte', $escopo->fresh()->nome);
        $this->assertSame([AbaAdmin::Comunicacao->value], $escopo->fresh()->abas);
    }

    public function test_escopo_sem_nenhuma_aba_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/escopos', ['nome' => 'Vazio', 'abas' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abas');
    }

    public function test_admin_recebe_escopo_na_edicao_em_curso(): void
    {
        $autor = User::factory()->admin()->create();
        $alvo = User::factory()->admin()->create();
        $escopo = $this->escopo('Projetos', [AbaAdmin::Projetos->value]);

        Sanctum::actingAs($autor);

        $this->putJson("/api/v1/admin/admins/{$alvo->id}/escopos", ['escopo_ids' => [$escopo->id]])
            ->assertOk()
            ->assertJsonPath("data.{$alvo->id}.escopos", ['Projetos']);

        $this->assertDatabaseHas('admin_escopos', [
            'user_id' => $alvo->id,
            'escopo_admin_id' => $escopo->id,
            'edicao_id' => Edicao::padrao()->id,
        ]);

        // Sem escopo, volta ao acesso total.
        $this->putJson("/api/v1/admin/admins/{$alvo->id}/escopos", ['escopo_ids' => []])->assertOk();
        $this->assertDatabaseMissing('admin_escopos', ['user_id' => $alvo->id]);
    }

    /**
     * O coração do RBAC: vários escopos na mesma edição somam as abas, em vez
     * de o último substituir o anterior.
     */
    public function test_admin_acumula_varios_escopos_e_as_abas_se_somam(): void
    {
        $autor = User::factory()->admin()->create();
        $alvo = User::factory()->admin()->create();
        $comunicacao = $this->escopo('Comunicação', [AbaAdmin::Comunicacao->value]);
        $credenciamento = $this->escopo('Credenciamento', [AbaAdmin::Credenciamento->value]);

        Sanctum::actingAs($autor);

        $this->putJson("/api/v1/admin/admins/{$alvo->id}/escopos", [
            'escopo_ids' => [$comunicacao->id, $credenciamento->id],
        ])
            ->assertOk()
            ->assertJsonPath("data.{$alvo->id}.escopos", ['Comunicação', 'Credenciamento'])
            ->assertJsonPath("data.{$alvo->id}.abas", [
                AbaAdmin::Credenciamento->value, AbaAdmin::Comunicacao->value,
            ]);

        $this->assertSame(2, $alvo->escopos()->count());

        // A união vale de verdade: as duas abas abrem, e as demais não.
        $alvo->refresh();
        $this->assertTrue($alvo->podeAbrirAba(AbaAdmin::Comunicacao));
        $this->assertTrue($alvo->podeAbrirAba(AbaAdmin::Credenciamento));
        $this->assertFalse($alvo->podeAbrirAba(AbaAdmin::Registros));
        $this->assertSame(
            [AbaAdmin::Credenciamento->value, AbaAdmin::Comunicacao->value],
            $alvo->abasPermitidas(),
        );
    }

    /** O middleware `aba:` respeita a união, não só o primeiro escopo. */
    public function test_middleware_libera_aba_vinda_do_segundo_escopo(): void
    {
        $admin = User::factory()->admin()->create();
        $edicao = Edicao::padrao();
        $admin->escopos()->attach(
            $this->escopo('Só projetos', [AbaAdmin::Projetos->value])->id,
            ['edicao_id' => $edicao->id],
        );

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/registros')->assertForbidden();

        $admin->escopos()->attach(
            $this->escopo('Só registros', [AbaAdmin::Registros->value])->id,
            ['edicao_id' => $edicao->id],
        );

        $this->getJson('/api/v1/admin/registros')->assertOk();
        $this->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_nao_deixa_a_edicao_sem_ninguem_na_aba_administradores(): void
    {
        $unico = User::factory()->admin()->create();
        $semAdmins = $this->escopo('Sem administradores', [AbaAdmin::Projetos->value]);

        Sanctum::actingAs($unico);

        $this->putJson("/api/v1/admin/admins/{$unico->id}/escopos", ['escopo_ids' => [$semAdmins->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('escopo_ids');

        // A atribuição foi desfeita: ele continua com acesso total.
        $this->assertDatabaseMissing('admin_escopos', ['user_id' => $unico->id]);
        $this->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_nao_deixa_tirar_a_aba_administradores_do_ultimo_escopo_que_a_tem(): void
    {
        $admin = User::factory()->admin()->create();
        $completo = $this->escopo('Completo', AbaAdmin::valores());
        $admin->escopos()->attach($completo->id, ['edicao_id' => Edicao::padrao()->id]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/escopos/{$completo->id}", [
            'abas' => [AbaAdmin::Projetos->value],
        ])->assertStatus(422)->assertJsonValidationErrors('escopo_ids');

        // Nada mudou: a transação foi desfeita.
        $this->assertSame(AbaAdmin::valores(), $completo->fresh()->abas);
    }

    public function test_escopo_em_uso_nao_e_excluido(): void
    {
        $admin = $this->comEscopo([AbaAdmin::Projetos->value]);
        $escopo = EscopoAdmin::where('nome', 'Restrito')->first();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/v1/admin/escopos/{$escopo->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('escopo');

        $this->putJson("/api/v1/admin/admins/{$admin->id}/escopos", ['escopo_ids' => []])->assertOk();
        $this->deleteJson("/api/v1/admin/escopos/{$escopo->id}")->assertOk();
        $this->assertDatabaseMissing('escopos_admin', ['id' => $escopo->id]);
    }

    public function test_escopo_nao_se_aplica_a_quem_nao_e_admin(): void
    {
        $orientador = User::factory()->create();

        $this->assertSame([], $orientador->abasPermitidas());
        $this->assertFalse($orientador->podeAbrirAba(AbaAdmin::Projetos));
    }

    public function test_quem_nao_e_admin_nao_gerencia_escopos(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/escopos')->assertForbidden();
        $this->postJson('/api/v1/admin/escopos', ['nome' => 'X', 'abas' => ['projetos']])->assertForbidden();
    }
}
