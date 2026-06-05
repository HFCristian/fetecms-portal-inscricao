<?php

namespace Tests\Feature;

use App\Enums\ProjetoStatus;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjetoTest extends TestCase
{
    use RefreshDatabase;

    private function orientador(): User
    {
        return User::factory()->create();
    }

    public function test_orientador_cria_projeto_em_rascunho(): void
    {
        $user = $this->orientador();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/projetos', ['titulo' => 'Meu Projeto'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'rascunho')
            ->assertJsonPath('data.titulo', 'Meu Projeto');

        $this->assertDatabaseHas('projetos', [
            'titulo' => 'Meu Projeto',
            'user_id' => $user->id,
            'status' => ProjetoStatus::Rascunho->value,
        ]);
    }

    public function test_listagem_traz_apenas_os_projetos_do_proprio_orientador(): void
    {
        $user = $this->orientador();
        Projeto::factory()->count(2)->create(['user_id' => $user->id]);
        Projeto::factory()->create(); // de outro orientador

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projetos')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_orientador_atualiza_o_proprio_projeto(): void
    {
        $user = $this->orientador();
        $projeto = Projeto::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/projetos/{$projeto->id}", ['titulo' => 'Atualizado'])
            ->assertOk()
            ->assertJsonPath('data.titulo', 'Atualizado');
    }

    public function test_nao_ve_projeto_de_outro_orientador(): void
    {
        $projetoAlheio = Projeto::factory()->create();
        Sanctum::actingAs($this->orientador());

        $this->getJson("/api/v1/projetos/{$projetoAlheio->id}")->assertForbidden();
    }

    public function test_nao_edita_projeto_de_outro_orientador(): void
    {
        $projetoAlheio = Projeto::factory()->create();
        Sanctum::actingAs($this->orientador());

        $this->putJson("/api/v1/projetos/{$projetoAlheio->id}", ['titulo' => 'Hack'])
            ->assertForbidden();
    }

    public function test_nao_exclui_projeto_de_outro_orientador(): void
    {
        $projetoAlheio = Projeto::factory()->create();
        Sanctum::actingAs($this->orientador());

        $this->deleteJson("/api/v1/projetos/{$projetoAlheio->id}")->assertForbidden();
    }

    public function test_exclui_o_proprio_rascunho(): void
    {
        $user = $this->orientador();
        $projeto = Projeto::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/projetos/{$projeto->id}")->assertOk();
        $this->assertSoftDeleted('projetos', ['id' => $projeto->id]);
    }

    public function test_projeto_submetido_nao_pode_ser_editado(): void
    {
        $user = $this->orientador();
        $projeto = Projeto::factory()->submetido()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/projetos/{$projeto->id}", ['titulo' => 'X'])->assertForbidden();
    }

    public function test_salva_campos_de_feira_etica_e_declaracao(): void
    {
        $user = $this->orientador();
        $projeto = Projeto::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/projetos/{$projeto->id}", [
            'feira_afiliada' => true,
            'feira_afiliada_nome' => 'Feira Municipal de Ciências',
            'necessita_termo_etica' => true,
            'declaracao_email' => true,
        ])->assertOk()
            ->assertJsonPath('data.feira_afiliada_nome', 'Feira Municipal de Ciências')
            ->assertJsonPath('data.necessita_termo_etica', true)
            ->assertJsonPath('data.declaracao_email', true);
    }

    public function test_avaliador_nao_cria_projeto(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->postJson('/api/v1/projetos', ['titulo' => 'X'])->assertForbidden();
    }

    public function test_validacao_rejeita_categoria_invalida_e_excesso_de_palavras_chave(): void
    {
        Sanctum::actingAs($this->orientador());

        $this->postJson('/api/v1/projetos', ['categoria' => 'invalida'])
            ->assertStatus(422)->assertJsonValidationErrors('categoria');

        $this->postJson('/api/v1/projetos', [
            'palavras_chave' => ['a', 'b', 'c', 'd', 'e', 'f'],
        ])->assertStatus(422)->assertJsonValidationErrors('palavras_chave');
    }
}
