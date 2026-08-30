<?php

namespace Tests\Feature;

use App\Enums\AbaAdmin;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\LocalizacaoComite;
use App\Models\User;
use App\Services\ComiteTransporteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprints 72–73 — Comitê especial: o localizador de quem conduz um grupo e o
 * mapa em tempo real de todos que estão a caminho.
 */
class ComiteTransporteTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return array_merge([
            'pessoas' => 3,
            'acompanhantes' => [
                ['nome' => 'Bia', 'area' => 'Ciências Agrárias'],
                ['nome' => '', 'area' => ''],
                ['nome' => 'Caio', 'area' => null],
            ],
            'transporte' => 'van',
            'origem_nome' => 'Aeroporto de Campo Grande',
            'origem_lat' => -20.4689,
            'origem_lng' => -54.6725,
            'destino_nome' => 'UFMS — Cidade Universitária',
            'destino_lat' => -20.5027,
            'destino_lng' => -54.6144,
            'minutos' => 60,
        ], $over);
    }

    private function iniciar(User $user, array $over = []): LocalizacaoComite
    {
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/admin/comite/localizacao', $this->payload($over))->assertCreated();

        return LocalizacaoComite::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_liga_o_localizador_com_o_que_o_assistente_coletou(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita Condutora']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/comite/localizacao', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.pessoas', 3)
            ->assertJsonPath('data.transporte', 'van')
            ->assertJsonPath('data.destino.nome', 'UFMS — Cidade Universitária')
            ->assertJsonPath('data.responsavel', 'Rita Condutora')
            // Van vira rota de carro no provedor.
            ->assertJsonPath('data.modo_rota', 'DRIVING');

        $sessao = LocalizacaoComite::first();
        $this->assertTrue($sessao->ativa());
        // Acompanhante sem nome nem área não vira linha.
        $this->assertCount(2, $sessao->acompanhantes);
        $this->assertSame('Bia', $sessao->acompanhantes[0]['nome']);
        $this->assertSame('Ciências Agrárias', $sessao->acompanhantes[0]['area']);
        $this->assertNull($sessao->acompanhantes[1]['area']);
    }

    public function test_a_pe_e_onibus_pedem_outro_modo_de_rota(): void
    {
        $admin = User::factory()->admin()->create();

        $this->iniciar($admin, ['transporte' => 'a_pe']);
        $this->getJson('/api/v1/admin/comite/localizacao')
            ->assertOk()->assertJsonPath('data.sessao.modo_rota', 'WALKING');

        $this->iniciar($admin, ['transporte' => 'onibus']);
        $this->getJson('/api/v1/admin/comite/localizacao')
            ->assertOk()->assertJsonPath('data.sessao.modo_rota', 'TRANSIT');
    }

    public function test_ligar_de_novo_encerra_a_sessao_anterior(): void
    {
        $admin = User::factory()->admin()->create();
        $primeira = $this->iniciar($admin);
        $segunda = $this->iniciar($admin, ['destino_nome' => 'Outro destino']);

        $this->assertFalse($primeira->fresh()->ativa());
        $this->assertTrue($segunda->fresh()->ativa());
        $this->assertSame(1, LocalizacaoComite::ativas()->count());
    }

    public function test_cada_posicao_atualiza_o_ponto_atual_e_o_trajeto(): void
    {
        $admin = User::factory()->admin()->create();
        $sessao = $this->iniciar($admin);

        $this->postJson('/api/v1/admin/comite/localizacao/ponto', [
            'latitude' => -20.47, 'longitude' => -54.67, 'precisao_m' => 12,
            'distancia_m' => 8200, 'duracao_s' => 900,
        ])->assertOk()->assertJsonPath('data.posicao.lat', -20.47);

        $this->postJson('/api/v1/admin/comite/localizacao/ponto', [
            'latitude' => -20.48, 'longitude' => -54.65, 'distancia_m' => 6000, 'duracao_s' => 600,
        ])->assertOk();

        $sessao->refresh();
        $this->assertSame(-20.48, $sessao->latitude);
        $this->assertSame(6000, $sessao->distancia_m);
        $this->assertSame(2, $sessao->pontos()->count());

        // A previsão de chegada sai da última estimativa recebida.
        $this->getJson('/api/v1/admin/comite/localizacao')
            ->assertOk()
            ->assertJsonPath('data.sessao.duracao_s', 600)
            ->assertJsonCount(2, 'data.sessao.trajeto');
    }

    public function test_a_posicao_sem_estimativa_preserva_a_anterior(): void
    {
        $admin = User::factory()->admin()->create();
        $sessao = $this->iniciar($admin);

        $this->postJson('/api/v1/admin/comite/localizacao/ponto', [
            'latitude' => -20.47, 'longitude' => -54.67, 'distancia_m' => 8200, 'duracao_s' => 900,
        ])->assertOk();

        // Sem rota calculada nesta leitura (o provedor pode falhar): mantém a última.
        $this->postJson('/api/v1/admin/comite/localizacao/ponto', [
            'latitude' => -20.475, 'longitude' => -54.66,
        ])->assertOk();

        $this->assertSame(8200, $sessao->fresh()->distancia_m);
        $this->assertSame(900, $sessao->fresh()->duracao_s);
    }

    public function test_prorrogar_ajusta_o_tempo_do_localizador(): void
    {
        $admin = User::factory()->admin()->create();
        $sessao = $this->iniciar($admin, ['minutos' => 30]);

        $this->patchJson('/api/v1/admin/comite/localizacao', ['minutos' => 120])->assertOk();

        $this->assertGreaterThan(100, now()->diffInMinutes($sessao->fresh()->expira_em, absolute: true));
    }

    public function test_encerrar_apaga_o_trajeto_e_tira_do_mapa(): void
    {
        $admin = User::factory()->admin()->create();
        $sessao = $this->iniciar($admin);

        $this->postJson('/api/v1/admin/comite/localizacao/ponto', [
            'latitude' => -20.47, 'longitude' => -54.67,
        ])->assertOk();
        $this->assertSame(1, $sessao->pontos()->count());

        $this->deleteJson('/api/v1/admin/comite/localizacao')->assertOk();

        $sessao->refresh();
        $this->assertFalse($sessao->ativa());
        // O trajeto some; a sessão fica só como registro de que existiu.
        $this->assertSame(0, $sessao->pontos()->count());
        $this->getJson('/api/v1/admin/comite/mapa')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sessao_vencida_sai_do_mapa_e_perde_o_trajeto(): void
    {
        $admin = User::factory()->admin()->create();
        $sessao = $this->iniciar($admin, ['minutos' => 10]);

        $this->postJson('/api/v1/admin/comite/localizacao/ponto', [
            'latitude' => -20.47, 'longitude' => -54.67,
        ])->assertOk();

        $this->travel(11)->minutes();

        $this->getJson('/api/v1/admin/comite/mapa')->assertOk()->assertJsonCount(0, 'data');

        $sessao->refresh();
        $this->assertNotNull($sessao->encerrado_em);
        $this->assertSame(0, $sessao->pontos()->count());
    }

    public function test_sem_localizador_ligado_nao_ha_ponto_nem_encerramento(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/comite/localizacao')->assertOk()->assertJsonPath('data.sessao', null);
        $this->postJson('/api/v1/admin/comite/localizacao/ponto', ['latitude' => -20.4, 'longitude' => -54.6])
            ->assertNotFound();
        $this->deleteJson('/api/v1/admin/comite/localizacao')->assertNotFound();
    }

    public function test_o_mapa_mostra_todas_as_sessoes_ligadas(): void
    {
        $uma = User::factory()->admin()->create(['name' => 'Rita']);
        $outra = User::factory()->admin()->create(['name' => 'Léo']);

        $this->iniciar($uma);
        $this->postJson('/api/v1/admin/comite/localizacao/ponto', [
            'latitude' => -20.47, 'longitude' => -54.67, 'distancia_m' => 5000, 'duracao_s' => 600,
        ])->assertOk();

        $this->iniciar($outra, ['pessoas' => 2]);

        $mapa = $this->getJson('/api/v1/admin/comite/mapa')->assertOk();
        $mapa->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.intervalo_segundos', ComiteTransporteService::INTERVALO_SEGUNDOS);

        $responsaveis = array_column($mapa->json('data'), 'responsavel');
        $this->assertEqualsCanonicalizing(['Rita', 'Léo'], $responsaveis);
    }

    public function test_o_detalhe_do_ponto_traz_pessoas_nomes_areas_distancia_e_chegada(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Rita']);
        $sessao = $this->iniciar($admin);

        $this->postJson('/api/v1/admin/comite/localizacao/ponto', [
            'latitude' => -20.47, 'longitude' => -54.67, 'distancia_m' => 5000, 'duracao_s' => 600,
        ])->assertOk();

        $detalhe = $this->getJson("/api/v1/admin/comite/mapa/{$sessao->id}")->assertOk();

        $detalhe->assertJsonPath('data.pessoas', 3)
            ->assertJsonPath('data.acompanhantes.0.nome', 'Bia')
            ->assertJsonPath('data.acompanhantes.0.area', 'Ciências Agrárias')
            ->assertJsonPath('data.distancia_m', 5000)
            ->assertJsonPath('data.duracao_s', 600)
            ->assertJsonPath('data.destino.nome', 'UFMS — Cidade Universitária');

        $this->assertNotNull($detalhe->json('data.chegada_em'));
        $this->assertNotNull($detalhe->json('data.trajeto'));
    }

    public function test_detalhe_de_sessao_desligada_nao_abre(): void
    {
        $admin = User::factory()->admin()->create();
        $sessao = $this->iniciar($admin);
        $this->deleteJson('/api/v1/admin/comite/localizacao')->assertOk();

        $this->getJson("/api/v1/admin/comite/mapa/{$sessao->id}")->assertNotFound();
    }

    public function test_tempo_fora_dos_limites_e_recusado(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/comite/localizacao', $this->payload(['minutos' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('minutos');

        $this->postJson('/api/v1/admin/comite/localizacao', $this->payload(['minutos' => 5000]))
            ->assertStatus(422)->assertJsonValidationErrors('minutos');
    }

    public function test_destino_e_obrigatorio(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/comite/localizacao', $this->payload(['destino_nome' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('destino_nome');
    }

    public function test_a_aba_respeita_o_escopo_do_admin(): void
    {
        User::factory()->admin()->create(); // guardião do acesso total
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => [AbaAdmin::Projetos->value]]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/comite/mapa')->assertForbidden();
    }

    public function test_quem_nao_e_admin_nao_acessa_o_comite(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/comite/mapa')->assertForbidden();
        $this->postJson('/api/v1/admin/comite/localizacao', $this->payload())->assertForbidden();
    }
}
