<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 222 — o ranking presencial: a média de cada finalista, com a
 * avaliação da comissão especial pesando mais (padrão 2).
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

    private function avaliacao(Projeto $projeto, float $nota, string $vaga = 'comum', string $status = 'concluida'): AvaliacaoPresencial
    {
        $avaliador = User::factory()->create(['role' => Role::Avaliador->value]);
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'presencial' => true, 'comissao_especial' => $vaga === 'comite']);

        return AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::from($status), 'nota' => $status === 'concluida' ? $nota : null,
            'vaga' => $vaga, 'concluida_em' => $status === 'concluida' ? now() : null,
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
}
