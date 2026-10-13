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

    public function test_admin_lista_conta_e_aprova_sem_anexar_a_credencial(): void
    {
        $ana = $this->avaliador();
        $this->recomendar($ana)->assertCreated();
        $this->recomendar($ana, $this->outroFinalista)->assertCreated();
        $admin = $this->adminDe('comite');

        $dados = $this->getJson('/api/v1/admin/premiacao/recomendacoes')->assertOk()->json('data');
        $this->assertSame(['pendente' => 2, 'aprovada' => 0, 'rejeitada' => 0], $dados['contagem']);
        $this->assertSame($ana->name, $dados['recomendacoes'][0]['avaliador']);
        $this->assertSame('MOSTRATEC 2027', $dados['recomendacoes'][0]['credencial']);

        $recomendacao = RecomendacaoPremiacao::where('projeto_id', $this->finalista->id)->firstOrFail();
        $this->postJson("/api/v1/admin/premiacao/recomendacoes/{$recomendacao->id}/decisao", ['status' => 'aprovada'])
            ->assertOk()->assertJsonPath('meta.message', 'Recomendação aprovada.');

        $recomendacao->refresh();
        $this->assertSame(StatusRecomendacao::Aprovada, $recomendacao->status);
        $this->assertSame($admin->id, $recomendacao->decidida_por);
        $this->assertNotNull($recomendacao->decidida_em);
        // Aprovar só marca: a credencial continua sem projeto.
        $this->assertSame(0, $this->credencial->projetos()->count());

        $this->getJson('/api/v1/admin/premiacao/recomendacoes?status=aprovada')
            ->assertJsonCount(1, 'data.recomendacoes')
            ->assertJsonPath('data.contagem.aprovada', 1);
    }

    /** Sprint 194 — filtrar as indicações pelo projeto: título, área e categoria. */
    public function test_filtra_por_titulo_area_e_categoria_e_a_contagem_acompanha(): void
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

        $titulos = fn (string $query) => array_column(
            $this->getJson('/api/v1/admin/premiacao/recomendacoes'.$query)->assertOk()->json('data.recomendacoes'),
            'projeto',
        );

        $this->assertSame(['Bioplástico de mandioca'], $titulos('?q=MANDIOCA'));
        $this->assertSame(['Abelhas nativas'], $titulos('?area_id='.$biologicas->id));
        $this->assertSame(['Bioplástico de mandioca'], $titulos('?categoria=fetecms'));
        $this->assertSame([], $titulos('?categoria=fetecms_fundect'));
        $this->assertSame(['Abelhas nativas'], $titulos('?categoria=fetec_jr&status=aprovada'));

        $this->getJson('/api/v1/admin/premiacao/recomendacoes?area_id='.$agrarias->id)
            ->assertJsonPath('data.contagem', ['pendente' => 1, 'aprovada' => 0, 'rejeitada' => 0])
            ->assertJsonPath('data.opcoes.categorias.0.value', 'fetec_jr');

        $this->getJson('/api/v1/admin/premiacao/recomendacoes?categoria=pictec')
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
        $this->credencial->projetos()->attach($this->finalista->id, ['atribuida_em' => now()]);
        $this->adminDe('comite');

        $this->getJson('/api/v1/admin/premiacao/recomendacoes')
            ->assertJsonPath('data.recomendacoes.0.ja_premiado', true);
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

        $this->getJson('/api/v1/admin/premiacao/recomendacoes')->assertForbidden();
        $this->postJson("/api/v1/admin/premiacao/recomendacoes/{$recomendacao->id}/decisao", ['status' => 'aprovada'])
            ->assertForbidden();
    }
}
