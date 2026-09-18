<?php

namespace Tests\Feature;

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
 * Sprint 140 — Verificar disparidade → **Identificação de padrões**.
 *
 * A outra ponta da disparidade: não o projeto cujas notas se afastaram, mas o
 * avaliador que as deu de um jeito que não parece avaliar.
 */
class PadroesAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::create(['nome' => 'Ciências Exatas', 'sigla' => 'EXA']);
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function admin(): User
    {
        Sanctum::actingAs($admin = User::factory()->admin()->create());

        return $admin;
    }

    private function projeto(string $titulo): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'area_id' => $this->area->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);
    }

    /**
     * Uma avaliação concluída. `$escala` vale para todas as perguntas de escala
     * quando `$variar` é falso; com ele, as respostas alternam, para a avaliação
     * não parecer uniforme sem querer.
     */
    private function avaliar(
        Projeto $projeto,
        User $avaliador,
        int $escala,
        bool $variar = true,
        ?int $minutos = null,
    ): Avaliacao {
        $i = 0;
        $respostas = Rubrica::normalizar(
            collect(Rubrica::perguntas())
                ->mapWithKeys(function (array $p) use ($escala, $variar, &$i) {
                    if ($p['tipo'] === Rubrica::TIPO_SIM_NAO) {
                        return [$p['chave'] => $escala >= 6];
                    }

                    // Alterna entre a nota pedida e a vizinha, mantendo a média
                    // perto dela sem repetir o mesmo número em tudo.
                    $valor = $variar && $i++ % 2 === 1 ? max(0, $escala - 2) : $escala;

                    return [$p['chave'] => $valor];
                })
                ->all()
        );

        return Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Concluida,
            'respostas' => $respostas,
            'nota' => Rubrica::nota($respostas),
            'iniciada_em' => $minutos === null ? null : now()->subMinutes($minutos),
            'concluida_em' => now(),
        ]);
    }

    private function avaliador(string $nome): User
    {
        return User::factory()->avaliador()->create(['name' => $nome]);
    }

    private function analisar(array $params = [])
    {
        return $this->getJson('/api/v1/admin/avaliacao/padroes?'.http_build_query($params));
    }

    /** Quem entrou na lista, por chave de padrão. */
    private function sinais(array $dados, string $avaliador): array
    {
        $linha = collect($dados['avaliadores'])->firstWhere('avaliador', $avaliador);

        return $linha === null ? [] : array_column($linha['padroes'], 'chave');
    }

    public function test_nota_maxima_em_todas_as_avaliacoes_vira_sinal(): void
    {
        $this->admin();
        $generoso = $this->avaliador('Generoso Silva');

        foreach (['A', 'B', 'C'] as $titulo) {
            $this->avaliar($this->projeto($titulo), $generoso, 10, variar: false);
        }

        $dados = $this->analisar()->assertOk()->json('data');

        $this->assertContains('notas_infladas', $this->sinais($dados, 'Generoso Silva'));
    }

    public function test_quem_fica_muito_abaixo_dos_colegas_nos_mesmos_projetos_vira_sinal(): void
    {
        $this->admin();
        $duro = $this->avaliador('Duro Lima');
        $colega = $this->avaliador('Colega Souza');

        foreach (['A', 'B', 'C'] as $titulo) {
            $projeto = $this->projeto($titulo);
            $this->avaliar($projeto, $duro, 2);
            $this->avaliar($projeto, $colega, 10);
        }

        $dados = $this->analisar()->assertOk()->json('data');

        $this->assertContains('fora_da_curva', $this->sinais($dados, 'Duro Lima'));
        // O colega dá notas altas, mas é ele que está na média dos projetos:
        // "fora da curva" é sobre a distância, não sobre o valor.
        $this->assertNotContains('fora_da_curva', $this->sinais($dados, 'Colega Souza'));
    }

    public function test_uma_nota_baixa_isolada_nao_acusa_ninguem(): void
    {
        $this->admin();
        $criterioso = $this->avaliador('Criterioso Dias');
        $colega = $this->avaliador('Colega Souza');

        // Só um projeto discordante: é o caso legítimo que motiva existirem
        // três avaliadores, e não pode virar acusação.
        $discordante = $this->projeto('A');
        $this->avaliar($discordante, $criterioso, 2);
        $this->avaliar($discordante, $colega, 10);

        foreach (['B', 'C'] as $titulo) {
            $projeto = $this->projeto($titulo);
            $this->avaliar($projeto, $criterioso, 8);
            $this->avaliar($projeto, $colega, 8);
        }

        $dados = $this->analisar()->assertOk()->json('data');

        $this->assertNotContains('fora_da_curva', $this->sinais($dados, 'Criterioso Dias'));
    }

    public function test_mesma_resposta_em_todas_as_perguntas_vira_sinal(): void
    {
        $this->admin();
        $automatico = $this->avaliador('Automático Reis');

        // Uniformes, mas em nota média: o sinal não pode depender de a nota ser
        // alta, senão só pegaria o caso do certificado fácil.
        foreach (['A', 'B', 'C'] as $titulo) {
            $this->avaliar($this->projeto($titulo), $automatico, 6, variar: false);
        }

        $sinais = $this->sinais($this->analisar()->assertOk()->json('data'), 'Automático Reis');

        $this->assertContains('respostas_repetidas', $sinais);
        $this->assertNotContains('notas_infladas', $sinais);
    }

    public function test_avaliacoes_enviadas_minutos_depois_de_abertas_viram_sinal(): void
    {
        $this->admin();
        $apressado = $this->avaliador('Apressado Costa');

        $this->avaliar($this->projeto('A'), $apressado, 7, minutos: 2);
        $this->avaliar($this->projeto('B'), $apressado, 5, minutos: 3);
        $this->avaliar($this->projeto('C'), $apressado, 6, minutos: 90);

        $dados = $this->analisar()->assertOk()->json('data');

        $this->assertContains('relampago', $this->sinais($dados, 'Apressado Costa'));

        // Com o limiar em 1 minuto ninguém mais é rápido demais.
        $outro = $this->analisar(['minutos_relampago' => 1])->assertOk()->json('data');
        $this->assertNotContains('relampago', $this->sinais($outro, 'Apressado Costa'));
    }

    public function test_avaliacao_sem_registro_de_abertura_nao_conta_como_relampago(): void
    {
        $this->admin();
        $antigo = $this->avaliador('Antigo Neves');

        // Concluídas antes de o portal marcar a abertura: duração desconhecida,
        // e desconhecida não é rápida.
        foreach (['A', 'B', 'C'] as $titulo) {
            $this->avaliar($this->projeto($titulo), $antigo, 7, minutos: null);
        }

        $sinais = $this->sinais($this->analisar()->assertOk()->json('data'), 'Antigo Neves');

        $this->assertNotContains('relampago', $sinais);
    }

    public function test_quem_tem_poucas_avaliacoes_fica_fora_da_analise(): void
    {
        $this->admin();
        $novato = $this->avaliador('Novato Alves');

        // Duas avaliações nota máxima: seria "notas infladas" se o mínimo não
        // o segurasse — com duas não há padrão.
        foreach (['A', 'B'] as $titulo) {
            $this->avaliar($this->projeto($titulo), $novato, 10, variar: false);
        }

        $dados = $this->analisar()->assertOk()->json('data');

        $this->assertSame(0, $dados['analisados']);
        $this->assertSame([], $this->sinais($dados, 'Novato Alves'));

        // Baixando o mínimo, ele aparece.
        $comMinimo2 = $this->analisar(['min_avaliacoes' => 2])->assertOk()->json('data');
        $this->assertContains('notas_infladas', $this->sinais($comMinimo2, 'Novato Alves'));
    }

    public function test_avaliador_dentro_do_esperado_nao_aparece(): void
    {
        $this->admin();
        $normal = $this->avaliador('Normal Pires');
        $colega = $this->avaliador('Colega Souza');

        foreach ([8, 6, 7] as $i => $escala) {
            $projeto = $this->projeto('P'.$i);
            $this->avaliar($projeto, $normal, $escala, minutos: 45);
            $this->avaliar($projeto, $colega, $escala, minutos: 50);
        }

        $dados = $this->analisar()->assertOk()->json('data');

        $this->assertSame([], $this->sinais($dados, 'Normal Pires'));
        $this->assertSame(0, $dados['total']);
        $this->assertSame(2, $dados['analisados']);
    }

    public function test_avaliador_demo_fica_de_fora(): void
    {
        $this->admin();
        $demo = $this->avaliador('Demo Teste');
        $demo->update(['is_demo' => true]);

        foreach (['A', 'B', 'C'] as $titulo) {
            $this->avaliar($this->projeto($titulo), $demo, 10, variar: false);
        }

        $dados = $this->analisar()->assertOk()->json('data');

        $this->assertSame(0, $dados['analisados']);
        $this->assertSame([], $dados['avaliadores']);
    }

    public function test_quem_nao_e_admin_nao_ve_a_analise(): void
    {
        Sanctum::actingAs(User::factory()->avaliador()->create());

        $this->analisar()->assertForbidden();
    }
}
