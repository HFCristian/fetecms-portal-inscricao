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
use App\Services\FilaAvaliadorService;
use App\Services\SessaoAvaliadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 112 — o fim da sessão do avaliador e o prazo da avaliação aberta.
 *
 * A sessão acaba de três jeitos (sair, entrar de novo, sumir), e os três
 * devolvem a fila ao bolo. A avaliação que alguém abriu e largou é outra
 * história: passados os dias parametrizados, o projeto volta para a pilha, mas
 * **o rascunho fica guardado** e o avaliador pode retomar.
 */
class SessaoAvaliadorTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function avaliador(int $areaId): User
    {
        $user = User::factory()->avaliador()->create(['password' => Hash::make(self::SENHA)]);
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

    private function porAtividade(): void
    {
        Edicao::atual()->update([
            'modo_distribuicao' => ModoDistribuicao::Atividade,
            'avaliacao_liberada_em' => now()->subDay(),
            'avaliacao_encerrada_em' => null,
        ]);
    }

    private function entrar(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::SENHA]);
    }

    // --- Fim de sessão -----------------------------------------------------

    public function test_logout_devolve_a_fila_de_sessao_ao_bolo(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $this->projeto($area->id, 'Um');
        $this->projeto($area->id, 'Dois');
        $this->porAtividade();

        $this->entrar($avaliador)->assertOk();
        $this->assertSame(2, Avaliacao::where('avaliador_id', $avaliador->id)->count());

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(0, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_logout_nao_devolve_a_designacao_manual_nem_a_avaliacao_aberta(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $manual = $this->projeto($area->id, 'Da organização');
        $aberto = $this->projeto($area->id, 'Já comecei');
        $this->porAtividade();

        $daMao = Avaliacao::create([
            'projeto_id' => $manual->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada, 'designacao_manual' => true,
        ]);
        $emAndamento = Avaliacao::create([
            'projeto_id' => $aberto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::EmAndamento,
        ]);

        Sanctum::actingAs($avaliador);
        app(SessaoAvaliadorService::class)->aoSair($avaliador);

        $this->assertDatabaseHas('avaliacoes', ['id' => $daMao->id]);
        $this->assertDatabaseHas('avaliacoes', ['id' => $emAndamento->id]);
    }

    public function test_logout_no_modo_total_nao_mexe_na_fila(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id);
        Edicao::atual()->update([
            'modo_distribuicao' => ModoDistribuicao::Total,
            'avaliacao_liberada_em' => now()->subDay(),
        ]);

        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada,
        ]);

        Sanctum::actingAs($avaliador);
        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(1, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_login_novo_limpa_o_que_sobrou_da_sessao_anterior(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $velho = $this->projeto($area->id, 'Da sessão passada');
        $this->projeto($area->id, 'Novo 1');
        $this->projeto($area->id, 'Novo 2');
        $this->porAtividade();

        $sobra = Avaliacao::create([
            'projeto_id' => $velho->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada, 'atividade_em' => now()->subDays(3),
        ]);

        $this->entrar($avaliador)->assertOk();

        $this->assertDatabaseMissing('avaliacoes', ['id' => $sobra->id]);
        $this->assertSame(3, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    public function test_varredura_devolve_a_fila_de_quem_sumiu(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $sumido = $this->projeto($area->id, 'Preso');
        $this->porAtividade();
        Edicao::atual()->update(['horas_sessao_avaliador' => 4]);

        $antiga = Avaliacao::create([
            'projeto_id' => $sumido->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada, 'atividade_em' => now()->subHours(5),
        ]);
        $recente = Avaliacao::create([
            'projeto_id' => $this->projeto($area->id, 'Ativo')->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada, 'atividade_em' => now()->subHour(),
        ]);

        $resultado = app(SessaoAvaliadorService::class)->varrer();

        $this->assertSame(1, $resultado['sessoes']);
        $this->assertDatabaseMissing('avaliacoes', ['id' => $antiga->id]);
        $this->assertDatabaseHas('avaliacoes', ['id' => $recente->id]);
    }

    public function test_varredura_de_sessao_nao_roda_no_modo_total(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id);
        Edicao::atual()->update([
            'modo_distribuicao' => ModoDistribuicao::Total,
            'horas_sessao_avaliador' => 1,
            'avaliacao_liberada_em' => now()->subDay(),
        ]);

        $velha = Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada, 'atividade_em' => now()->subYear(),
        ]);

        $this->assertSame(0, app(SessaoAvaliadorService::class)->varrer()['sessoes']);
        $this->assertDatabaseHas('avaliacoes', ['id' => $velha->id]);
    }

    // --- Prazo da avaliação aberta ----------------------------------------

    public function test_avaliacao_aberta_ha_mais_de_y_dias_volta_para_a_pilha(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id, 'Parado');
        Edicao::atual()->update(['dias_avaliacao_aberta' => 3]);

        $parada = Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::EmAndamento,
            'respostas' => ['q1' => 8],
            'atividade_em' => now()->subDays(4),
        ]);

        $this->assertSame(1, app(SessaoAvaliadorService::class)->varrer()['abertas']);

        // Some das consultas normais — o projeto voltou para a distribuição…
        $this->assertNull(Avaliacao::find($parada->id));

        // …mas a linha e o rascunho continuam lá.
        $guardada = Avaliacao::comDevolvidas()->findOrFail($parada->id);
        $this->assertNotNull($guardada->devolvida_em);
        $this->assertSame(['q1' => 8], $guardada->respostas);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoDevolvidaPorPrazo->value)->first();
        $this->assertNotNull($registro);
        $this->assertSame($avaliador->name, $registro->detalhes['de']);
    }

    public function test_a_regra_dos_dias_vale_nos_dois_modos(): void
    {
        foreach ([ModoDistribuicao::Total, ModoDistribuicao::Atividade] as $modo) {
            $area = Area::create(['nome' => 'Área '.$modo->value]);
            $avaliador = $this->avaliador($area->id);
            Edicao::atual()->update(['modo_distribuicao' => $modo, 'dias_avaliacao_aberta' => 2]);

            Avaliacao::create([
                'projeto_id' => $this->projeto($area->id)->id, 'avaliador_id' => $avaliador->id,
                'status' => StatusAvaliacao::EmAndamento, 'atividade_em' => now()->subDays(5),
            ]);

            $this->assertSame(1, app(SessaoAvaliadorService::class)->varrer()['abertas'], $modo->value);
        }
    }

    public function test_sem_prazo_definido_a_avaliacao_aberta_nunca_volta(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        Edicao::atual()->update(['dias_avaliacao_aberta' => null]);

        $velha = Avaliacao::create([
            'projeto_id' => $this->projeto($area->id)->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::EmAndamento, 'atividade_em' => now()->subYear(),
        ]);

        $this->assertSame(0, app(SessaoAvaliadorService::class)->varrer()['abertas']);
        $this->assertDatabaseHas('avaliacoes', ['id' => $velha->id, 'devolvida_em' => null]);
    }

    public function test_projeto_devolvido_volta_a_ser_distribuivel(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $preso = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id, 'Único');
        Edicao::atual()->update([
            'dias_avaliacao_aberta' => 1,
            'avaliacoes_min_por_projeto' => 1,
            'avaliacoes_max_por_projeto' => 1,
        ]);

        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $preso->id,
            'status' => StatusAvaliacao::EmAndamento, 'atividade_em' => now()->subDays(2),
        ]);

        app(SessaoAvaliadorService::class)->varrer();

        // O projeto não conta mais como coberto: outro avaliador consegue pegá-lo.
        $novo = $this->avaliador($area->id);
        $this->assertSame($projeto->id, app(FilaAvaliadorService::class)->proximo($novo));
    }

    // --- Retomada ----------------------------------------------------------

    public function test_avaliador_retoma_o_rascunho_devolvido(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id, 'Retomável');
        Edicao::atual()->update([
            'dias_avaliacao_aberta' => 1,
            'avaliacao_liberada_em' => now()->subDay(),
        ]);

        $avaliacao = Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::EmAndamento,
            'respostas' => ['q1' => 6], 'atividade_em' => now()->subDays(3),
        ]);

        app(SessaoAvaliadorService::class)->varrer();
        Sanctum::actingAs($avaliador);

        // A tela do avaliador lista a devolvida, com o rótulo da data.
        $this->getJson('/api/v1/avaliacao')
            ->assertOk()
            ->assertJsonPath('data.devolvidos.0.avaliacao_id', $avaliacao->id)
            ->assertJsonCount(1, 'data.devolvidos');

        $this->postJson("/api/v1/avaliacao/{$avaliacao->id}/retomar")
            ->assertOk()
            ->assertJsonPath('data.status', 'em_andamento');

        $retomada = $avaliacao->fresh();
        $this->assertNull($retomada->devolvida_em);
        $this->assertSame(['q1' => 6], $retomada->respostas);
    }

    public function test_retomar_e_recusado_quando_o_projeto_ja_foi_coberto(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $projeto = $this->projeto($area->id, 'Tomado');
        // O máximo nunca fica abaixo do mínimo, então os dois vão a 1 para o
        // projeto caber uma avaliação só.
        Edicao::atual()->update([
            'dias_avaliacao_aberta' => 1,
            'avaliacoes_min_por_projeto' => 1,
            'avaliacoes_max_por_projeto' => 1,
            'avaliacao_liberada_em' => now()->subDay(),
        ]);

        $minha = Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::EmAndamento, 'atividade_em' => now()->subDays(3),
        ]);

        app(SessaoAvaliadorService::class)->varrer();

        // Outro avaliador chegou e concluiu enquanto ela estava devolvida.
        Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $this->avaliador($area->id)->id,
            'status' => StatusAvaliacao::Concluida, 'nota' => 9, 'concluida_em' => now(),
        ]);

        Sanctum::actingAs($avaliador);

        $this->postJson("/api/v1/avaliacao/{$minha->id}/retomar")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PROJETO_JA_COBERTO');

        $this->assertNotNull(Avaliacao::comDevolvidas()->find($minha->id)->devolvida_em);
    }

    public function test_avaliador_nao_retoma_avaliacao_de_outro(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $dono = $this->avaliador($area->id);
        $intruso = $this->avaliador($area->id);
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);

        $avaliacao = Avaliacao::create([
            'projeto_id' => $this->projeto($area->id)->id, 'avaliador_id' => $dono->id,
            'status' => StatusAvaliacao::EmAndamento, 'devolvida_em' => now(),
        ]);

        Sanctum::actingAs($intruso);

        $this->postJson("/api/v1/avaliacao/{$avaliacao->id}/retomar")->assertForbidden();
    }

    // --- Parametrização ----------------------------------------------------

    public function test_admin_salva_os_prazos_e_a_mudanca_vira_registro(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao/prazos', [
            'horas_sessao' => 6,
            'dias_avaliacao_aberta' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.horas_sessao', 6)
            ->assertJsonPath('data.dias_avaliacao_aberta', 5);

        $this->assertSame(6, Edicao::horasSessaoAvaliador());
        $this->assertSame(5, Edicao::diasAvaliacaoAberta());

        $this->assertDatabaseHas('registros_atividade', ['tipo' => TipoRegistro::AvaliacaoHorasSessao->value]);
        $this->assertDatabaseHas('registros_atividade', ['tipo' => TipoRegistro::AvaliacaoDiasAberta->value]);
    }

    public function test_horas_em_branco_voltam_ao_padrao_e_dias_em_branco_desligam(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao/prazos', [
            'horas_sessao' => null,
            'dias_avaliacao_aberta' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.horas_sessao', Edicao::PADRAO_HORAS_SESSAO)
            ->assertJsonPath('data.dias_avaliacao_aberta', null);
    }
}
