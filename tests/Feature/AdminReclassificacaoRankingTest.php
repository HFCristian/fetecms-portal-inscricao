<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
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
     * @param  array{0:int,1:int,2:int}  $notas
     * @param  array<string, mixed>  $classificacao
     */
    private function avaliacaoConcluida(Projeto $p, User $avaliador, array $notas, array $classificacao = [], ?string $em = null): Avaliacao
    {
        [$v, $r, $q] = $notas;

        return Avaliacao::create([
            'projeto_id' => $p->id, 'avaliador_id' => $avaliador->id, 'status' => 'concluida',
            'nota_video' => $v, 'nota_resumo' => $r, 'nota_pesquisa' => $q, 'nota' => $v + $r + $q,
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
        $this->avaliacaoConcluida($agua, $ana, [9, 8, 10], ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($agua, $bruno, [8, 9, 9], ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($agua, $carla, [9, 9, 9], ['area_correta' => true]);

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
        $this->avaliacaoConcluida($abelhas, $this->avaliador('Ana'), [7, 8, 8], [
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
        $this->avaliacaoConcluida($ok, $this->avaliador('Ana'), [9, 9, 9], ['area_correta' => true, 'subarea_correta' => true]);

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
        $this->avaliacaoConcluida($p, $this->avaliador('Ana'), [9, 8, 10],
            ['area_correta' => false, 'area_sugerida_id' => $this->bio->id], '2026-08-01 10:00');
        $this->avaliacaoConcluida($p, $this->avaliador('Bruno'), [8, 9, 9],
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

    // --- Ranking ---

    public function test_ranking_ordena_pela_media_das_notas_finais(): void
    {
        $alto = $this->projeto('Secador solar', $this->exatas);
        $medio = $this->projeto('Purificação de água', $this->exatas);
        $ana = $this->avaliador('Ana');
        $bruno = $this->avaliador('Bruno');

        $this->avaliacaoConcluida($alto, $ana, [10, 10, 10]);   // 30
        $this->avaliacaoConcluida($alto, $bruno, [9, 10, 9]);   // 28  -> média 29
        $this->avaliacaoConcluida($medio, $ana, [7, 7, 7]);     // 21
        $this->avaliacaoConcluida($medio, $bruno, [8, 7, 8]);   // 23  -> média 22

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/ranking')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.posicao', 1)
            ->assertJsonPath('data.0.titulo', 'Secador solar')
            ->assertJsonPath('data.0.media', 29)
            ->assertJsonPath('data.0.nota_maxima', 30)
            ->assertJsonPath('data.0.avaliacoes', 2)
            ->assertJsonPath('data.1.posicao', 2)
            ->assertJsonPath('data.1.media', 22);
    }

    public function test_ranking_traz_a_media_de_cada_quesito(): void
    {
        $p = $this->projeto('Secador solar', $this->exatas);
        $this->avaliacaoConcluida($p, $this->avaliador('Ana'), [10, 6, 8]);
        $this->avaliacaoConcluida($p, $this->avaliador('Bruno'), [9, 7, 8]);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/ranking')
            ->assertOk()
            ->assertJsonPath('data.0.medias_quesitos.video', 9.5)
            ->assertJsonPath('data.0.medias_quesitos.resumo', 6.5)
            ->assertJsonPath('data.0.medias_quesitos.pesquisa', 8);
    }

    public function test_ranking_marca_como_parcial_quem_nao_tem_3_avaliacoes(): void
    {
        $completo = $this->projeto('Completo', $this->exatas);
        $parcial = $this->projeto('Parcial', $this->exatas);

        foreach (['Ana', 'Bruno', 'Carla'] as $nome) {
            $this->avaliacaoConcluida($completo, $this->avaliador($nome), [5, 5, 5]);
        }
        $this->avaliacaoConcluida($parcial, $this->avaliador('Diego'), [9, 9, 9]);

        $this->comoAdmin();

        $resposta = $this->getJson('/api/v1/admin/avaliacao/ranking')->assertOk();
        // O parcial lidera na média (27 > 15), mas vem sinalizado.
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
            $this->avaliacaoConcluida($maisAvaliado, $this->avaliador($nome), [8, 8, 8]);
        }
        $this->avaliacaoConcluida($menosAvaliado, $this->avaliador('Diego'), [8, 8, 8]);

        $this->comoAdmin();

        $this->getJson('/api/v1/admin/avaliacao/ranking')
            ->assertOk()
            // Mesma média (24): a posição empata, mas quem tem mais avaliações vem antes.
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
        $this->avaliacaoConcluida($exatas, $this->avaliador('Ana'), [8, 8, 8]);
        $this->avaliacaoConcluida($bio, $this->avaliador('Bruno'), [9, 9, 9]);

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

        $this->avaliacaoConcluida($agua, $this->avaliador('Ana'), [9, 8, 10],
            ['area_correta' => false, 'area_sugerida_id' => $this->bio->id]);
        $this->avaliacaoConcluida($abelhas, $this->avaliador('Bruno'), [7, 8, 8],
            ['area_correta' => true, 'subarea_correta' => false, 'subarea_sugerida_id' => $this->ecologia->id]);
    }
}
