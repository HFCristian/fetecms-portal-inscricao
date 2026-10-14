<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusRecomendacao;
use App\Enums\TipoCredencial;
use App\Models\Area;
use App\Models\AvaliadorProfile;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RecomendacaoPremiacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 193 — o avaliador da comissão especial recomenda finalistas para as
 * credenciais e prêmios; a organização aprova ou rejeita, e a decisão só
 * marca (não anexa a credencial).
 */
class RecomendacaoPremiacaoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private Projeto $finalista;

    private Projeto $outroFinalista;

    private Credencial $credencial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);
        $this->finalista = Projeto::factory()->submetido()->create(['titulo' => 'Bioplástico de mandioca', 'edicao_id' => $this->edicao->id]);
        $this->outroFinalista = Projeto::factory()->submetido()->create(['titulo' => 'Abelhas nativas', 'edicao_id' => $this->edicao->id]);

        $lista = ListaFinal::create(['edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'vigente' => true, 'versao' => 1]);
        $lista->projetos()->attach([$this->finalista->id, $this->outroFinalista->id]);

        $this->credencial = Credencial::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'MOSTRATEC 2027', 'orgao' => 'Fundação Liberato',
            'vagas' => 1, 'informacoes' => 'Só ensino médio.',
        ]);
    }

    private function avaliador(bool $comissao = true): User
    {
        return AvaliadorProfile::factory()->create(['comissao_especial' => $comissao])->user;
    }

    private function recomendar(User $avaliador, ?Projeto $projeto = null, ?Credencial $credencial = null)
    {
        Sanctum::actingAs($avaliador);

        return $this->postJson('/api/v1/avaliador/comite/recomendacoes', [
            'credencial_id' => ($credencial ?? $this->credencial)->id,
            'projeto_id' => ($projeto ?? $this->finalista)->id,
            'justificativa' => '  Estande impecável.  ',
        ]);
    }

    private function adminDe(string $aba): User
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => $aba, 'abas' => [$aba]]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    // --- O avaliador -----------------------------------------------------

    public function test_a_comissao_ve_credenciais_ativas_com_informacoes_e_os_finalistas(): void
    {
        Credencial::create(['edicao_id' => $this->edicao->id, 'nome' => 'Cancelada', 'ativa' => false]);
        Credencial::create(['edicao_id' => $this->edicao->id, 'nome' => 'Destaque', 'tipo' => TipoCredencial::Premio]);
        Sanctum::actingAs($this->avaliador());

        $dados = $this->getJson('/api/v1/avaliador/comite')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['MOSTRATEC 2027', 'Destaque'], array_column($dados['credenciais'], 'nome'));
        $this->assertSame('Só ensino médio.', collect($dados['credenciais'])->firstWhere('nome', 'MOSTRATEC 2027')['informacoes']);
        $this->assertEqualsCanonicalizing(['Bioplástico de mandioca', 'Abelhas nativas'], array_column($dados['candidatos'], 'titulo'));
        // O que o projeto já levou é da organização, não vai para quem recomenda.
        $this->assertArrayNotHasKey('credenciais', $dados['candidatos'][0]);
        $this->assertSame([], $dados['recomendacoes']);
    }

    public function test_quem_nao_e_da_comissao_nao_entra(): void
    {
        Sanctum::actingAs($this->avaliador(comissao: false));

        $this->getJson('/api/v1/avaliador/comite')->assertForbidden();
        $this->recomendar($this->avaliador(comissao: false))->assertForbidden();
    }

    public function test_recomenda_sem_limite_por_avaliador_nem_por_credencial(): void
    {
        $ana = $this->avaliador();
        $bruno = $this->avaliador();
        $destaque = Credencial::create(['edicao_id' => $this->edicao->id, 'nome' => 'Destaque']);

        // A credencial tem 1 vaga e recebe 3 recomendações: recomendação não é atribuição.
        $this->recomendar($ana)->assertCreated()
            ->assertJsonPath('data.recomendacoes.0.status', 'pendente')
            ->assertJsonPath('data.recomendacoes.0.justificativa', 'Estande impecável.');
        $this->recomendar($ana, $this->outroFinalista)->assertCreated();
        $this->recomendar($ana, $this->finalista, $destaque)->assertCreated();
        $this->recomendar($bruno)->assertCreated();

        $this->assertSame(4, RecomendacaoPremiacao::count());
        $this->assertSame(3, RecomendacaoPremiacao::where('credencial_id', $this->credencial->id)->count());
        // E nada foi anexado.
        $this->assertSame(0, $this->credencial->projetos()->count());
    }

    public function test_nao_repete_a_mesma_recomendacao(): void
    {
        $ana = $this->avaliador();
        $this->recomendar($ana)->assertCreated();

        $this->recomendar($ana)->assertStatus(422)->assertJsonValidationErrors('projeto_id');
    }

    public function test_so_finalista_e_so_credencial_ativa(): void
    {
        $ana = $this->avaliador();
        $foraDaLista = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        $cancelada = Credencial::create(['edicao_id' => $this->edicao->id, 'nome' => 'Cancelada', 'ativa' => false]);

        $this->recomendar($ana, $foraDaLista)->assertStatus(422)->assertJsonValidationErrors('projeto_id');
        $this->recomendar($ana, $this->finalista, $cancelada)->assertStatus(422)->assertJsonValidationErrors('credencial_id');
    }

    public function test_retira_a_propria_pendente_mas_nao_a_decidida_nem_a_alheia(): void
    {
        $ana = $this->avaliador();
        $this->recomendar($ana)->assertCreated();
        $minha = RecomendacaoPremiacao::firstOrFail();

        Sanctum::actingAs($this->avaliador());
        $this->deleteJson("/api/v1/avaliador/comite/recomendacoes/{$minha->id}")->assertNotFound();

        $minha->update(['status' => StatusRecomendacao::Rejeitada]);
        Sanctum::actingAs($ana);
        $this->deleteJson("/api/v1/avaliador/comite/recomendacoes/{$minha->id}")
            ->assertStatus(422)->assertJsonValidationErrors('recomendacao');

        $minha->update(['status' => StatusRecomendacao::Pendente]);
        $this->deleteJson("/api/v1/avaliador/comite/recomendacoes/{$minha->id}")->assertOk();
        $this->assertSame(0, RecomendacaoPremiacao::count());
    }

    // --- A organização ---------------------------------------------------

    /** Sprint 201 — os pedidos ficam no cartão da credencial, para decidir ali. */
    public function test_pendentes_aparecem_no_cartao_e_aprovar_nao_anexa_a_credencial(): void
    {
        $ana = $this->avaliador();
        $this->recomendar($ana)->assertCreated();
        $this->recomendar($ana, $this->outroFinalista)->assertCreated();
        $admin = $this->adminDe('comite');

        $resposta = $this->getJson('/api/v1/admin/comite/premiacao')->assertOk();
        $cartao = $resposta->json('data.0');
        $this->assertSame(2, $resposta->json('meta.recomendacoes.pendentes'));
        $this->assertCount(2, $cartao['pendentes']);
        $this->assertSame($ana->name, $cartao['pendentes'][0]['avaliador']);
        $this->assertSame('Estande impecável.', $cartao['pendentes'][0]['justificativa']);
        $this->assertSame([], $cartao['aprovadas']);

        $recomendacao = RecomendacaoPremiacao::where('projeto_id', $this->finalista->id)->firstOrFail();
        $this->postJson("/api/v1/admin/premiacao/recomendacoes/{$recomendacao->id}/decisao", ['status' => 'aprovada'])
            ->assertOk()->assertJsonPath('meta.message', 'Recomendação aprovada.');

        $recomendacao->refresh();
        $this->assertSame(StatusRecomendacao::Aprovada, $recomendacao->status);
        $this->assertSame($admin->id, $recomendacao->decidida_por);
        $this->assertNotNull($recomendacao->decidida_em);
        // Aprovar só marca: a credencial continua sem projeto.
        $this->assertSame(0, $this->credencial->projetos()->count());

        $cartao = $this->getJson('/api/v1/admin/comite/premiacao')->json('data.0');
        $this->assertCount(1, $cartao['pendentes']);
        $this->assertSame('Bioplástico de mandioca', $cartao['aprovadas'][0]['projeto']);
        $this->assertSame([$recomendacao->id], $cartao['aprovadas'][0]['recomendacao_ids']);
    }

    /**
     * Sprints 194 e 201 — os filtros recortam as recomendações dentro dos
     * cartões: título ou código do projeto, área e categoria.
     */
    public function test_filtra_as_recomendacoes_dos_cartoes_por_titulo_codigo_area_e_categoria(): void
    {
        $agrarias = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $biologicas = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
        $this->finalista->update(['area_id' => $agrarias->id, 'categoria' => Categoria::Fetecms]);
        $this->outroFinalista->update(['area_id' => $biologicas->id, 'categoria' => Categoria::FetecJr]);
        $ana = $this->avaliador();
        $this->recomendar($ana)->assertCreated();
        $this->recomendar($ana, $this->outroFinalista)->assertCreated();
        RecomendacaoPremiacao::where('projeto_id', $this->outroFinalista->id)->update(['status' => 'aprovada']);
        $this->adminDe('comite');

        $projetos = function (string $query) {
            $cartao = $this->getJson('/api/v1/admin/comite/premiacao'.$query)->assertOk()->json('data.0');

            return array_merge(array_column($cartao['pendentes'], 'projeto'), array_column($cartao['aprovadas'], 'projeto'));
        };

        $this->assertSame(['Bioplástico de mandioca'], $projetos('?q=MANDIOCA'));
        // O código da lista final: FET.AGR-001 para o bioplástico, JR.BIO-001 para as abelhas.
        $this->assertSame(['Bioplástico de mandioca'], $projetos('?q=fet.agr'));
        $this->assertSame(['Abelhas nativas'], $projetos('?q=JR.BIO-001'));
        $this->assertSame(['Abelhas nativas'], $projetos('?area_id='.$biologicas->id));
        $this->assertSame(['Bioplástico de mandioca'], $projetos('?categoria=fetecms'));
        $this->assertSame([], $projetos('?categoria=fetecms_fundect'));

        $this->getJson('/api/v1/admin/comite/premiacao?area_id='.$agrarias->id)
            ->assertJsonPath('meta.recomendacoes.pendentes', 1)
            ->assertJsonPath('meta.recomendacoes.opcoes.categorias.0.value', 'fetec_jr');

        $this->getJson('/api/v1/admin/comite/premiacao?categoria=pictec')
            ->assertStatus(422)->assertJsonValidationErrors('categoria');
    }

    /** Sprint 195 — as aprovadas aparecem no cartão da credencial, nas duas telas. */
    public function test_aprovadas_aparecem_no_cartao_da_credencial_agrupadas_por_projeto(): void
    {
        $ana = $this->avaliador();
        $bruno = $this->avaliador();
        $this->recomendar($ana)->assertCreated();
        $this->recomendar($bruno)->assertCreated();
        $this->recomendar($ana, $this->outroFinalista)->assertCreated();
        RecomendacaoPremiacao::where('projeto_id', $this->finalista->id)->update(['status' => 'aprovada']);
        RecomendacaoPremiacao::where('projeto_id', $this->outroFinalista->id)->update(['status' => 'rejeitada']);
        $this->adminDe('comite');

        $cartao = $this->getJson('/api/v1/admin/comite/premiacao')->assertOk()->json('data.0');

        // Só a aprovada, uma linha por projeto com os dois nomes.
        $this->assertCount(1, $cartao['aprovadas']);
        $this->assertSame('Bioplástico de mandioca', $cartao['aprovadas'][0]['projeto']);
        $this->assertEqualsCanonicalizing([$ana->name, $bruno->name], $cartao['aprovadas'][0]['avaliadores']);
        $this->assertFalse($cartao['aprovadas'][0]['ja_premiado']);
        $this->assertSame(1, $cartao['aprovadas_pendentes']);

        // Premiar pelo cartão é a atribuição de sempre; a linha passa a dizer que já foi.
        $this->postJson("/api/v1/admin/comite/premiacao/{$this->credencial->id}/projetos", ['projeto_id' => $this->finalista->id])
            ->assertOk()
            ->assertJsonPath('data.0.aprovadas.0.ja_premiado', true)
            ->assertJsonPath('data.0.aprovadas_pendentes', 0);

        // A mesma informação na Avaliação presencial.
        $this->adminDe('avaliacao_presencial');
        $this->getJson('/api/v1/admin/presencial/credenciais')
            ->assertJsonPath('data.0.aprovadas.0.projeto', 'Bioplástico de mandioca');
    }

    public function test_rejeita_e_pode_reconsiderar(): void
    {
        $this->recomendar($this->avaliador())->assertCreated();
        $recomendacao = RecomendacaoPremiacao::firstOrFail();
        $this->adminDe('avaliacao_presencial');

        $this->postJson("/api/v1/admin/premiacao/recomendacoes/{$recomendacao->id}/decisao", ['status' => 'rejeitada'])->assertOk();
        $this->assertSame(StatusRecomendacao::Rejeitada, $recomendacao->refresh()->status);

        $this->postJson("/api/v1/admin/premiacao/recomendacoes/{$recomendacao->id}/decisao", ['status' => 'aprovada'])->assertOk();
        $this->assertSame(StatusRecomendacao::Aprovada, $recomendacao->refresh()->status);

        $this->postJson("/api/v1/admin/premiacao/recomendacoes/{$recomendacao->id}/decisao", ['status' => 'pendente'])
            ->assertStatus(422);
    }

    public function test_aponta_quando_o_projeto_ja_levou_a_credencial(): void
    {
        $this->recomendar($this->avaliador())->assertCreated();
        RecomendacaoPremiacao::firstOrFail()->update(['status' => 'aprovada']);
        $this->credencial->projetos()->attach($this->finalista->id, ['atribuida_em' => now()]);
        $this->adminDe('comite');

        $this->getJson('/api/v1/admin/comite/premiacao')
            ->assertJsonPath('data.0.aprovadas.0.ja_premiado', true);
    }

    /**
     * Sprint 202 — cancelar a premiação de uma aprovada é tirar a credencial
     * do projeto: a vaga volta e a recomendação continua aprovada.
     */
    public function test_cancelar_a_premiacao_tira_a_credencial_e_a_recomendacao_continua_aprovada(): void
    {
        $this->recomendar($this->avaliador())->assertCreated();
        $recomendacao = RecomendacaoPremiacao::firstOrFail();
        $recomendacao->update(['status' => 'aprovada']);
        $this->adminDe('comite');
        $this->postJson("/api/v1/admin/comite/premiacao/{$this->credencial->id}/projetos", ['projeto_id' => $this->finalista->id])
            ->assertOk()->assertJsonPath('data.0.disponiveis', 0);

        $this->deleteJson("/api/v1/admin/comite/premiacao/{$this->credencial->id}/projetos/{$this->finalista->id}")
            ->assertOk()
            ->assertJsonPath('data.0.disponiveis', 1)
            ->assertJsonPath('data.0.aprovadas.0.ja_premiado', false);

        $this->assertSame(StatusRecomendacao::Aprovada, $recomendacao->refresh()->status);
        $this->assertSame(0, $this->credencial->projetos()->count());
    }

    /** Sprint 201 — a rejeitada vai para o próprio grupo do cartão e pode ser reconsiderada. */
    public function test_rejeitada_fica_no_proprio_grupo_do_cartao(): void
    {
        $this->recomendar($this->avaliador())->assertCreated();
        $recomendacao = RecomendacaoPremiacao::firstOrFail();
        $this->adminDe('avaliacao_presencial');

        $this->postJson("/api/v1/admin/premiacao/recomendacoes/{$recomendacao->id}/decisao", ['status' => 'rejeitada'])->assertOk();

        $cartao = $this->getJson('/api/v1/admin/presencial/credenciais')->assertOk()->json('data.0');
        $this->assertSame([], $cartao['pendentes']);
        $this->assertSame($recomendacao->id, $cartao['rejeitadas'][0]['id']);
        $this->assertNotNull($cartao['rejeitadas'][0]['decidida_por']);
    }

    public function test_avaliador_ve_a_decisao_na_propria_aba(): void
    {
        $ana = $this->avaliador();
        $this->recomendar($ana)->assertCreated();
        RecomendacaoPremiacao::firstOrFail()->update(['status' => StatusRecomendacao::Aprovada]);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/avaliador/comite')
            ->assertJsonPath('data.recomendacoes.0.status', 'aprovada')
            ->assertJsonPath('data.recomendacoes.0.status_label', 'Aprovada');
    }

    public function test_quem_nao_tem_as_abas_da_premiacao_nao_decide(): void
    {
        $this->recomendar($this->avaliador())->assertCreated();
        $recomendacao = RecomendacaoPremiacao::firstOrFail();
        $this->adminDe('projetos');

        $this->getJson('/api/v1/admin/comite/premiacao')->assertForbidden();
        $this->postJson("/api/v1/admin/premiacao/recomendacoes/{$recomendacao->id}/decisao", ['status' => 'aprovada'])
            ->assertForbidden();
    }

    /** Sprint 203 — os finalistas vêm com o código da lista final, para buscar por ele. */
    public function test_os_finalistas_vem_com_o_codigo_da_lista_final(): void
    {
        $this->finalista->update(['categoria' => Categoria::Fetecms, 'area_id' => Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR'])->id]);
        $this->adminDe('comite');

        $candidatos = collect($this->getJson('/api/v1/admin/comite/premiacao')->json('meta.candidatos'))->keyBy('titulo');
        $this->assertSame('FET.AGR-001', $candidatos['Bioplástico de mandioca']['codigo']);

        Sanctum::actingAs($this->avaliador());
        $doAvaliador = collect($this->getJson('/api/v1/avaliador/comite')->json('data.candidatos'))->keyBy('titulo');
        $this->assertSame('FET.AGR-001', $doAvaliador['Bioplástico de mandioca']['codigo']);
    }
}
