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
 * Sprint 92 — ordem das abas do menu do admin (Parametrização → Ordem do menu).
 *
 * A ordem é da **edição**, vale para todo mundo, e é de apresentação: mudar a
 * ordem nunca muda quem abre o quê.
 */
class OrdemAbasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    public function test_sem_ordem_salva_vale_a_ordem_do_portal(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $resposta = $this->getJson('/api/v1/admin/abas')->assertOk();

        $this->assertFalse($resposta->json('data.personalizada'));
        $this->assertSame(AbaAdmin::valores(), array_column($resposta->json('data.abas'), 'value'));
    }

    public function test_admin_reordena_o_menu(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $nova = [AbaAdmin::Credenciamento->value, AbaAdmin::Projetos->value];

        $resposta = $this->putJson('/api/v1/admin/abas', ['ordem' => $nova])
            ->assertOk()
            ->assertJsonPath('meta.message', 'Ordem do menu salva.')
            ->assertJsonPath('data.personalizada', true);

        $ordem = array_column($resposta->json('data.abas'), 'value');

        // O que a tela pediu vem na frente...
        $this->assertSame($nova, array_slice($ordem, 0, 2));
        // ...e o resto atrás, na ordem do enum, sem perder nenhuma aba.
        $this->assertCount(count(AbaAdmin::valores()), $ordem);
        $this->assertSame(
            array_values(array_diff(AbaAdmin::valores(), $nova)),
            array_slice($ordem, 2),
        );
    }

    /** O menu do usuário sai na ordem escolhida. */
    public function test_o_payload_do_usuario_respeita_a_ordem(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/abas', [
            'ordem' => [AbaAdmin::Registros->value, AbaAdmin::Credenciamento->value],
        ])->assertOk();

        $abas = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.abas');

        $this->assertSame(
            [AbaAdmin::Registros->value, AbaAdmin::Credenciamento->value],
            array_slice($abas, 0, 2),
        );
    }

    /**
     * A ordem é de apresentação: ela reordena o que a pessoa já enxerga e não
     * acrescenta nem tira nada.
     */
    public function test_ordem_nao_muda_o_acesso(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create([
            'nome' => 'Só credenciamento',
            'abas' => [AbaAdmin::Credenciamento->value],
        ]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::padrao()->id]);

        Edicao::padrao()->update(['ordem_abas' => AbaAdmin::valores()]);

        $this->assertSame([AbaAdmin::Credenciamento->value], $admin->fresh()->abasPermitidas());

        Sanctum::actingAs($admin->fresh());
        $this->assertSame(
            [AbaAdmin::Credenciamento->value],
            $this->getJson('/api/v1/auth/me')->assertOk()->json('data.abas'),
        );
        // E as demais continuam barradas de verdade.
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    /** Aba nova no código entra no fim, em vez de sumir do menu de quem já salvou. */
    public function test_aba_fora_da_ordem_salva_entra_no_fim(): void
    {
        $parcial = [AbaAdmin::Registros->value, AbaAdmin::Projetos->value];

        $ordenada = AbaAdmin::ordenar(AbaAdmin::valores(), $parcial);

        $this->assertSame($parcial, array_slice($ordenada, 0, 2));
        $this->assertCount(count(AbaAdmin::valores()), $ordenada);
    }

    public function test_valor_desconhecido_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/admin/abas', ['ordem' => ['aba_que_nao_existe']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ordem.0');
    }

    public function test_restaurar_volta_a_ordem_do_portal(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/admin/abas', ['ordem' => [AbaAdmin::Registros->value]])->assertOk();

        $resposta = $this->deleteJson('/api/v1/admin/abas')
            ->assertOk()
            ->assertJsonPath('data.personalizada', false);

        $this->assertSame(AbaAdmin::valores(), array_column($resposta->json('data.abas'), 'value'));
        $this->assertNull(Edicao::padrao()->fresh()->ordem_abas);
    }

    /** A ordem é da edição: trocar de edição troca o menu junto. */
    public function test_ordem_e_por_edicao(): void
    {
        $outra = Edicao::create(['nome' => 'XVII FETECMS', 'ano' => 2027]);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/abas', ['ordem' => [AbaAdmin::Registros->value]])->assertOk();

        // Mesma pessoa, outra edição em escopo: a ordem daquela é a de fábrica.
        // (`edicao_id` não é fillable — a troca passa pelo EdicaoService.)
        $admin->edicao_id = $outra->id;
        $admin->save();
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/abas')
            ->assertOk()
            ->assertJsonPath('data.personalizada', false)
            ->assertJsonPath('data.abas.0.value', AbaAdmin::valores()[0]);
    }

    public function test_so_quem_abre_parametrizacao_reordena(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create([
            'nome' => 'Só credenciamento',
            'abas' => [AbaAdmin::Credenciamento->value],
        ]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::padrao()->id]);

        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/abas')->assertForbidden();
        $this->putJson('/api/v1/admin/abas', ['ordem' => []])->assertForbidden();
    }
}
