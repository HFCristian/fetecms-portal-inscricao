<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Aba "Presencial" do avaliador: a intenção de avaliar no dia da feira e as
 * orientações que só quem aceita enxerga.
 */
class AvaliacaoPresencialIntencaoTest extends TestCase
{
    use RefreshDatabase;

    private User $avaliador;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->addDays(10), 'evento_ate' => now()->addDays(12),
            'info_avaliacao_presencial' => 'Chegue às 7h30 no ginásio, com o crachá.',
        ]);

        $this->avaliador = User::factory()->create(['role' => Role::Avaliador->value]);
        AvaliadorProfile::factory()->create(['user_id' => $this->avaliador->id]);
    }

    private function comoAvaliador(): void
    {
        Sanctum::actingAs($this->avaliador);
    }

    public function test_comeca_sem_resposta_e_sem_orientacoes(): void
    {
        $this->comoAvaliador();

        // Nulo é "ainda não respondeu" — diferente de "não vai".
        $this->getJson('/api/v1/avaliador/presencial')
            ->assertOk()
            ->assertJsonPath('data.respondido', false)
            ->assertJsonPath('data.presencial', null)
            ->assertJsonPath('data.pode_alterar', true)
            ->assertJsonPath('data.informacoes', null);
    }

    public function test_quem_aceita_passa_a_ver_as_orientacoes(): void
    {
        $this->comoAvaliador();

        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => true])
            ->assertOk()
            ->assertJsonPath('data.presencial', true)
            ->assertJsonPath('data.informacoes', 'Chegue às 7h30 no ginásio, com o crachá.');

        $this->assertDatabaseHas('avaliador_profiles', [
            'user_id' => $this->avaliador->id,
            'presencial' => true,
        ]);
    }

    public function test_quem_recusa_nao_ve_as_orientacoes_mas_pode_voltar_atras(): void
    {
        $this->comoAvaliador();

        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => false])
            ->assertOk()
            ->assertJsonPath('data.respondido', true)
            ->assertJsonPath('data.presencial', false)
            ->assertJsonPath('data.informacoes', null)
            ->assertJsonPath('data.pode_alterar', true);

        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => true])
            ->assertOk()
            ->assertJsonPath('data.presencial', true)
            ->assertJsonPath('data.informacoes', 'Chegue às 7h30 no ginásio, com o crachá.');
    }

    public function test_com_o_evento_em_curso_quem_quer_ir_ainda_marca_sim(): void
    {
        // Sprint 225: sem trava de data — o avaliador online decide no dia.
        $this->comoAvaliador();
        $this->edicao->update(['evento_de' => now()->subHour(), 'evento_ate' => now()->addDay()]);

        $this->getJson('/api/v1/avaliador/presencial')
            ->assertOk()
            ->assertJsonPath('data.evento_iniciado', true)
            ->assertJsonPath('data.pode_alterar', true);

        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => true])
            ->assertOk()
            ->assertJsonPath('data.presencial', true)
            ->assertJsonPath('data.informacoes', 'Chegue às 7h30 no ginásio, com o crachá.');

        // E pode desistir também.
        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => false])
            ->assertOk()
            ->assertJsonPath('data.presencial', false);
    }

    public function test_quem_desiste_devolve_o_que_estava_so_designado(): void
    {
        $this->comoAvaliador();
        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => true])->assertOk();
        foreach ([StatusAvaliacao::Designada, StatusAvaliacao::EmAndamento] as $status) {
            AvaliacaoPresencial::create([
                'edicao_id' => $this->edicao->id, 'avaliador_id' => $this->avaliador->id, 'status' => $status,
                'projeto_id' => Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id])->id,
            ]);
        }

        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => false])->assertOk();

        // A que ele já abriu fica, para retomar se voltar atrás.
        $this->assertSame([StatusAvaliacao::EmAndamento], AvaliacaoPresencial::pluck('status')->all());
    }

    public function test_conta_so_do_presencial_nao_muda_a_resposta(): void
    {
        $this->comoAvaliador();
        $this->avaliador->avaliadorProfile->update(['apenas_presencial' => true, 'presencial' => true]);

        $this->getJson('/api/v1/avaliador/presencial')->assertJsonPath('data.pode_alterar', false);
        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => false])
            ->assertStatus(422)->assertJsonValidationErrors('presencial');
    }

    public function test_sem_orientacoes_publicadas_a_resposta_continua_valendo(): void
    {
        $this->edicao->update(['info_avaliacao_presencial' => null]);
        $this->comoAvaliador();

        $this->putJson('/api/v1/avaliador/presencial', ['presencial' => true])
            ->assertOk()
            ->assertJsonPath('data.presencial', true)
            ->assertJsonPath('data.informacoes', null);
    }

    public function test_orientador_nao_acessa(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Orientador->value]));

        $this->getJson('/api/v1/avaliador/presencial')->assertForbidden();
    }
}
