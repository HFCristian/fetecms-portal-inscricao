<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\StatusPresenca;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 222 — o ranking presencial: a média de cada finalista, com a
 * avaliação da comissão especial pesando mais (padrão 2). Sprint 237 — Ver
 * avaliações (quem avaliou, seção por seção) e a exclusão com justificativa.
 */
class RankingPresencialTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    private Area $agr;

    private Area $bio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->bio = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function finalista(string $titulo, ?Area $area = null, ?ListaFinal $lista = null, int $estande = 1): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'area_id' => ($area ?? $this->agr)->id,
        ]);
        ($lista ?? $this->lista)->projetos()->attach($projeto->id);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => 'A', 'numero' => $estande]);

        return $projeto;
    }

    private function avaliacao(Projeto $projeto, float $nota, string $vaga = 'comum', string $status = 'concluida', ?string $nome = null, array $respostas = []): AvaliacaoPresencial
    {
        $avaliador = User::factory()->create(['role' => Role::Avaliador->value, ...($nome ? ['name' => $nome] : [])]);
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'presencial' => true, 'comissao_especial' => $vaga === 'comite']);

        return AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::from($status), 'nota' => $status === 'concluida' ? $nota : null,
            'vaga' => $vaga, 'concluida_em' => $status === 'concluida' ? now() : null,
            'respostas' => $respostas,
        ]);
    }

    public function test_a_avaliacao_da_comissao_pesa_dois_na_media(): void
    {
        $abelhas = $this->finalista('Abelhas');
        $this->avaliacao($abelhas, 8);
        $this->avaliacao($abelhas, 6);
        $this->avaliacao($abelhas, 9, 'comite');

        // (8 + 6 + 9 × 2) / 4 = 8 — a média simples seria 7,667.
        $this->getJson('/api/v1/admin/presencial/ranking')
            ->assertOk()
            ->assertJsonPath('data.vagas.peso_comite', 2)
            ->assertJsonPath('data.projetos.0.titulo', 'Abelhas')
            ->assertJsonPath('data.projetos.0.media', 8)
            ->assertJsonPath('data.projetos.0.comuns', 2)
            ->assertJsonPath('data.projetos.0.comite', 1)
            ->assertJsonPath('data.projetos.0.parcial', false)
            ->assertJsonPath('data.projetos.0.estande', 1);
    }

    public function test_o_peso_e_o_da_edicao_e_o_da_vaga_gravada(): void
    {
        $this->edicao->update(['presencial_peso_comite' => 3]);
        $abelhas = $this->finalista('Abelhas');
        $this->avaliacao($abelhas, 6);
        $this->avaliacao($abelhas, 10, 'comite');
        // Da comissão, mas na vaga comum (a dela estava tomada): pesa 1.
        $extra = $this->avaliacao($abelhas, 6);
        $extra->avaliador->avaliadorProfile->update(['comissao_especial' => true]);

        // (6 + 10 × 3 + 6) / 5 = 8,4.
        $this->getJson('/api/v1/admin/presencial/ranking')
            ->assertJsonPath('data.projetos.0.media', 8.4)
            ->assertJsonPath('data.projetos.0.comuns', 2);
    }

    public function test_ordem_empate_parcial_e_quem_fica_de_fora(): void
    {
        $abelhas = $this->finalista('Abelhas', estande: 1);
        $bioplastico = $this->finalista('Bioplástico', $this->bio, estande: 2);
        $compostagem = $this->finalista('Compostagem', estande: 3);
        $semNota = $this->finalista('Drone', estande: 4);
        foreach ([$abelhas, $bioplastico] as $p) {
            $this->avaliacao($p, 7);
            $this->avaliacao($p, 7);
            $this->avaliacao($p, 7, 'comite');
        }
        $this->avaliacao($compostagem, 9);
        $this->avaliacao($semNota, 0, status: 'em_andamento');
        // Fora da lista final: não entra.
        $fora = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        $this->avaliacao($fora, 10);

        $projetos = $this->getJson('/api/v1/admin/presencial/ranking')->assertOk()->json('data.projetos');

        $this->assertSame(['Compostagem', 'Abelhas', 'Bioplástico'], array_column($projetos, 'titulo'));
        $this->assertSame([1, 2, 2], array_column($projetos, 'posicao'));
        // Uma comum de duas e nenhuma da comissão: a média ainda pode mudar.
        $this->assertTrue($projetos[0]['parcial']);
        $this->assertFalse($projetos[1]['parcial']);

        $this->getJson("/api/v1/admin/presencial/ranking?area_id={$this->bio->id}")
            ->assertJsonCount(1, 'data.projetos')
            ->assertJsonPath('data.projetos.0.titulo', 'Bioplástico')
            ->assertJsonPath('data.projetos.0.posicao', 1);
    }

    public function test_modo_de_teste_le_a_lista_de_demonstracao(): void
    {
        $this->avaliacao($this->finalista('De verdade'), 9);
        $demo = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Demo', 'tipo' => 'final', 'vigente' => true, 'demo' => true, 'versao' => 1,
        ]);
        $this->avaliacao($this->finalista('Do ensaio', lista: $demo, estande: 901), 8);
        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $this->getJson('/api/v1/admin/presencial/ranking?teste=1')
            ->assertJsonPath('data.modo_teste', true)
            ->assertJsonCount(1, 'data.projetos')
            ->assertJsonPath('data.projetos.0.titulo', 'Do ensaio');

        $this->getJson('/api/v1/admin/presencial/ranking')
            ->assertJsonPath('data.projetos.0.titulo', 'De verdade');
    }

    public function test_sem_a_aba_o_ranking_e_negado(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Avaliador->value]));

        $this->getJson('/api/v1/admin/presencial/ranking')->assertForbidden();
    }

    // --- Ver avaliações e exclusão (Sprint 237) ------------------------------

    public function test_ver_avaliacoes_mostra_quem_avaliou_e_os_pontos_de_cada_secao(): void
    {
        $abelhas = $this->finalista('Abelhas', estande: 12);
        $this->avaliacao($abelhas, 8, nome: 'Ana Souza', respostas: ['apresentacao' => 10, 'projeto_impresso' => 'sim']);
        $this->avaliacao($abelhas, 9, 'comite', nome: 'Bruno Lima');
        $this->avaliacao($abelhas, 0, status: 'em_andamento', nome: 'Carla Dias', respostas: ['apresentacao' => 4]);
        // Só designada: sai pela Distribuição presencial, não entra aqui.
        $this->avaliacao($abelhas, 0, status: 'designada', nome: 'Davi Rocha');

        $dados = $this->postJson("/api/v1/admin/presencial/ranking/projetos/{$abelhas->id}/avaliacoes")
            ->assertOk()
            ->assertJsonPath('data.projeto.titulo', 'Abelhas')
            ->assertJsonPath('data.projeto.estande', 12)
            // (8 + 9 × 2) / 3 — a mesma média do ranking.
            ->assertJsonPath('data.media', 8.6667)
            ->json('data');

        $this->assertSame(['Ana Souza', 'Bruno Lima', 'Carla Dias'], array_column($dados['avaliacoes'], 'avaliador'));
        [$ana, $bruno, $carla] = $dados['avaliacoes'];
        $this->assertSame(1.137, $ana['secoes']['apresentacao']);
        $this->assertEquals(0.283, $ana['secoes']['projeto_pesquisa']);
        $this->assertSame('comite', $bruno['vaga']);
        $this->assertEquals(2, $bruno['peso']);
        // O rascunho não tem nota nem pontos: não se compara com o que foi enviado.
        $this->assertNull($carla['nota']);
        $this->assertNull($carla['secoes']);
        $this->assertContains('apresentacao', array_column($dados['secoes'], 'chave'));

        // Uma consulta por nota que estava na tela, marcada como presencial.
        $registros = RegistroAtividade::where('tipo', TipoRegistro::NotasVisualizadas)->get();
        $this->assertCount(2, $registros);
        $this->assertSame('presencial', $registros[0]->detalhes['fase']);
        $this->assertSame('8,000', $registros[0]->detalhes['nota']);
    }

    public function test_ver_avaliacoes_de_quem_nao_e_finalista_e_404(): void
    {
        $fora = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        $this->avaliacao($fora, 8);

        $this->postJson("/api/v1/admin/presencial/ranking/projetos/{$fora->id}/avaliacoes")->assertNotFound();
    }

    public function test_excluir_apaga_a_avaliacao_registra_o_motivo_e_recalcula_o_ranking(): void
    {
        $abelhas = $this->finalista('Abelhas');
        $boa = $this->avaliacao($abelhas, 8, nome: 'Ana Souza');
        $ruim = $this->avaliacao($abelhas, 2, 'comite', nome: 'Bruno Lima');

        $this->postJson("/api/v1/admin/presencial/ranking/avaliacoes/{$ruim->id}/excluir", ['justificativa' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->postJson("/api/v1/admin/presencial/ranking/avaliacoes/{$ruim->id}/excluir", [
            'justificativa' => 'Avaliou o estande do lado.',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.avaliacoes')
            ->assertJsonPath('data.avaliacoes.0.avaliacao_id', $boa->id)
            ->assertJsonPath('data.media', 8);

        $this->assertModelMissing($ruim);
        $registro = RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoPresencialExcluida)->sole();
        $this->assertSame('Bruno Lima', $registro->detalhes['avaliador']);
        $this->assertSame('2,000', $registro->detalhes['nota']);
        $this->assertSame('Comissão especial', $registro->detalhes['vaga']);
        $this->assertSame('Avaliou o estande do lado.', $registro->detalhes['justificativa']);
        // A tela devolvida depois de excluir não conta como consulta.
        $this->assertSame(0, RegistroAtividade::where('tipo', TipoRegistro::NotasVisualizadas)->count());

        // A vaga da comissão volta a abrir: o projeto fica parcial de novo.
        $this->getJson('/api/v1/admin/presencial/ranking')
            ->assertJsonPath('data.projetos.0.media', 8)
            ->assertJsonPath('data.projetos.0.comite', 0)
            ->assertJsonPath('data.projetos.0.parcial', true);
    }

    public function test_excluir_vale_para_a_em_andamento_mas_nao_para_a_so_designada(): void
    {
        $abelhas = $this->finalista('Abelhas');
        $aberta = $this->avaliacao($abelhas, 0, status: 'em_andamento');
        $designada = $this->avaliacao($abelhas, 0, status: 'designada');

        $this->postJson("/api/v1/admin/presencial/ranking/avaliacoes/{$aberta->id}/excluir", ['justificativa' => 'Saiu do evento.'])
            ->assertOk();
        $this->assertModelMissing($aberta);
        $this->assertNull(RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoPresencialExcluida)->sole()->detalhes['nota']);

        $this->postJson("/api/v1/admin/presencial/ranking/avaliacoes/{$designada->id}/excluir", ['justificativa' => 'Não precisa mais.'])
            ->assertStatus(422)->assertJsonValidationErrors('avaliacao');
        $this->assertModelExists($designada);
    }

    public function test_o_modo_de_teste_nao_alcanca_o_finalista_de_verdade(): void
    {
        $real = $this->avaliacao($this->finalista('De verdade'), 9);
        ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Demo', 'tipo' => 'final', 'vigente' => true, 'demo' => true, 'versao' => 1,
        ]);
        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $this->postJson("/api/v1/admin/presencial/ranking/avaliacoes/{$real->id}/excluir", [
            'justificativa' => 'Ensaio do balcão.', 'teste' => 1,
        ])->assertNotFound();
        $this->assertModelExists($real);
    }

    public function test_a_conta_temporaria_do_voluntario_nao_ve_nem_exclui(): void
    {
        $abelhas = $this->finalista('Abelhas');
        $avaliacao = $this->avaliacao($abelhas, 8);
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Vera Voluntária', 'email' => 'vera@fetec.test', 'password' => 'senha-do-turno',
            'cpf' => '52998224725', 'curso' => 'Biologia', 'horas' => 5,
        ], null, 'avaliacao_presencial');
        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();
        Sanctum::actingAs($conta->user);

        // A aba abre para ela (o ranking sem nomes), mas o detalhe não.
        $this->getJson('/api/v1/admin/presencial/ranking')->assertOk();
        $this->postJson("/api/v1/admin/presencial/ranking/projetos/{$abelhas->id}/avaliacoes")->assertForbidden();
        $this->postJson("/api/v1/admin/presencial/ranking/avaliacoes/{$avaliacao->id}/excluir", ['justificativa' => 'Teste.'])
            ->assertForbidden();
        $this->assertModelExists($avaliacao);
    }
}
