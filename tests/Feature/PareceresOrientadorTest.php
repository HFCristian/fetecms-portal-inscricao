<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Rubrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Aba "Pareceres" do orientador: a nota média de cada projeto, as observações
 * dos avaliadores (anônimas) e as etapas da rubrica em níveis — nunca em notas.
 */
class PareceresOrientadorTest extends TestCase
{
    use RefreshDatabase;

    private User $orientador;

    private Projeto $projeto;

    protected function setUp(): void
    {
        parent::setUp();

        $area = Area::create(['nome' => 'Ciências Exatas e da Terra']);
        $this->orientador = User::factory()->create(['role' => Role::Orientador->value]);
        $this->projeto = Projeto::factory()->submetido()->create([
            'user_id' => $this->orientador->id,
            'titulo' => 'Bioplástico de mandioca',
            'area_id' => $area->id,
        ]);

        // A janela dos pareceres é a mesma dos ajustes.
        Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'inscricoes_abertas' => true,
            'ajustes_de' => now()->subDay(),
            'ajustes_ate' => now()->addDays(5),
        ]);
    }

    /**
     * Avaliação concluída com a rubrica respondida por igual — todas as
     * perguntas de escala no mesmo ponto, as de Sim/Não em "Sim".
     *
     * @param  array<string, mixed>  $over
     */
    private function avaliacao(int $ponto = 10, array $over = []): Avaliacao
    {
        $respostas = [];

        foreach (Rubrica::perguntas() as $pergunta) {
            $respostas[$pergunta['chave']] = $pergunta['tipo'] === Rubrica::TIPO_SIM_NAO ? true : $ponto;
        }

        return Avaliacao::create([
            'projeto_id' => $this->projeto->id,
            'avaliador_id' => User::factory()->create(['role' => Role::Avaliador->value, 'name' => 'Ana Souza'])->id,
            'status' => StatusAvaliacao::Concluida->value,
            'respostas' => $respostas,
            'nota' => Rubrica::nota($respostas),
            'concluida_em' => now(),
            ...$over,
        ]);
    }

    private function comoOrientador(): void
    {
        Sanctum::actingAs($this->orientador);
    }

    public function test_lista_os_projetos_submetidos_com_a_media(): void
    {
        $this->avaliacao(10);
        $this->avaliacao(8);
        $this->comoOrientador();

        $resposta = $this->getJson('/api/v1/pareceres')->assertOk();

        $resposta->assertJsonPath('data.janela.aberta', true)
            ->assertJsonCount(1, 'data.projetos')
            ->assertJsonPath('data.projetos.0.titulo', 'Bioplástico de mandioca')
            ->assertJsonPath('data.projetos.0.avaliacoes', 2);

        // A média das duas notas, com duas casas.
        $media = round((Rubrica::nota($this->respostas(10)) + Rubrica::nota($this->respostas(8))) / 2, 2);
        $this->assertEqualsWithDelta($media, $resposta->json('data.projetos.0.media'), 0.01);
    }

    /** @return array<string, mixed> */
    private function respostas(int $ponto): array
    {
        $respostas = [];

        foreach (Rubrica::perguntas() as $pergunta) {
            $respostas[$pergunta['chave']] = $pergunta['tipo'] === Rubrica::TIPO_SIM_NAO ? true : $ponto;
        }

        return $respostas;
    }

    public function test_secoes_saem_em_niveis_e_nunca_com_a_nota(): void
    {
        // Tudo no topo da escala → toda seção é ponto forte.
        $this->avaliacao(10);
        $this->comoOrientador();

        $resposta = $this->getJson("/api/v1/pareceres/projetos/{$this->projeto->id}")->assertOk();

        foreach ($resposta->json('data.secoes') as $secao) {
            $this->assertSame('forte', $secao['nivel'], "Seção {$secao['chave']} deveria ser ponto forte.");
            $this->assertSame('Ponto forte', $secao['nivel_label']);
            // O que não é enviado não vaza: a pontuação da seção não vai junto.
            $this->assertArrayNotHasKey('media', $secao);
            $this->assertArrayNotHasKey('pontos', $secao);
            $this->assertArrayNotHasKey('maximo', $secao);
        }
    }

    public function test_nota_baixa_vira_ponto_fraco_e_media_vira_ponto_medio(): void
    {
        // 2 de 10 na escala → 20% do peso de cada seção → abaixo de 4.
        $this->avaliacao(2);
        $this->comoOrientador();

        $niveis = collect($this->getJson("/api/v1/pareceres/projetos/{$this->projeto->id}")->json('data.secoes'))
            ->pluck('nivel')
            ->unique()
            ->all();

        // As perguntas de Sim/Não respondidas com "Sim" valem peso cheio, então
        // as seções que só têm delas continuam fortes: o que importa aqui é que
        // as de escala caíram para "fraco".
        $this->assertContains('fraco', $niveis);

        // 6 de 10 → 60% → entre 4 e 8.
        $this->projeto->avaliacoes()->delete();
        $this->avaliacao(6);

        $niveis = collect($this->getJson("/api/v1/pareceres/projetos/{$this->projeto->id}")->json('data.secoes'))
            ->pluck('nivel')
            ->all();

        $this->assertContains('medio', $niveis);
    }

    public function test_observacoes_sao_anonimas(): void
    {
        $this->avaliacao(10, [
            'comentario_video' => 'O vídeo poderia mostrar o experimento.',
            'comentario_projeto' => 'Faltou detalhar a metodologia.',
        ]);
        $this->comoOrientador();

        $resposta = $this->getJson("/api/v1/pareceres/projetos/{$this->projeto->id}")->assertOk();

        $resposta->assertJsonCount(2, 'data.recomendacoes')
            ->assertJsonPath('data.recomendacoes.0.avaliador', 'Avaliador 1')
            ->assertJsonPath('data.recomendacoes.0.texto', 'O vídeo poderia mostrar o experimento.')
            ->assertJsonPath('data.recomendacoes.1.avaliador', 'Avaliador 1');

        // O nome do avaliador não aparece em lugar nenhum do payload.
        $resposta->assertDontSee('Ana Souza');
    }

    public function test_avaliacao_sem_respostas_nao_vira_ponto_fraco(): void
    {
        // Avaliação anterior à rubrica atual: tem nota, não tem respostas.
        Avaliacao::create([
            'projeto_id' => $this->projeto->id,
            'avaliador_id' => User::factory()->create(['role' => Role::Avaliador->value])->id,
            'status' => StatusAvaliacao::Concluida->value,
            'nota' => 7.5,
            'concluida_em' => now(),
        ]);
        $this->comoOrientador();

        $resposta = $this->getJson("/api/v1/pareceres/projetos/{$this->projeto->id}")->assertOk();

        foreach ($resposta->json('data.secoes') as $secao) {
            $this->assertNull($secao['nivel']);
            $this->assertSame('Não avaliado', $secao['nivel_label']);
        }

        // A nota média continua existindo — ela não depende das respostas.
        $resposta->assertJsonPath('data.media', 7.5);
    }

    public function test_fora_da_janela_a_aba_abre_vazia_e_o_detalhe_e_barrado(): void
    {
        $this->avaliacao(10);
        Edicao::query()->update(['ajustes_de' => now()->addDays(3), 'ajustes_ate' => now()->addDays(10)]);
        $this->comoOrientador();

        $this->getJson('/api/v1/pareceres')
            ->assertOk()
            ->assertJsonPath('data.janela.aberta', false)
            ->assertJsonCount(0, 'data.projetos');

        $this->getJson("/api/v1/pareceres/projetos/{$this->projeto->id}")->assertForbidden();
    }

    public function test_orientador_demo_ignora_as_datas_em_modo_teste(): void
    {
        $this->avaliacao(10);
        Edicao::query()->update(['ajustes_de' => now()->addDays(3), 'ajustes_ate' => now()->addDays(10)]);
        $this->orientador->update(['is_demo' => true]);
        $this->comoOrientador();

        $this->getJson('/api/v1/pareceres?teste=1')
            ->assertOk()
            ->assertJsonPath('data.janela.aberta', true)
            ->assertJsonCount(1, 'data.projetos');

        $this->getJson("/api/v1/pareceres/projetos/{$this->projeto->id}?teste=1")->assertOk();
    }

    public function test_orientador_nao_ve_parecer_de_projeto_alheio(): void
    {
        $outro = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
        ]);
        $this->comoOrientador();

        $this->getJson("/api/v1/pareceres/projetos/{$outro->id}")->assertForbidden();
    }

    public function test_avaliador_nao_acessa_a_aba(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => Role::Avaliador->value]));

        $this->getJson('/api/v1/pareceres')->assertForbidden();
    }
}
