<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\AvaliadorProfile;
use App\Models\Aviso;
use App\Models\AvisoVisualizacao;
use App\Models\Edicao;
use App\Models\User;
use App\Services\AvisoService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Avisos na tela: o admin publica um card que aparece para os orientadores
 * conectados, com as datas resolvidas na hora da leitura.
 */
class AvisoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin FETECMS']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function orientador(array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => Role::Orientador], $over));
    }

    private function publicar(array $over = []): Aviso
    {
        $this->postJson('/api/v1/admin/avisos', array_merge([
            'titulo' => 'As inscrições estão se encerrando',
            'mensagem' => 'Faltam poucos minutos.',
        ], $over))->assertCreated();

        return Aviso::latest('id')->first();
    }

    // ------------------------------------------------------------- publicação

    public function test_admin_publica_um_aviso(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/avisos', [
            'titulo' => 'Inscrições encerrando',
            'mensagem' => 'Confirme sua submissão.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.titulo', 'Inscrições encerrando')
            ->assertJsonPath('data.mensagem', 'Confirme sua submissão.');

        $this->assertDatabaseHas('avisos', [
            'titulo' => 'Inscrições encerrando',
            'autor_nome' => 'Admin FETECMS',
            'encerrado_em' => null,
        ]);
    }

    public function test_publicar_um_novo_aviso_encerra_o_anterior(): void
    {
        $this->admin();
        $primeiro = $this->publicar(['titulo' => 'Primeiro']);
        $segundo = $this->publicar(['titulo' => 'Segundo']);

        $this->assertNotNull($primeiro->fresh()->encerrado_em);
        $this->assertNull($segundo->fresh()->encerrado_em);
        $this->assertSame($segundo->id, app(AvisoService::class)->ativo()->id);
    }

    public function test_admin_encerra_o_aviso_no_ar(): void
    {
        $this->admin();
        $aviso = $this->publicar();

        $this->postJson("/api/v1/admin/avisos/{$aviso->id}/encerrar")->assertOk();

        $this->assertNotNull($aviso->fresh()->encerrado_em);
        $this->assertNull(app(AvisoService::class)->ativo());
    }

    public function test_encerrar_duas_vezes_nao_muda_a_data(): void
    {
        $this->admin();
        $aviso = $this->publicar();

        $this->postJson("/api/v1/admin/avisos/{$aviso->id}/encerrar")->assertOk();
        $primeiraData = $aviso->fresh()->encerrado_em;

        $this->travel(5)->minutes();
        $this->postJson("/api/v1/admin/avisos/{$aviso->id}/encerrar")->assertOk();

        $this->assertEquals($primeiraData, $aviso->fresh()->encerrado_em);
    }

    public function test_titulo_e_mensagem_sao_obrigatorios(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/avisos', ['titulo' => '  ', 'mensagem' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['titulo', 'mensagem']);
    }

    public function test_so_admin_publica_aviso(): void
    {
        Sanctum::actingAs($this->orientador());

        $this->postJson('/api/v1/admin/avisos', ['titulo' => 'X', 'mensagem' => 'Y'])->assertForbidden();
        $this->getJson('/api/v1/admin/avisos/opcoes')->assertForbidden();
    }

    // ---------------------------------------------------------------- entrega

    public function test_orientador_recebe_o_aviso_no_ar(): void
    {
        $this->admin();
        $this->publicar(['titulo' => 'Atenção', 'mensagem' => 'Prazo chegando.']);

        Sanctum::actingAs($this->orientador());

        $this->getJson('/api/v1/avisos/ativo')
            ->assertOk()
            ->assertJsonPath('data.titulo', 'Atenção')
            ->assertJsonPath('data.mensagem', 'Prazo chegando.');
    }

    public function test_sem_aviso_no_ar_a_resposta_vem_vazia(): void
    {
        Sanctum::actingAs($this->orientador());

        $this->getJson('/api/v1/avisos/ativo')->assertOk()->assertJsonPath('data', null);
    }

    public function test_aviso_encerrado_nao_chega_a_ninguem(): void
    {
        $this->admin();
        $aviso = $this->publicar();
        $this->postJson("/api/v1/admin/avisos/{$aviso->id}/encerrar")->assertOk();

        Sanctum::actingAs($this->orientador());

        $this->getJson('/api/v1/avisos/ativo')->assertOk()->assertJsonPath('data', null);
    }

    public function test_avaliador_e_admin_nao_recebem_o_card(): void
    {
        $admin = $this->admin();
        $this->publicar();

        $this->getJson('/api/v1/avisos/ativo')->assertOk()->assertJsonPath('data', null);

        Sanctum::actingAs(User::factory()->create(['role' => Role::Avaliador]));
        $this->getJson('/api/v1/avisos/ativo')->assertOk()->assertJsonPath('data', null);

        $this->assertTrue($admin->isAdmin());
    }

    public function test_orientador_inativo_nao_recebe_o_card(): void
    {
        $this->admin();
        $this->publicar();

        Sanctum::actingAs($this->orientador(['is_active' => false]));

        $this->getJson('/api/v1/avisos/ativo')->assertOk()->assertJsonPath('data', null);
    }

    // -------------------------------------------------------------- registros

    public function test_registra_quem_viu_o_card(): void
    {
        $this->admin();
        $aviso = $this->publicar();
        $ana = $this->orientador();

        Sanctum::actingAs($ana);
        $this->postJson("/api/v1/avisos/{$aviso->id}/visto")->assertOk();

        $this->assertDatabaseHas('aviso_visualizacoes', [
            'aviso_id' => $aviso->id,
            'user_id' => $ana->id,
            'fechado_em' => null,
        ]);
    }

    public function test_marcar_visto_duas_vezes_guarda_a_primeira_hora(): void
    {
        $this->admin();
        $aviso = $this->publicar();
        $ana = $this->orientador();

        Sanctum::actingAs($ana);
        $this->postJson("/api/v1/avisos/{$aviso->id}/visto")->assertOk();
        $primeira = AvisoVisualizacao::first()->visto_em;

        $this->travel(10)->minutes();
        $this->postJson("/api/v1/avisos/{$aviso->id}/visto")->assertOk();

        $this->assertSame(1, AvisoVisualizacao::count());
        $this->assertEquals($primeira, AvisoVisualizacao::first()->visto_em);
    }

    public function test_quem_fecha_o_card_nao_o_ve_de_novo_mas_os_outros_veem(): void
    {
        $this->admin();
        $aviso = $this->publicar();
        $ana = $this->orientador();
        $bruno = $this->orientador();

        Sanctum::actingAs($ana);
        $this->postJson("/api/v1/avisos/{$aviso->id}/fechar")->assertOk();
        $this->getJson('/api/v1/avisos/ativo')->assertOk()->assertJsonPath('data', null);

        Sanctum::actingAs($bruno);
        $this->getJson('/api/v1/avisos/ativo')->assertOk()->assertJsonPath('data.id', $aviso->id);

        $this->assertDatabaseHas('aviso_visualizacoes', ['user_id' => $ana->id, 'aviso_id' => $aviso->id]);
        $this->assertNotNull(AvisoVisualizacao::where('user_id', $ana->id)->first()->fechado_em);
    }

    public function test_fechar_registra_a_visualizacao_de_quem_nao_tinha_marcado_visto(): void
    {
        $this->admin();
        $aviso = $this->publicar();
        $ana = $this->orientador();

        Sanctum::actingAs($ana);
        $this->postJson("/api/v1/avisos/{$aviso->id}/fechar")->assertOk();

        $visualizacao = AvisoVisualizacao::first();
        $this->assertNotNull($visualizacao->visto_em);
        $this->assertNotNull($visualizacao->fechado_em);
    }

    // -------------------------------------------------------------- variáveis

    public function test_variaveis_de_data_sao_resolvidas_na_leitura(): void
    {
        Edicao::atual()->update([
            'submissoes_ate' => now()->addMinutes(45),
            'avaliacao_liberada_em' => '2026-10-05 08:00:00',
        ]);

        $this->admin();
        $this->publicar([
            'titulo' => 'Encerra em {{prazo_inscricoes}}',
            'mensagem' => 'Faltam {{ tempo_restante }}. As avaliações começam em {{inicio_avaliacoes}}.',
        ]);

        Sanctum::actingAs($this->orientador());

        $resposta = $this->getJson('/api/v1/avisos/ativo')->assertOk()->json('data');

        $this->assertStringContainsString(now()->addMinutes(45)->format('d/m/Y'), $resposta['titulo']);
        $this->assertStringContainsString('45 minutos', $resposta['mensagem']);
        $this->assertStringContainsString('05/10/2026 às 08:00', $resposta['mensagem']);
    }

    public function test_tempo_restante_acompanha_o_relogio_de_quem_le(): void
    {
        Edicao::atual()->update(['submissoes_ate' => now()->addMinutes(60)]);

        $this->admin();
        $this->publicar(['mensagem' => 'Faltam {{tempo_restante}}.']);

        Sanctum::actingAs($this->orientador());
        $this->getJson('/api/v1/avisos/ativo')->assertJsonPath('data.mensagem', 'Faltam 1 hora.');

        // Meia hora depois, o MESMO aviso já diz outra coisa.
        $this->travel(30)->minutes();
        $this->getJson('/api/v1/avisos/ativo')->assertJsonPath('data.mensagem', 'Faltam 30 minutos.');
    }

    public function test_sem_datas_definidas_as_variaveis_viram_texto_neutro(): void
    {
        Edicao::atual()->update(['submissoes_ate' => null, 'avaliacao_liberada_em' => null]);

        $this->admin();
        $this->publicar(['mensagem' => 'Prazo: {{prazo_inscricoes}} / Avaliações: {{inicio_avaliacoes}}.']);

        Sanctum::actingAs($this->orientador());

        $this->getJson('/api/v1/avisos/ativo')
            ->assertJsonPath('data.mensagem', 'Prazo: uma data ainda a definir / Avaliações: uma data ainda a definir.');
    }

    public function test_previa_mostra_o_texto_ja_resolvido(): void
    {
        Edicao::atual()->update(['submissoes_ate' => now()->addMinutes(20)]);
        $this->admin();

        $this->postJson('/api/v1/admin/avisos/previa', [
            'titulo' => 'Faltam {{tempo_restante}}',
            'mensagem' => 'Encerra em {{prazo_inscricoes}}.',
        ])
            ->assertOk()
            ->assertJsonPath('data.titulo', 'Faltam 20 minutos');
    }

    public function test_opcoes_trazem_variaveis_modelo_e_datas(): void
    {
        $this->admin();
        $this->orientador();

        $this->getJson('/api/v1/admin/avisos/opcoes')
            ->assertOk()
            ->assertJsonPath('data.destinatarios', 1)
            ->assertJsonPath('data.modelo.titulo', AvisoService::MODELO_TITULO)
            ->assertJsonStructure(['data' => [
                'variaveis' => [['chave', 'rotulo', 'descricao']],
                'modelo' => ['titulo', 'mensagem'],
                'inscricoes' => ['encerradas', 'prazo_label'],
            ]]);
    }

    // -------------------------------------------------------------- relatório

    /** Três orientadores: um fecha o card, um só vê e um não abre o sistema. */
    private function cenarioDeLeitura(): array
    {
        $this->admin();
        $aviso = $this->publicar(['titulo' => 'Atenção', 'mensagem' => 'Prazo chegando.']);

        $fechou = $this->orientador(['name' => 'Ana Lima', 'email' => 'ana@fetecms.test']);
        $viu = $this->orientador(['name' => 'Bruno Alves', 'email' => 'bruno@fetecms.test']);
        $ausente = $this->orientador(['name' => 'Carla Souza', 'email' => 'carla@fetecms.test']);

        Sanctum::actingAs($fechou);
        $this->postJson("/api/v1/avisos/{$aviso->id}/fechar")->assertOk();

        Sanctum::actingAs($viu);
        $this->postJson("/api/v1/avisos/{$aviso->id}/visto")->assertOk();

        Sanctum::actingAs(User::where('role', Role::Admin->value)->first());

        return [$aviso, $fechou, $viu, $ausente];
    }

    public function test_relatorio_conta_vistos_fechados_e_quem_nao_viu(): void
    {
        [$aviso] = $this->cenarioDeLeitura();

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}")
            ->assertOk()
            ->assertJsonPath('data.destinatarios', 3)
            ->assertJsonPath('data.vistos', 2)
            ->assertJsonPath('data.fechados', 1)
            ->assertJsonPath('data.nao_vistos', 1)
            ->assertJsonPath('data.ativo', true);
    }

    public function test_relatorio_lista_cada_pessoa_com_a_sua_situacao(): void
    {
        [$aviso] = $this->cenarioDeLeitura();

        $linhas = $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->json('data');

        $porNome = collect($linhas)->keyBy('nome');
        $this->assertSame('fechado', $porNome['Ana Lima']['situacao']);
        $this->assertSame('visto', $porNome['Bruno Alves']['situacao']);
        $this->assertSame('nao_visto', $porNome['Carla Souza']['situacao']);
        $this->assertNotNull($porNome['Ana Lima']['fechado_em']);
        $this->assertNull($porNome['Carla Souza']['visto_em']);
    }

    public function test_relatorio_filtra_por_situacao(): void
    {
        [$aviso] = $this->cenarioDeLeitura();

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores?situacao=fechado")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nome', 'Ana Lima');

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores?situacao=visto")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nome', 'Bruno Alves');

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores?situacao=nao_visto")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nome', 'Carla Souza');
    }

    public function test_situacao_desconhecida_e_ignorada(): void
    {
        [$aviso] = $this->cenarioDeLeitura();

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores?situacao=inventada")
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_relatorio_busca_por_nome_e_email(): void
    {
        [$aviso] = $this->cenarioDeLeitura();

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores?q=bruno")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nome', 'Bruno Alves');

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores?q=carla@fetecms.test")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_quem_leu_continua_no_relatorio_mesmo_desativado(): void
    {
        [$aviso, $fechou] = $this->cenarioDeLeitura();
        $fechou->update(['is_active' => false]);

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}")
            ->assertOk()
            ->assertJsonPath('data.vistos', 2)
            ->assertJsonPath('data.destinatarios', 2);

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores?situacao=fechado")
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Ana Lima');
    }

    public function test_historico_lista_os_avisos_do_mais_novo_para_o_mais_antigo(): void
    {
        [$aviso] = $this->cenarioDeLeitura();
        $novo = $this->publicar(['titulo' => 'Segundo aviso']);

        $this->getJson('/api/v1/admin/avisos')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $novo->id)
            ->assertJsonPath('data.0.ativo', true)
            ->assertJsonPath('data.1.id', $aviso->id)
            ->assertJsonPath('data.1.ativo', false)
            ->assertJsonPath('data.1.vistos', 2)
            ->assertJsonPath('data.1.fechados', 1);
    }

    public function test_relatorio_guarda_o_texto_original_com_as_variaveis(): void
    {
        Edicao::atual()->update(['submissoes_ate' => now()->addMinutes(30)]);
        $this->admin();
        $aviso = $this->publicar(['mensagem' => 'Faltam {{tempo_restante}}.']);

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}")
            ->assertOk()
            ->assertJsonPath('data.mensagem_original', 'Faltam {{tempo_restante}}.')
            ->assertJsonPath('data.mensagem', 'Faltam 30 minutos.');
    }

    public function test_exporta_o_relatorio_em_csv(): void
    {
        [$aviso] = $this->cenarioDeLeitura();

        $csv = $this->get("/api/v1/admin/avisos/{$aviso->id}/exportar")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        // fputcsv coloca aspas em campo com espaço — daí o formato do cabeçalho.
        $this->assertStringContainsString('Nome;E-mail;Situação;"Viu em";"Fechou em"', $csv);
        $this->assertStringContainsString('"Ana Lima";ana@fetecms.test;"Fechou o aviso"', $csv);
        $this->assertStringContainsString('"Carla Souza";carla@fetecms.test;"Ainda não viu"', $csv);
    }

    public function test_csv_respeita_o_filtro_da_tela(): void
    {
        [$aviso] = $this->cenarioDeLeitura();

        $csv = $this->get("/api/v1/admin/avisos/{$aviso->id}/exportar?situacao=nao_visto")->assertOk()->getContent();

        $this->assertStringContainsString('Carla Souza', $csv);
        $this->assertStringNotContainsString('Ana Lima', $csv);
    }

    public function test_so_admin_ve_o_relatorio(): void
    {
        [$aviso] = $this->cenarioDeLeitura();
        Sanctum::actingAs($this->orientador());

        $this->getJson('/api/v1/admin/avisos')->assertForbidden();
        $this->getJson("/api/v1/admin/avisos/{$aviso->id}")->assertForbidden();
        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores")->assertForbidden();
        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/exportar")->assertForbidden();
    }

    // ------------------------------------------------ públicos e expiração

    private function avaliador(array $perfil = [], array $over = []): User
    {
        $user = User::factory()->avaliador()->create($over);
        AvaliadorProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'area_id' => Area::create(['nome' => 'Área '.$user->id])->id,
        ], $perfil));

        return $user->fresh();
    }

    public function test_aviso_alcanca_so_o_publico_escolhido(): void
    {
        $this->admin();
        $orientador = $this->orientador();
        $avaliador = $this->avaliador();

        $aviso = $this->publicar(['titulo' => 'Só avaliadores', 'publicos' => ['avaliadores']]);

        $servico = app(AvisoService::class);
        $this->assertSame($aviso->id, $servico->paraUsuario($avaliador)?->id);
        $this->assertNull($servico->paraUsuario($orientador));
    }

    public function test_sem_publico_o_aviso_vai_para_os_orientadores(): void
    {
        $this->admin();
        $orientador = $this->orientador();
        $avaliador = $this->avaliador();

        $aviso = $this->publicar(['titulo' => 'Padrão']);

        $servico = app(AvisoService::class);
        $this->assertSame($aviso->id, $servico->paraUsuario($orientador)?->id);
        $this->assertNull($servico->paraUsuario($avaliador));
        $this->assertSame(['orientadores'], $aviso->publicos);
    }

    public function test_avisos_de_publicos_diferentes_convivem_no_ar(): void
    {
        $this->admin();
        $orientador = $this->orientador();
        $avaliador = $this->avaliador();

        $paraOrientadores = $this->publicar(['titulo' => 'Aos orientadores', 'publicos' => ['orientadores']]);
        $paraAvaliadores = $this->publicar(['titulo' => 'Aos avaliadores', 'publicos' => ['avaliadores']]);

        // O primeiro não foi encerrado: o público é outro.
        $this->assertNull($paraOrientadores->fresh()->encerrado_em);
        $this->assertNull($paraAvaliadores->fresh()->encerrado_em);

        $servico = app(AvisoService::class);
        $this->assertSame($paraOrientadores->id, $servico->paraUsuario($orientador)?->id);
        $this->assertSame($paraAvaliadores->id, $servico->paraUsuario($avaliador)?->id);
        $this->assertCount(2, $servico->vigentes());
    }

    public function test_republicar_para_o_mesmo_publico_encerra_o_anterior(): void
    {
        $this->admin();

        $primeiro = $this->publicar(['titulo' => 'Primeiro', 'publicos' => ['avaliadores']]);
        $segundo = $this->publicar(['titulo' => 'Segundo', 'publicos' => ['avaliadores']]);

        $this->assertNotNull($primeiro->fresh()->encerrado_em);
        $this->assertNull($segundo->fresh()->encerrado_em);
    }

    public function test_comissao_especial_como_publico_do_aviso(): void
    {
        $this->admin();
        $daComissao = $this->avaliador(['comissao_especial' => true]);
        $comum = $this->avaliador();

        $aviso = $this->publicar(['titulo' => 'Comissão', 'publicos' => ['avaliadores_comissao']]);

        $servico = app(AvisoService::class);
        $this->assertSame($aviso->id, $servico->paraUsuario($daComissao)?->id);
        $this->assertNull($servico->paraUsuario($comum));
    }

    public function test_aviso_expira_sozinho_na_data(): void
    {
        $this->admin();
        $orientador = $this->orientador();

        $aviso = $this->publicar([
            'titulo' => 'Com prazo',
            'expira_em' => now()->addHour()->format('Y-m-d\TH:i'),
        ]);

        $servico = app(AvisoService::class);
        $this->assertSame($aviso->id, $servico->paraUsuario($orientador)?->id);
        $this->assertNotNull($aviso->fresh()->expira_em);

        $this->travel(2)->hours();

        $this->assertNull($servico->paraUsuario($orientador));
        $this->assertCount(0, $servico->vigentes());
        $this->assertFalse($aviso->fresh()->ativo());
        // Expirar não é encerrar: a data do admin continua vazia.
        $this->assertNull($aviso->fresh()->encerrado_em);

        $this->travelBack();
    }

    public function test_expiracao_precisa_ser_no_futuro(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/avisos', [
            'titulo' => 'Passado',
            'mensagem' => 'Texto',
            'expira_em' => now()->subHour()->format('Y-m-d\TH:i'),
        ])->assertStatus(422)->assertJsonValidationErrors('expira_em');
    }

    public function test_publico_invalido_e_recusado(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/avisos', [
            'titulo' => 'Título',
            'mensagem' => 'Texto',
            'publicos' => ['inexistente'],
        ])->assertStatus(422)->assertJsonValidationErrors('publicos.0');
    }

    public function test_previa_conta_quantos_o_publico_alcanca(): void
    {
        $this->admin();
        $this->orientador();
        $this->orientador();
        $this->avaliador();

        $this->postJson('/api/v1/admin/avisos/previa', [
            'titulo' => 'Título',
            'mensagem' => 'Texto',
            'publicos' => ['avaliadores'],
        ])->assertOk()->assertJsonPath('data.destinatarios', 1);

        $this->postJson('/api/v1/admin/avisos/previa', [
            'titulo' => 'Título',
            'mensagem' => 'Texto',
            'publicos' => ['orientadores'],
        ])->assertOk()->assertJsonPath('data.destinatarios', 2);
    }

    public function test_relatorio_usa_o_publico_do_aviso(): void
    {
        $this->admin();
        $this->orientador();
        $avaliador = $this->avaliador();

        $aviso = $this->publicar(['titulo' => 'Aos avaliadores', 'publicos' => ['avaliadores']]);

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}")
            ->assertOk()
            // Só o avaliador entra na base: o orientador não recebeu este aviso.
            ->assertJsonPath('data.destinatarios', 1)
            ->assertJsonPath('data.nao_vistos', 1)
            ->assertJsonPath('data.publicos.0.label', 'Todos os avaliadores');

        $this->getJson("/api/v1/admin/avisos/{$aviso->id}/leitores")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $avaliador->email);
    }

    public function test_endpoint_de_avisos_no_ar_lista_todos(): void
    {
        $this->admin();
        $this->publicar(['titulo' => 'Aos orientadores', 'publicos' => ['orientadores']]);
        $this->publicar(['titulo' => 'Aos avaliadores', 'publicos' => ['avaliadores']]);

        $this->getJson('/api/v1/admin/avisos/ativo')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.titulo', 'Aos avaliadores');
    }
}
