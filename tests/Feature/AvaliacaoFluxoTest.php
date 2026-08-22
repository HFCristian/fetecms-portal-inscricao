<?php

namespace Tests\Feature;

use App\Enums\TipoDocumento;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\Subarea;
use App\Models\User;
use App\Support\Rubrica;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvaliacaoFluxoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    /** @return array{0: User, 1: Avaliacao} */
    private function cenario(bool $liberada = true, bool $demo = false): array
    {
        $area = Area::create(['nome' => 'Área A']);
        $av = User::factory()->avaliador()->create(['is_demo' => $demo]);
        AvaliadorProfile::factory()->create(['user_id' => $av->id, 'area_id' => $area->id]);
        $proj = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id, 'area_id' => $area->id, 'titulo' => 'Projeto X',
        ]);
        $aval = Avaliacao::create(['projeto_id' => $proj->id, 'avaliador_id' => $av->id, 'status' => 'designada']);

        if ($liberada) {
            Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);
        }

        return [$av, $aval];
    }

    /**
     * Todas as perguntas respondidas com o mesmo ponto da escala (e o mesmo
     * Sim/Não nas de duas opções).
     *
     * @return array<string, int|bool>
     */
    private function respostas(int $ponto = 10, bool $sim = true): array
    {
        $respostas = [];

        foreach (Rubrica::perguntas() as $pergunta) {
            $respostas[$pergunta['chave']] = $pergunta['tipo'] === Rubrica::TIPO_SIM_NAO ? $sim : $ponto;
        }

        return $respostas;
    }

    /**
     * Preenchimento válido para concluir: rubrica inteira respondida,
     * recomendações (opcionais) e a conferência da área.
     *
     * @param  array<string, int|bool>|null  $respostas
     * @return array<string, mixed>
     */
    private function preenchimento(?array $respostas = null): array
    {
        return [
            'respostas' => $respostas ?? $this->respostas(),
            'comentario_video' => 'Bom ritmo, mas o áudio oscila.',
            'comentario_projeto' => 'Vale aprofundar a discussão dos resultados.',
            // Conferência da classificação: área é obrigatória ao concluir.
            'area_correta' => true,
        ];
    }

    public function test_inicia_e_conclui_com_a_rubrica_inteira(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")
            ->assertOk()->assertJsonPath('data.status', 'em_andamento');

        // Tudo no topo da escala fecha exatamente o teto do documento.
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento())
            ->assertOk()
            ->assertJsonPath('data.status', 'concluida')
            ->assertJsonPath('data.nota', 10)
            ->assertJsonPath('data.nota_maxima', 10)
            ->assertJsonPath('data.respostas.introducao_problema', 10)
            ->assertJsonPath('data.respostas.titulo_coerente', true)
            ->assertJsonPath('data.comentario_video', 'Bom ritmo, mas o áudio oscila.')
            ->assertJsonPath('data.comentario_projeto', 'Vale aprofundar a discussão dos resultados.');

        $this->assertDatabaseHas('avaliacoes', ['id' => $aval->id, 'status' => 'concluida']);
        $this->assertSame(10.0, $aval->fresh()->nota);
    }

    public function test_o_pior_preenchimento_zera_a_nota(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        // "Não possui" em tudo e "Não" nas de duas opções: 0 é resposta válida.
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento($this->respostas(0, false)))
            ->assertOk()
            ->assertJsonPath('data.nota', 0)
            ->assertJsonPath('data.respostas.palavras_chave', false);
    }

    public function test_a_nota_e_a_soma_ponderada_das_respostas(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        // "Regular" (6) em toda a escala + "Sim" nas duas de Sim/Não:
        // 0,275 (Sim/Não) + 60% dos 9,725 restantes = 6,11.
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento($this->respostas(6)))
            ->assertOk()
            ->assertJsonPath('data.nota', 6.11);
    }

    public function test_cada_secao_pesa_o_que_o_documento_manda(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        // Só o vídeo no topo: a seção vale 2,0 da nota final.
        $respostas = [
            ...$this->respostas(0, false),
            'video_engajamento' => 10,
            'video_dominio' => 10,
        ];

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento($respostas))
            ->assertOk()
            ->assertJsonPath('data.nota', 2);
    }

    public function test_a_nota_final_e_calculada_no_servidor_e_ignora_a_enviada(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->preenchimento($this->respostas(0, false)),
            'nota' => 10, // tentativa de forçar a nota final
        ])->assertOk()->assertJsonPath('data.nota', 0);
    }

    public function test_recomendacoes_sao_opcionais(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            'respostas' => $this->respostas(),
            'area_correta' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.comentario_video', null)
            ->assertJsonPath('data.comentario_projeto', null);
    }

    public function test_recomendacao_em_branco_vira_nula(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->preenchimento(),
            'comentario_projeto' => '   ',
        ])->assertOk()->assertJsonPath('data.comentario_projeto', null);
    }

    public function test_show_traz_os_detalhes_do_projeto(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->getJson("/api/v1/avaliacao/{$aval->id}")
            ->assertOk()
            ->assertJsonPath('data.projeto.titulo', 'Projeto X')
            ->assertJsonPath('data.avaliacao.status', 'designada');
    }

    public function test_nao_conclui_sem_iniciar(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento())->assertStatus(422);
    }

    public function test_todas_as_perguntas_e_a_conferencia_da_area_sao_obrigatorias(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'respostas.titulo_coerente',
                'respostas.introducao_problema',
                'respostas.video_dominio',
                'area_correta',
            ]);
    }

    public function test_uma_pergunta_faltando_ja_barra_o_envio(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $respostas = $this->respostas();
        unset($respostas['conclusao_correlacao']);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento($respostas))
            ->assertStatus(422)
            ->assertJsonValidationErrors('respostas.conclusao_correlacao');
    }

    public function test_a_resposta_precisa_ser_um_ponto_da_escala(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        // A escala é 0, 2, 4, 6, 8 e 10: nem valor ímpar nem acima do teto valem.
        foreach ([5, 12] as $invalido) {
            $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento([
                ...$this->respostas(),
                'resumo_sintese' => $invalido,
            ]))->assertStatus(422)->assertJsonValidationErrors('respostas.resumo_sintese');
        }
    }

    public function test_pergunta_fora_da_rubrica_e_recusada(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento([
            ...$this->respostas(),
            'pergunta_inventada' => 10,
        ]))->assertStatus(422)->assertJsonValidationErrors('respostas');
    }

    public function test_a_avaliacao_segue_em_andamento_quando_o_preenchimento_e_invalido(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento([
            ...$this->respostas(),
            'resumo_sintese' => 5,
        ]))->assertStatus(422);

        $this->assertDatabaseHas('avaliacoes', [
            'id' => $aval->id, 'status' => 'em_andamento', 'nota' => null, 'respostas' => null,
        ]);
    }

    public function test_apenas_uma_avaliacao_em_andamento_por_vez(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $av = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $av->id, 'area_id' => $area->id]);
        $orient = User::factory()->create();
        $a1 = Avaliacao::create(['projeto_id' => Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $area->id])->id, 'avaliador_id' => $av->id, 'status' => 'designada']);
        $a2 = Avaliacao::create(['projeto_id' => Projeto::factory()->submetido()->create(['user_id' => $orient->id, 'area_id' => $area->id])->id, 'avaliador_id' => $av->id, 'status' => 'designada']);
        Edicao::atual()->update(['avaliacao_liberada_em' => now()->subDay()]);

        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$a1->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/avaliacao/{$a2->id}/iniciar")->assertStatus(422);
    }

    public function test_nao_avalia_antes_da_liberacao(): void
    {
        [$av, $aval] = $this->cenario(liberada: false);
        Sanctum::actingAs($av);

        $this->getJson("/api/v1/avaliacao/{$aval->id}")->assertStatus(403);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertStatus(403);
    }

    public function test_demo_em_modo_teste_ignora_a_data(): void
    {
        [$av, $aval] = $this->cenario(liberada: false, demo: true);
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar?teste=1")
            ->assertOk()
            ->assertJsonPath('data.status', 'em_andamento');
    }

    public function test_avaliador_real_nao_ignora_a_data_mesmo_com_teste(): void
    {
        [$av, $aval] = $this->cenario(liberada: false, demo: false);
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar?teste=1")->assertStatus(403);
    }

    public function test_nao_acessa_avaliacao_de_outro_avaliador(): void
    {
        [, $aval] = $this->cenario();
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->getJson("/api/v1/avaliacao/{$aval->id}")->assertStatus(403);
    }

    // --- Conferência da classificação (área obrigatória, subárea opcional) ---

    public function test_area_incorreta_exige_a_sugestao(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->preenchimento(), 'area_correta' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('area_sugerida_id');
    }

    public function test_conclui_sugerindo_area_e_subarea_corretas(): void
    {
        [$av, $aval] = $this->cenario();
        $outraArea = Area::create(['nome' => 'Área B']);
        $outraSubarea = Subarea::create(['area_id' => $outraArea->id, 'nome' => 'Subárea B1']);
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->preenchimento(),
            'area_correta' => false, 'area_sugerida_id' => $outraArea->id,
            'subarea_correta' => false, 'subarea_sugerida_id' => $outraSubarea->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.area_correta', false)
            ->assertJsonPath('data.area_sugerida', 'Área B')
            ->assertJsonPath('data.subarea_sugerida', 'Subárea B1');
    }

    public function test_a_sugestao_precisa_ser_diferente_da_classificacao_atual(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        // Sugerir a MESMA área do projeto não corrige nada.
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->preenchimento(),
            'area_correta' => false, 'area_sugerida_id' => $aval->projeto->area_id,
        ])->assertStatus(422)->assertJsonValidationErrors('area_sugerida_id');
    }

    public function test_marcar_area_como_correta_descarta_a_sugestao_salva_antes(): void
    {
        [$av, $aval] = $this->cenario();
        $outraArea = Area::create(['nome' => 'Área B']);
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        // Rascunho com a área marcada como errada...
        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", [
            'area_correta' => false, 'area_sugerida_id' => $outraArea->id,
        ])->assertOk()->assertJsonPath('data.area_sugerida_id', $outraArea->id);

        // ...e depois o avaliador muda de ideia: a sugestão órfã some.
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento())
            ->assertOk()
            ->assertJsonPath('data.area_correta', true)
            ->assertJsonPath('data.area_sugerida_id', null);
    }

    public function test_conferir_a_subarea_e_opcional(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        // Sem nenhuma resposta sobre a subárea, a avaliação é enviada normalmente.
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento())
            ->assertOk()
            ->assertJsonPath('data.subarea_correta', null);
    }

    public function test_subarea_incorreta_exige_a_sugestao(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->preenchimento(), 'subarea_correta' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('subarea_sugerida_id');
    }

    public function test_sugestao_precisa_existir_no_catalogo(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", [
            ...$this->preenchimento(), 'area_correta' => false, 'area_sugerida_id' => 99999,
        ])->assertStatus(422)->assertJsonValidationErrors('area_sugerida_id');
    }

    // --- Rascunho da avaliação ---

    public function test_salva_rascunho_parcial_e_continua_em_andamento(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", [
            'respostas' => ['video_engajamento' => 8],
            'comentario_video' => 'Faltou legenda.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'em_andamento')
            ->assertJsonPath('data.respostas.video_engajamento', 8)
            ->assertJsonPath('data.nota', null); // sem nota final enquanto não envia

        $this->assertNotNull($aval->fresh()->rascunho_em);
    }

    public function test_rascunho_e_recuperado_ao_reabrir_a_avaliacao(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", [
            'respostas' => ['resumo_sintese' => 4, 'palavras_chave' => false],
            'comentario_projeto' => 'Objetivo pouco claro.',
        ])->assertOk();

        $this->getJson("/api/v1/avaliacao/{$aval->id}")
            ->assertOk()
            ->assertJsonPath('data.avaliacao.respostas.resumo_sintese', 4)
            ->assertJsonPath('data.avaliacao.respostas.palavras_chave', false)
            ->assertJsonPath('data.avaliacao.comentario_projeto', 'Objetivo pouco claro.');
    }

    public function test_rascunho_valida_a_escala(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", ['respostas' => ['resumo_sintese' => 7]])
            ->assertStatus(422)->assertJsonValidationErrors('respostas.resumo_sintese');
    }

    public function test_rascunho_nao_exige_nada(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", [])->assertOk();
    }

    public function test_nao_salva_rascunho_sem_iniciar(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", ['respostas' => ['resumo_sintese' => 4]])
            ->assertStatus(422);
    }

    public function test_nao_salva_rascunho_de_avaliacao_ja_enviada(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento())->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", ['respostas' => ['resumo_sintese' => 2]])
            ->assertStatus(422);
    }

    public function test_enviar_limpa_a_marca_de_rascunho(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);
        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", ['respostas' => ['resumo_sintese' => 4]])->assertOk();

        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento())
            ->assertOk()
            ->assertJsonPath('data.rascunho_em', null);
    }

    public function test_rascunho_de_outro_avaliador_e_barrado(): void
    {
        [, $aval] = $this->cenario();
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->postJson("/api/v1/avaliacao/{$aval->id}/rascunho", ['respostas' => ['resumo_sintese' => 4]])
            ->assertStatus(403);
    }

    // --- Rubrica entregue ao front ---

    public function test_a_rubrica_acompanha_a_avaliacao(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->getJson("/api/v1/avaliacao/{$aval->id}")
            ->assertOk()
            ->assertJsonPath('data.rubrica.nota_maxima', 10)
            // Escala do documento: 0 a 10 de dois em dois.
            ->assertJsonCount(6, 'data.rubrica.escala')
            ->assertJsonPath('data.rubrica.escala.0.valor', 0)
            ->assertJsonPath('data.rubrica.escala.0.rotulo', 'Não possui')
            ->assertJsonPath('data.rubrica.escala.5.valor', 10)
            ->assertJsonPath('data.rubrica.escala.5.rotulo', 'Muito bom')
            // Seções na ordem do documento, da conferência da área ao campo final.
            ->assertJsonCount(12, 'data.rubrica.secoes')
            ->assertJsonPath('data.rubrica.secoes.0.chave', 'geral_inicio')
            ->assertJsonPath('data.rubrica.secoes.0.componente', 'classificacao')
            ->assertJsonPath('data.rubrica.secoes.11.componente', 'comentarios')
            ->assertJsonPath('data.rubrica.secoes.11.comentario.campo', 'comentario_projeto');
    }

    public function test_a_rubrica_traz_o_peso_e_as_orientacoes_de_cada_pergunta(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        $this->getJson("/api/v1/avaliacao/{$aval->id}")
            ->assertOk()
            ->assertJsonPath('data.rubrica.secoes.1.chave', 'titulo')
            ->assertJsonPath('data.rubrica.secoes.1.maximo', 0.15)
            ->assertJsonPath('data.rubrica.secoes.1.perguntas.0.tipo', 'sim_nao')
            ->assertJsonPath('data.rubrica.secoes.1.perguntas.0.peso', 0.15)
            ->assertJsonPath(
                'data.rubrica.secoes.1.perguntas.0.ajuda',
                'Avalie o título do projeto, levando em consideração sua adequação e adesão ao que foi proposto.',
            );
    }

    public function test_pergunta_sem_orientacao_no_documento_vem_sem_ajuda(): void
    {
        [$av, $aval] = $this->cenario();
        Sanctum::actingAs($av);

        // O documento ainda não traz a orientação do "domínio do tema" no vídeo.
        $this->getJson("/api/v1/avaliacao/{$aval->id}")
            ->assertOk()
            ->assertJsonPath('data.rubrica.secoes.10.perguntas.1.chave', 'video_dominio')
            ->assertJsonPath('data.rubrica.secoes.10.perguntas.1.ajuda', null);
    }

    // --- Projeto de continuação (lido, mas sem pergunta própria) ---

    public function test_projeto_de_continuacao_nao_gera_pergunta_extra(): void
    {
        [$av, $aval] = $this->cenario();
        $aval->projeto->update(['continuacao' => true, 'tempo_pesquisa_meses' => 18]);
        ProjetoDocumento::factory()->create([
            'projeto_id' => $aval->projeto_id,
            'tipo' => TipoDocumento::ProjetoContinuacao,
            'nome_original' => 'projeto-de-continuacao.pdf',
        ]);
        Sanctum::actingAs($av);

        // O documento continua na leitura do avaliador, mas não é pontuado à parte.
        $this->getJson("/api/v1/avaliacao/{$aval->id}")
            ->assertOk()
            ->assertJsonPath('data.projeto.continuacao', true)
            ->assertJsonPath('data.projeto.tempo_pesquisa_meses', 18)
            ->assertJsonMissingPath('data.projeto.avalia_continuidade');

        $this->postJson("/api/v1/avaliacao/{$aval->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/avaliacao/{$aval->id}/concluir", $this->preenchimento())
            ->assertOk()
            ->assertJsonPath('data.nota', 10); // mesmo teto de qualquer outro projeto
    }
}
