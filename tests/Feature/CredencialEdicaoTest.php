<?php

namespace Tests\Feature;

use App\Enums\TipoCredencial;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Credenciais e Prêmios: editar o item inteiro (nome, tipo, órgão, descrição,
 * informações e vagas) e o nome repetido, que derrubava o cadastro com
 * "Erro interno do servidor".
 */
class CredencialEdicaoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function credencial(array $over = []): Credencial
    {
        return Credencial::create(array_merge([
            'edicao_id' => $this->edicao->id,
            'nome' => 'MOSTRATEC 2027',
            'orgao' => 'FUNDECT',
            'vagas' => 3,
        ], $over));
    }

    private function premiar(Credencial $credencial, int $quantos): void
    {
        foreach (Projeto::factory()->submetido()->count($quantos)->create() as $projeto) {
            $credencial->projetos()->attach($projeto->id, ['atribuida_em' => now()]);
        }
    }

    private function url(Credencial $credencial): string
    {
        return "/api/v1/admin/presencial/credenciais/{$credencial->id}";
    }

    public function test_edita_nome_tipo_orgao_descricao_informacoes_e_vagas(): void
    {
        $credencial = $this->credencial();

        $this->patchJson($this->url($credencial), [
            'tipo' => 'premio',
            'nome' => '  Destaque da feira  ',
            'orgao' => 'UFMS',
            'descricao' => 'Para o projeto mais inovador.',
            'informacoes' => 'Escolhido pela comissão.',
            'vagas' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Destaque da feira')
            ->assertJsonPath('data.0.tipo', 'premio')
            ->assertJsonPath('data.0.orgao', 'UFMS')
            ->assertJsonPath('data.0.descricao', 'Para o projeto mais inovador.')
            ->assertJsonPath('data.0.informacoes', 'Escolhido pela comissão.')
            ->assertJsonPath('data.0.vagas', 5);

        $this->assertSame(TipoCredencial::Premio, $credencial->refresh()->tipo);
    }

    public function test_vagas_sobem_e_descem_ate_o_numero_de_premiados(): void
    {
        $credencial = $this->credencial(['vagas' => 3]);
        $this->premiar($credencial, 2);

        $this->patchJson($this->url($credencial), ['vagas' => 8])
            ->assertOk()->assertJsonPath('data.0.vagas', 8)->assertJsonPath('data.0.disponiveis', 6);

        // Igual ao número de premiados é o piso: a credencial fica esgotada.
        $this->patchJson($this->url($credencial), ['vagas' => 2])
            ->assertOk()->assertJsonPath('data.0.disponiveis', 0);

        $this->patchJson($this->url($credencial), ['vagas' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.vagas.0', 'Este item já foi dado a 2 projeto(s), então as vagas não podem ser menos que 2. Retire um projeto antes de reduzir.');

        $this->assertSame(2, $credencial->refresh()->vagas);

        // Em branco é sem teto: cabe qualquer número de premiados.
        $this->patchJson($this->url($credencial), ['vagas' => null])
            ->assertOk()->assertJsonPath('data.0.vagas', null);
    }

    public function test_trocar_o_tipo_de_item_ja_premiado_e_permitido(): void
    {
        $credencial = $this->credencial();
        $this->premiar($credencial, 1);

        $this->patchJson($this->url($credencial), ['tipo' => 'premio'])
            ->assertOk()->assertJsonPath('data.0.tipo', 'premio')->assertJsonPath('data.0.usadas', 1);
    }

    public function test_nome_em_branco_na_edicao_e_recusado(): void
    {
        $credencial = $this->credencial();

        $this->patchJson($this->url($credencial), ['nome' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('nome');

        $this->assertSame('MOSTRATEC 2027', $credencial->refresh()->nome);
    }

    public function test_cadastrar_com_nome_que_ja_existe_diz_qual_item_usa_o_nome(): void
    {
        $this->credencial(['ativa' => false]);

        // Caixa e espaços diferentes não fazem outro nome.
        $this->postJson('/api/v1/admin/presencial/credenciais', ['tipo' => 'premio', 'nome' => ' mostratec 2027 '])
            ->assertStatus(422)
            ->assertJsonPath('errors.nome.0', 'Já existe uma credencial chamada “MOSTRATEC 2027” (desativada) nesta edição. Escolha outro nome ou edite o item existente.');

        $this->assertSame(1, Credencial::count());
    }

    public function test_o_aviso_de_nome_repetido_diz_quando_e_premio(): void
    {
        $this->credencial(['tipo' => TipoCredencial::Premio, 'nome' => 'Menção honrosa']);

        $this->postJson('/api/v1/admin/presencial/credenciais', ['nome' => 'Mencao Honrosa'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nome.0', 'Já existe um prêmio chamado “Menção honrosa” nesta edição. Escolha outro nome ou edite o item existente.');
    }

    public function test_renomear_para_um_nome_em_uso_e_recusado(): void
    {
        $this->credencial(['nome' => 'FEBRACE 2027']);
        $outra = $this->credencial();

        $this->patchJson($this->url($outra), ['nome' => 'Febrace 2027'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nome.0', 'Já existe uma credencial chamada “FEBRACE 2027” nesta edição. Escolha outro nome ou edite o item existente.');

        $this->assertSame('MOSTRATEC 2027', $outra->refresh()->nome);
    }

    public function test_editar_sem_trocar_o_nome_nao_esbarra_em_item_parecido(): void
    {
        // Dois itens antigos que diferem só na caixa: o banco aceitou os dois,
        // e salvar um deles com o mesmo nome não pode virar recusa.
        $this->credencial(['nome' => 'Mostratec 2027']);
        $credencial = $this->credencial();

        $this->patchJson($this->url($credencial), ['nome' => 'MOSTRATEC 2027', 'orgao' => 'CNPq'])
            ->assertOk();

        $this->assertSame('CNPq', $credencial->refresh()->orgao);
    }

    public function test_o_mesmo_nome_em_outra_edicao_e_permitido(): void
    {
        $outra = Edicao::create(['nome' => 'XV FETECMS', 'ano' => 2025]);
        Credencial::create(['edicao_id' => $outra->id, 'nome' => 'MOSTRATEC 2027']);

        $this->postJson('/api/v1/admin/presencial/credenciais', ['nome' => 'MOSTRATEC 2027'])
            ->assertCreated();
    }

    public function test_cadastro_simultaneo_com_o_mesmo_nome_vira_aviso_e_nao_erro_interno(): void
    {
        // Outro admin grava o mesmo nome entre a conferência e a gravação:
        // quem perde a corrida recebe a mesma recusa, não um 500.
        Credencial::creating(function (Credencial $c) {
            if (DB::table('credenciais')->where('nome', $c->nome)->doesntExist()) {
                DB::table('credenciais')->insert([
                    'edicao_id' => $c->edicao_id, 'tipo' => 'credencial', 'nome' => $c->nome,
                    'ativa' => true, 'ordem' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->postJson('/api/v1/admin/presencial/credenciais', ['nome' => 'FEBRACE 2027'])
            ->assertStatus(422)
            ->assertJsonPath('errors.nome.0', 'Já existe uma credencial chamada “FEBRACE 2027” nesta edição. Escolha outro nome ou edite o item existente.');
    }
}
