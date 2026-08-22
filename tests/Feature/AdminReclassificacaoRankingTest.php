<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use App\Support\Rubrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Painéis do admin em "Avaliação online": projetos com sugestão de
 * reclassificação (com filtros) e ranking dos projetos avaliados.
 */
class AdminReclassificacaoRankingTest extends TestCase
{
    use RefreshDatabase;

    private Area $exatas;

    private Area $bio;

    private Subarea $ecologia;

    private User $orientador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exatas = Area::create(['nome' => 'Exatas']);
        $this->bio = Area::create(['nome' => 'Biológicas']);
        $this->ecologia = Subarea::create(['area_id' => $this->bio->id, 'nome' => 'Ecologia']);
        $this->orientador = User::factory()->create();
    }

    private function avaliador(string $nome): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $this->exatas->id]);

        return $user;
    }

    private function projeto(string $titulo, Area $area, ?Subarea $sub = null): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => $this->orientador->id, 'titulo' => $titulo,
            'area_id' => $area->id, 'subarea_id' => $sub?->id,
        ]);
    }

    /**
     * Avaliação concluída com a rubrica respondida por igual: todas as
     * perguntas de escala no mesmo ponto e as de Sim/Não em "Sim". A nota sai
     * dos pesos, como no envio de verdade.
     *
     * @param  array<string, mixed>  $classificacao
     */
    private function avaliacaoConcluida(Projeto $p, User $avaliador, int $ponto = 10, array $classificacao = [], ?string $em = null): Avaliacao
    {
        $respostas = [];

        foreach (Rubrica::perguntas() as $pergunta) {
            $respostas[$pergunta['chave']] = $pergunta['tipo'] === Rubrica::TIPO_SIM_NAO ? true : $ponto;
        }

        return Avaliacao::create([
            'projeto_id' => $p->id, 'avaliador_id' => $avaliador->id, 'status' => 'concluida',
            'respostas' => $respostas, 'nota' => Rubrica::nota($respostas),
            'concluida_em' => $em ? now()->parse($em) : now(),
            ...$classificacao,
        ]);
    }

    private function comoAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    // --- Reclassificações sugeridas ---

    public function test_lista_projetos_com_sugestao_e_consolida_o_consenso(): void
    {
        $agua = $this->projeto('Purificação de água', $this->exatas);
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');
        $carla = $this->avaliador('Carla');

        // Dois avaliadores sugerem Biológicas; o terceiro acha que está certo.
        $this->avaliacaoConcluida($agua, $ana, 10, ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($agua, $bruno, 10, ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($agua, $carla, 10, ['area_correta' => true]);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'Purificação de água')
            ->assertJsonPath('data.0.area', 'Exatas')
            // Só quem apontou erro entra na contagem.
            ->assertJsonPath('data.0.total_sugestoes', 2)
            ->assertJsonPath('data.0.area_mais_sugerida.nome', 'Biológicas')
            ->assertJsonPath('data.0.area_mais_sugerida.votos', 2)
            ->assertJsonPath('data.0.subarea_mais_sugerida', null);
    }

    public function test_sugestao_de_subarea_tambem_entra(): void
    {
        $abelhas = $this->projeto('Abelhas nativas', $this->bio, Subarea::create(['area_id' => $this->bio->id, 'nome' => 'Botânica']));
        $this->avaliacaoConcluida($abelhas, $this->avaliador('Ana'), 10, [
            'area_correta' => true, 'subarea_correta' => false, 'subarea_sugerida_id' => $this->ecologia->id,
        ]);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')
            ->assertOk()
            ->assertJsonPath('data.0.subarea_mais_sugerida.nome', 'Ecologia')
            ->assertJsonPath('data.0.sugestoes.0.subarea_sugerida', 'Ecologia')
            ->assertJsonPath('data.0.sugestoes.0.area_sugerida', null);
    }

    public function test_projeto_sem_sugestao_nao_aparece(): void
    {
        $ok = $this->projeto('Tudo certo', $this->exatas);
        $this->avaliacaoConcluida($ok, $this->avaliador('Ana'), 10, ['area_correta' => true, 'subarea_correta' => true]);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_avaliacao_ainda_em_andamento_nao_aparece(): void
    {
        $p = $this->projeto('Em andamento', $this->exatas);
        // Rascunho já marcou a área como errada, mas a avaliação não foi enviada.
        Avaliacao::create([
            'projeto_id' => $p->id, 'avaliador_id' => $this->avaliador('Ana')->id,
            'status' => 'em_andamento', 'area_correta' => false, 'area_sugerida_id' => $this->bio->id,
        ]);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filtra_por_nome_do_projeto(): void
    {
        $this->criarDoisComSugestao();
        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes?q=abelh')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'Abelhas nativas');
    }

    public function test_filtra_pela_area_atual_do_projeto(): void
    {
        $this->criarDoisComSugestao();
        $this->comoAdmin();

        $this->getJson("/api/v1/admin/avaliacao/reclassificacoes?area_id={$this->exatas->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'Purificação de água');
    }

    public function test_filtra_por_periodo_da_data_de_avaliacao(): void
    {
        $p = $this->projeto('Purificação de água', $this->exatas);
        $this->avaliacaoConcluida($p, $this->avaliador('Ana'), 10,
            ['area_correta' => false, 'area_sugerida_id' => $this->bio->id], '2026-08-01 10:00');
        $this->avaliacaoConcluida($p, $this->avaliador('Bruno'), 10,
            ['area_correta' => false, 'area_sugerida_id' => $this->bio->id], '2026-08-20 10:00');

        $this->comoAdmin();

        // Só a sugestão dentro da janela sobra (o projeto continua listado).
        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes?de=2026-08-15')
            ->assertOk()
            ->assertJsonPath('data.0.total_sugestoes', 1);

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes?ate=2026-08-05')
            ->assertOk()
            ->assertJsonPath('data.0.total_sugestoes', 1);

        // Data limite é inclusiva nos dois extremos.
        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes?de=2026-08-01&ate=2026-08-20')
            ->assertOk()
            ->assertJsonPath('data.0.total_sugestoes', 2);
    }

    public function test_periodo_invertido_e_rejeitado(): void
    {
        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes?de=2026-08-20&ate=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('ate');
    }

    public function test_reclassificacoes_sao_restritas_ao_admin(): void
    {
        Sanctum::actingAs(User::factory()->create()); // orientador

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')->assertForbidden();
    }

    public function test_traz_as_opcoes_distintas_com_votos_para_o_admin_escolher(): void
    {
        $saude = Area::create(['nome' => 'Saúde']);
        $agua = $this->projeto('Purificação de água', $this->exatas);

        // 2 votos em Biológicas, 1 em Saúde.
        $this->avaliacaoConcluida($agua, $this->avaliador('Ana'), 10, ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($agua, $this->avaliador('Bruno'), 10, ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($agua, $this->avaliador('Carla'), 10, ['area_correta' => false, 'area_sugerida_id' => $saude->id]);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')
            ->assertOk()
            ->assertJsonCount(2, 'data.0.opcoes_area')
            // Mais votada primeiro, cada uma com o id para aplicar.
            ->assertJsonPath('data.0.opcoes_area.0.id', $this->bio->id)
            ->assertJsonPath('data.0.opcoes_area.0.votos', 2)
            ->assertJsonPath('data.0.opcoes_area.1.id', $saude->id)
            ->assertJsonPath('data.0.opcoes_area.1.votos', 1);
    }

    // --- Aplicar sugestões ---

    public function test_aplica_a_sugestao_de_area_de_um_projeto(): void
    {
        $agua = $this->projeto('Purificação de água', $this->exatas);
        $this->avaliacaoConcluida($agua, $this->avaliador('Ana'), 10,
            ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [['projeto_id' => $agua->id, 'area_id' => $this->bio->id]],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.area_anterior', 'Exatas')
            ->assertJsonPath('data.0.area', 'Biológicas')
            ->assertJsonPath('meta.message', 'Reclassificação aplicada em 1 projeto.');

        $this->assertSame($this->bio->id, $agua->fresh()->area_id);
    }

    public function test_aplica_area_e_subarea_do_mesmo_projeto(): void
    {
        $projeto = $this->projeto('Abelhas', $this->exatas);
        $this->avaliacaoConcluida($projeto, $this->avaliador('Ana'), 10, [
            'area_correta' => false, 'area_sugerida_id' => $this->bio->id,
            'subarea_correta' => false, 'subarea_sugerida_id' => $this->ecologia->id,
        ]);

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [[
                'projeto_id' => $projeto->id,
                'area_id' => $this->bio->id,
                'subarea_id' => $this->ecologia->id,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.area', 'Biológicas')
            ->assertJsonPath('data.0.subarea', 'Ecologia');

        $projeto->refresh();
        $this->assertSame($this->bio->id, $projeto->area_id);
        $this->assertSame($this->ecologia->id, $projeto->subarea_id);
    }

    public function test_aplica_varios_projetos_de_uma_vez(): void
    {
        $this->criarDoisComSugestao();
        $agua = Projeto::where('titulo', 'Purificação de água')->first();
        $abelhas = Projeto::where('titulo', 'Abelhas nativas')->first();

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [
                ['projeto_id' => $agua->id, 'area_id' => $this->bio->id],
                ['projeto_id' => $abelhas->id, 'subarea_id' => $this->ecologia->id],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.message', 'Reclassificação aplicada em 2 projetos.');

        $this->assertSame($this->bio->id, $agua->fresh()->area_id);
        $this->assertSame($this->ecologia->id, $abelhas->fresh()->subarea_id);
    }

    public function test_trocar_a_area_limpa_a_subarea_que_nao_pertence_a_ela(): void
    {
        $botanica = Subarea::create(['area_id' => $this->bio->id, 'nome' => 'Botânica']);
        // Projeto em Biológicas/Botânica que os avaliadores querem em Exatas.
        $projeto = $this->projeto('Sensor de umidade', $this->bio, $botanica);
        $this->avaliacaoConcluida($projeto, $this->avaliador('Ana'), 10,
            ['area_correta' => false, 'area_sugerida_id' => $this->exatas->id]);

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [['projeto_id' => $projeto->id, 'area_id' => $this->exatas->id]],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.subarea', null)
            ->assertJsonPath('data.0.subarea_limpa', true);

        $this->assertNull($projeto->fresh()->subarea_id);
    }

    public function test_sugestao_aplicada_some_da_lista(): void
    {
        $agua = $this->projeto('Purificação de água', $this->exatas);
        $this->avaliacaoConcluida($agua, $this->avaliador('Ana'), 10,
            ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);

        $this->comoAdmin();
        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [['projeto_id' => $agua->id, 'area_id' => $this->bio->id]],
        ])->assertOk();

        // A sugestão agora aponta para a área atual: não é mais uma troca pendente.
        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sugestao_divergente_continua_pendente_depois_de_aplicar_a_outra(): void
    {
        $saude = Area::create(['nome' => 'Saúde']);
        $agua = $this->projeto('Purificação de água', $this->exatas);
        $this->avaliacaoConcluida($agua, $this->avaliador('Ana'), 10, ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($agua, $this->avaliador('Bruno'), 10, ['area_correta' => false, 'area_sugerida_id' => $saude->id]);

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [['projeto_id' => $agua->id, 'area_id' => $this->bio->id]],
        ])->assertOk();

        // Quem sugeriu Saúde continua discordando da classificação nova.
        $this->getJson('/api/v1/admin/avaliacao/reclassificacoes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.area', 'Biológicas')
            ->assertJsonPath('data.0.total_sugestoes', 1)
            ->assertJsonPath('data.0.area_mais_sugerida.nome', 'Saúde');
    }

    public function test_nao_aplica_area_que_ninguem_sugeriu(): void
    {
        $saude = Area::create(['nome' => 'Saúde']);
        $agua = $this->projeto('Purificação de água', $this->exatas);
        $this->avaliacaoConcluida($agua, $this->avaliador('Ana'), 10,
            ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);

        $this->comoAdmin();

        // Saúde existe no catálogo, mas nenhum avaliador sugeriu para este projeto.
        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [['projeto_id' => $agua->id, 'area_id' => $saude->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('itens');

        $this->assertSame($this->exatas->id, $agua->fresh()->area_id);
    }

    public function test_lote_invalido_nao_aplica_nada(): void
    {
        $saude = Area::create(['nome' => 'Saúde']);
        $this->criarDoisComSugestao();
        $agua = Projeto::where('titulo', 'Purificação de água')->first();
        $abelhas = Projeto::where('titulo', 'Abelhas nativas')->first();

        $this->comoAdmin();

        // O primeiro item é válido; o segundo não. A transação desfaz os dois.
        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [
                ['projeto_id' => $agua->id, 'area_id' => $this->bio->id],
                ['projeto_id' => $abelhas->id, 'area_id' => $saude->id],
            ],
        ])->assertStatus(422);

        $this->assertSame($this->exatas->id, $agua->fresh()->area_id);
    }

    public function test_item_sem_area_nem_subarea_e_rejeitado(): void
    {
        $agua = $this->projeto('Purificação de água', $this->exatas);

        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [['projeto_id' => $agua->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('itens.0.area_id');
    }

    public function test_lista_vazia_e_rejeitada(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', ['itens' => []])
            ->assertStatus(422)->assertJsonValidationErrors('itens');
    }

    public function test_aplicar_e_restrito_ao_admin(): void
    {
        $agua = $this->projeto('Purificação de água', $this->exatas);
        Sanctum::actingAs(User::factory()->create()); // orientador

        $this->postJson('/api/v1/admin/avaliacao/reclassificacoes/aplicar', [
            'itens' => [['projeto_id' => $agua->id, 'area_id' => $this->bio->id]],
        ])->assertForbidden();
    }

    // --- Ranking ---

    public function test_ranking_ordena_pela_media_das_notas_finais(): void
    {
        $alto = $this->projeto('Secador solar', $this->exatas);
        $medio = $this->projeto('Purificação de água', $this->exatas);
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');

        // Toda a escala no topo fecha 10,00; em "Regular" (6), 6,11.
        $this->avaliacaoConcluida($alto, $ana, 10);
        $this->avaliacaoConcluida($alto, $bruno, 10);
        $this->avaliacaoConcluida($medio, $ana, 6);
        $this->avaliacaoConcluida($medio, $bruno, 6);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/ranking')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.posicao', 1)
            ->assertJsonPath('data.0.titulo', 'Secador solar')
            ->assertJsonPath('data.0.media', 10)
            ->assertJsonPath('data.0.nota_maxima', 10)
            ->assertJsonPath('data.0.avaliacoes', 2)
            ->assertJsonPath('data.1.posicao', 2)
            ->assertJsonPath('data.1.media', 6.11);
    }

    public function test_ranking_traz_a_media_de_cada_secao_da_rubrica(): void
    {
        $p = $this->projeto('Secador solar', $this->exatas);
        $this->avaliacaoConcluida($p, $this->avaliador('Ana'), 10);
        $this->avaliacaoConcluida($p, $this->avaliador('Bruno'), 6);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/ranking')
            ->assertOk()
            // Uma linha por seção pontuada do documento, na ordem dele.
            ->assertJsonCount(10, 'data.0.medias_secoes')
            // Título é Sim/Não: "Sim" nas duas avaliações rende o peso cheio.
            ->assertJsonPath('data.0.medias_secoes.0.chave', 'titulo')
            ->assertJsonPath('data.0.medias_secoes.0.maximo', 0.15)
            ->assertJsonPath('data.0.medias_secoes.0.media', 0.15)
            // Introdução vale 1,075: média entre 1,075 e 60% disso (0,645).
            ->assertJsonPath('data.0.medias_secoes.2.chave', 'introducao')
            ->assertJsonPath('data.0.medias_secoes.2.media', 0.86)
            // Vídeo vale 2,0: média entre 2,0 e 1,2.
            ->assertJsonPath('data.0.medias_secoes.9.chave', 'video')
            ->assertJsonPath('data.0.medias_secoes.9.maximo', 2)
            ->assertJsonPath('data.0.medias_secoes.9.media', 1.6);
    }

    public function test_avaliacao_da_rubrica_antiga_entra_so_na_media_geral(): void
    {
        $p = $this->projeto('Secador solar', $this->exatas);

        // Avaliação anterior à rubrica atual: a migration guardou a nota
        // reescalada, mas não há respostas para distribuir por seção.
        Avaliacao::create([
            'projeto_id' => $p->id,
            'avaliador_id' => $this->avaliador('Ana')->id,
            'status' => 'concluida',
            'nota' => 7.5,
            'concluida_em' => now(),
        ]);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/ranking')
            ->assertOk()
            ->assertJsonPath('data.0.media', 7.5)
            ->assertJsonPath('data.0.nota_maxima', 10)
            ->assertJsonPath('data.0.medias_secoes.0.media', null);
    }

    public function test_ranking_marca_como_parcial_quem_nao_tem_3_avaliacoes(): void
    {
        $completo = $this->projeto('Completo', $this->exatas);
        $parcial = $this->projeto('Parcial', $this->exatas);

        foreach (['Ana', 'Bruno', 'Carla'] as $nome) {
            $this->avaliacaoConcluida($completo, $this->avaliador($nome), 6);
        }
        $this->avaliacaoConcluida($parcial, $this->avaliador('Diego'), 10);

        $this->comoAdmin();

        $resposta = $this->getJson('/api/v1/admin/avaliacao/ranking')->assertOk();
        // O parcial lidera na média (10,00 > 6,11), mas vem sinalizado.
        $resposta->assertJsonPath('data.0.titulo', 'Parcial')
            ->assertJsonPath('data.0.completo', false)
            ->assertJsonPath('data.1.titulo', 'Completo')
            ->assertJsonPath('data.1.completo', true);
    }

    public function test_ranking_desempata_por_numero_de_avaliacoes(): void
    {
        $maisAvaliado = $this->projeto('Mais avaliado', $this->exatas);
        $menosAvaliado = $this->projeto('Menos avaliado', $this->exatas);

        foreach (['Ana', 'Bruno', 'Carla'] as $nome) {
            $this->avaliacaoConcluida($maisAvaliado, $this->avaliador($nome), 10);
        }
        $this->avaliacaoConcluida($menosAvaliado, $this->avaliador('Diego'), 10);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/ranking')
            ->assertOk()
            // Mesma média (12): a posição empata, mas quem tem mais avaliações vem antes.
            ->assertJsonPath('data.0.titulo', 'Mais avaliado')
            ->assertJsonPath('data.0.posicao', 1)
            ->assertJsonPath('data.1.posicao', 1);
    }

    public function test_ranking_ignora_projeto_sem_avaliacao_concluida(): void
    {
        $p = $this->projeto('Só designado', $this->exatas);
        Avaliacao::create(['projeto_id' => $p->id, 'avaliador_id' => $this->avaliador('Ana')->id, 'status' => 'designada']);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/ranking')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ranking_filtra_por_area(): void
    {
        $exatas = $this->projeto('De exatas', $this->exatas);
        $bio = $this->projeto('De biológicas', $this->bio);
        $this->avaliacaoConcluida($exatas, $this->avaliador('Ana'), 10);
        $this->avaliacaoConcluida($bio, $this->avaliador('Bruno'), 10);

        $this->comoAdmin();

        $this->getJson("/api/v1/admin/avaliacao/ranking?area_id={$this->bio->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titulo', 'De biológicas');
    }

    public function test_ranking_e_restrito_ao_admin(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->getJson('/api/v1/admin/avaliacao/ranking')->assertForbidden();
    }

    private function criarDoisComSugestao(): void
    {
        $agua = $this->projeto('Purificação de água', $this->exatas);
        $abelhas = $this->projeto('Abelhas nativas', $this->bio);

        $this->avaliacaoConcluida($agua, $this->avaliador('Ana'), 10,
            ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($abelhas, $this->avaliador('Bruno'), 10,
            ['area_correta' => true, 'subarea_correta' => false, 'subarea_sugerida_id' => $this->ecologia->id]);
    }
}
