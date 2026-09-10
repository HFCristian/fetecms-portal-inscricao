<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\ModoDistribuicao;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 110 — o **modo de distribuição** da edição.
 *
 * *Total* é o comportamento histórico: o admin distribui em massa e o avaliador
 * encontra a fila pronta. *Por atividade* monta a fila no login, para que só
 * ocupe projeto quem está de fato avaliando.
 */
class ModoDistribuicaoTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    private function avaliador(int $areaId, array $over = []): User
    {
        $user = User::factory()->avaliador()->create([
            'password' => Hash::make(self::SENHA),
        ] + $over);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);

        return $user;
    }

    private function projeto(int $areaId, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'categoria' => Categoria::Fetecms, 'titulo' => $titulo,
        ]);
    }

    /** Põe a edição no modo pedido, com a avaliação já liberada. */
    private function modo(ModoDistribuicao $modo): void
    {
        Edicao::atual()->update([
            'modo_distribuicao' => $modo,
            'avaliacao_liberada_em' => now()->subDay(),
            'avaliacao_encerrada_em' => null,
        ]);
    }

    private function entrar(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::SENHA,
        ]);
    }

    public function test_edicao_nasce_no_modo_total(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/distribuicao')
            ->assertOk()
            ->assertJsonPath('data.modo', 'total')
            ->assertJsonPath('data.distribui_em_massa', true)
            ->assertJsonCount(2, 'data.modos');

        $this->assertSame(ModoDistribuicao::Total, Edicao::modoDistribuicao());
    }

    public function test_admin_troca_o_modo_e_a_mudanca_vira_registro(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao/modo', ['modo' => 'atividade'])
            ->assertOk()
            ->assertJsonPath('data.modo', 'atividade')
            ->assertJsonPath('data.distribui_em_massa', false);

        $this->assertSame(ModoDistribuicao::Atividade, Edicao::modoDistribuicao());

        $registro = RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoModoDistribuicao->value)->first();
        $this->assertNotNull($registro);
        $this->assertSame('Distribuição Total', $registro->detalhes['de']);
        $this->assertSame('Distribuição por Atividade', $registro->detalhes['para']);
    }

    public function test_modo_invalido_nao_passa_na_validacao(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao/modo', ['modo' => 'inventado'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modo');
    }

    public function test_salvar_o_mesmo_modo_nao_gera_registro(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao/modo', ['modo' => 'total'])->assertOk();

        $this->assertSame(0, RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoModoDistribuicao->value)->count());
    }

    public function test_no_modo_por_atividade_a_distribuicao_em_massa_e_recusada(): void
    {
        $this->modo(ModoDistribuicao::Atividade);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/avaliacao/distribuir')
            ->assertStatus(422)
            ->assertJsonValidationErrors('distribuicao');

        $this->postJson('/api/v1/admin/avaliacao/redistribuir')
            ->assertStatus(422)
            ->assertJsonValidationErrors('distribuicao');
    }

    public function test_no_modo_total_a_distribuicao_em_massa_continua_valendo(): void
    {
        $this->modo(ModoDistribuicao::Total);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/avaliacao/distribuir')->assertStatus(202);
    }

    public function test_login_do_avaliador_monta_a_fila_no_modo_por_atividade(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $this->projeto($area->id, 'Um');
        $this->projeto($area->id, 'Dois');
        $this->modo(ModoDistribuicao::Atividade);

        $this->assertSame(0, Avaliacao::where('avaliador_id', $avaliador->id)->count());

        $this->entrar($avaliador)->assertOk();

        $this->assertSame(2, Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('status', StatusAvaliacao::Designada->value)
            ->count());
    }

    public function test_login_nao_designa_nada_no_modo_total(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $this->projeto($area->id);
        $this->modo(ModoDistribuicao::Total);

        $this->entrar($avaliador)->assertOk();

        $this->assertSame(0, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_login_nao_designa_antes_de_a_avaliacao_ser_liberada(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $this->projeto($area->id);
        Edicao::atual()->update([
            'modo_distribuicao' => ModoDistribuicao::Atividade,
            'avaliacao_liberada_em' => now()->addWeek(),
        ]);

        $this->entrar($avaliador)->assertOk();

        $this->assertSame(0, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_login_nao_designa_depois_de_encerrado_o_periodo(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $this->projeto($area->id);
        Edicao::atual()->update([
            'modo_distribuicao' => ModoDistribuicao::Atividade,
            'avaliacao_liberada_em' => now()->subMonth(),
            'avaliacao_encerrada_em' => now()->subDay(),
        ]);

        $this->entrar($avaliador)->assertOk();

        $this->assertSame(0, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_login_de_orientador_nao_dispara_designacao(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $this->projeto($area->id);
        $this->modo(ModoDistribuicao::Atividade);

        $orientador = User::factory()->create(['password' => Hash::make(self::SENHA)]);

        $this->entrar($orientador)->assertOk();

        $this->assertSame(0, Avaliacao::count());
    }

    public function test_a_fila_do_login_respeita_o_minimo_por_avaliador(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        for ($i = 0; $i < 6; $i++) {
            $this->projeto($area->id, "Projeto {$i}");
        }

        Edicao::atual()->update(['avaliacoes_min_por_avaliador' => 2]);
        $this->modo(ModoDistribuicao::Atividade);

        $this->entrar($avaliador)->assertOk();

        $this->assertSame(2, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_edicao_nova_herda_o_modo_da_atual(): void
    {
        $this->modo(ModoDistribuicao::Atividade);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/edicoes', [
            'nome' => 'XVII FETECMS', 'ano' => 2027, 'copiar_de' => Edicao::atual()->id,
        ])->assertCreated();

        $nova = Edicao::where('ano', 2027)->firstOrFail();
        $this->assertSame(ModoDistribuicao::Atividade, $nova->modo_distribuicao);
    }
}
