<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorLiberacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 223 — Avaliação presencial → Validação de avaliadores: quem confirmou
 * o presencial só avalia depois de liberado pela organização, uma vez para o
 * evento inteiro da edição.
 */
class ValidacaoPresencialTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/presencial/validacao';

    private Edicao $edicao;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->admin = User::factory()->admin()->create(['name' => 'Organização']);
        Sanctum::actingAs($this->admin);
    }

    private function avaliador(string $nome, bool $presencial = true, bool $demo = false, bool $apenasPresencial = false): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome, 'is_demo' => $demo]);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'presencial' => $presencial, 'apenas_presencial' => $apenasPresencial,
            'area_id' => Area::firstOrCreate(['nome' => 'Ciências Agrárias'], ['sigla' => 'AGR'])->id,
        ]);

        return $user;
    }

    public function test_lista_quem_confirmou_o_presencial_com_a_situacao(): void
    {
        $ana = $this->avaliador('Ana', apenasPresencial: true);
        $this->avaliador('Bruno');
        $this->avaliador('Carla', presencial: false);
        $this->avaliador('Demo', demo: true);
        AvaliadorLiberacaoPresencial::create(['edicao_id' => $this->edicao->id, 'user_id' => $ana->id, 'liberado_por' => $this->admin->id]);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.resumo', ['total' => 2, 'liberados' => 1, 'aguardando' => 1])
            ->assertJsonCount(2, 'data.avaliadores')
            ->assertJsonPath('data.avaliadores.0.nome', 'Ana')
            ->assertJsonPath('data.avaliadores.0.liberado', true)
            ->assertJsonPath('data.avaliadores.0.liberado_por', 'Organização')
            ->assertJsonPath('data.avaliadores.0.apenas_presencial', true)
            ->assertJsonPath('data.avaliadores.1.liberado', false);

        $this->getJson(self::URL.'?situacao=aguardando')
            ->assertJsonCount(1, 'data.avaliadores')
            ->assertJsonPath('data.avaliadores.0.nome', 'Bruno')
            // O resumo é da base inteira, não do recorte.
            ->assertJsonPath('data.resumo.total', 2);

        $this->getJson(self::URL.'?busca=ana')->assertJsonCount(1, 'data.avaliadores');
    }

    public function test_libera_em_lote_e_ignora_quem_nao_confirmou(): void
    {
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $carla = $this->avaliador('Carla', presencial: false);

        $this->postJson(self::URL.'/liberar', ['avaliador_ids' => [$ana->id, $bruno->id, $carla->id]])
            ->assertOk()
            ->assertJsonPath('meta.resultado.liberados', 2)
            ->assertJsonPath('meta.resultado.ignorados', ['Carla: não confirmou a avaliação presencial.'])
            ->assertJsonPath('data.resumo.liberados', 2);

        // De novo: quem já está liberado fica como estava.
        $this->postJson(self::URL.'/liberar', ['avaliador_ids' => [$ana->id]])
            ->assertOk()->assertJsonPath('meta.resultado.liberados', 0);

        $this->assertSame(2, AvaliadorLiberacaoPresencial::count());
        $this->assertSame($this->admin->id, AvaliadorLiberacaoPresencial::where('user_id', $ana->id)->sole()->liberado_por);
    }

    public function test_revogar_tira_a_liberacao_e_devolve_o_que_nao_foi_aberto(): void
    {
        $ana = $this->avaliador('Ana');
        AvaliadorLiberacaoPresencial::create(['edicao_id' => $this->edicao->id, 'user_id' => $ana->id]);
        foreach ([StatusAvaliacao::Designada, StatusAvaliacao::EmAndamento] as $status) {
            AvaliacaoPresencial::create([
                'edicao_id' => $this->edicao->id, 'avaliador_id' => $ana->id, 'status' => $status,
                'projeto_id' => Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id])->id,
            ]);
        }

        $this->deleteJson(self::URL."/{$ana->id}")
            ->assertOk()
            ->assertJsonPath('meta.resultado.devolvidas', 1)
            ->assertJsonPath('data.avaliadores.0.liberado', false);

        $this->assertSame(0, AvaliadorLiberacaoPresencial::count());
        // A que ele já abriu fica, em leitura.
        $this->assertSame([StatusAvaliacao::EmAndamento], AvaliacaoPresencial::pluck('status')->all());
    }

    public function test_a_liberacao_e_da_edicao(): void
    {
        $ana = $this->avaliador('Ana');
        $anterior = Edicao::create(['nome' => 'XV FETECMS', 'ano' => 2025, 'padrao' => false]);
        AvaliadorLiberacaoPresencial::create(['edicao_id' => $anterior->id, 'user_id' => $ana->id]);

        $this->getJson(self::URL)->assertJsonPath('data.avaliadores.0.liberado', false);
    }

    public function test_o_cadastro_mostra_que_a_conta_nova_ainda_precisa_ser_liberada(): void
    {
        $area = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
        $this->postJson('/api/v1/admin/presencial/avaliadores-cadastrados', [
            'name' => 'Rosa Pantaneira', 'email' => 'rosa@ufms.br', 'cpf' => '529.982.247-25',
            'titulacao' => 'Doutorado (concluído)', 'area_id' => $area->id,
        ])->assertCreated()->assertJsonPath('data.liberado', false);

        $rosa = User::where('email', 'rosa@ufms.br')->sole();
        $this->postJson(self::URL.'/liberar', ['avaliador_ids' => [$rosa->id]])->assertOk();

        $this->getJson('/api/v1/admin/presencial/avaliadores-cadastrados')->assertJsonPath('data.0.liberado', true);
    }

    public function test_modo_de_teste_mostra_e_libera_so_os_avaliadores_demo(): void
    {
        $this->avaliador('De verdade');
        $demo = $this->avaliador('Avaliador demo', demo: true);
        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $this->getJson(self::URL.'?teste=1')
            ->assertJsonPath('data.modo_teste', true)
            ->assertJsonCount(1, 'data.avaliadores')
            ->assertJsonPath('data.avaliadores.0.nome', 'Avaliador demo');

        $real = User::where('name', 'De verdade')->sole();
        $this->postJson(self::URL.'/liberar', ['avaliador_ids' => [$demo->id, $real->id], 'teste' => 1])
            ->assertOk()->assertJsonPath('meta.resultado.liberados', 1);
    }

    public function test_quem_nao_e_admin_nao_alcanca(): void
    {
        Sanctum::actingAs($this->avaliador('Ana'));

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL.'/liberar', ['avaliador_ids' => [1]])->assertForbidden();
    }
}
