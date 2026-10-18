<?php

namespace Tests\Unit;

use App\Support\RubricaPresencial;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 219 — a rubrica presencial do documento "Perguntas de Avaliação
 * FETECMS 2026 · Etapa Presencial": blocos, pesos e as três regras que não
 * cabem numa soma simples (condicionais, "não se aplica" e sem valor).
 */
class RubricaPresencialTest extends TestCase
{
    /**
     * Respostas no máximo: escala em 10 e Sim em tudo, o que também abre todas
     * as condicionais.
     *
     * @param  array<string, int|string>  $troca
     * @return array<string, int|string>
     */
    private function cheias(int $ponto = 10, array $troca = []): array
    {
        $respostas = [];

        foreach (RubricaPresencial::perguntas() as $p) {
            $respostas[$p['chave']] = $p['tipo'] === RubricaPresencial::TIPO_ESCALA ? $ponto : RubricaPresencial::SIM;
        }

        return [...$respostas, ...$troca];
    }

    public function test_os_pesos_fecham_10_com_8_5_de_avaliacao_e_1_5_de_documentos(): void
    {
        $this->assertSame(10.0, round(array_sum(array_column(RubricaPresencial::perguntas(), 'peso')), 4));
        $this->assertSame(8.5, RubricaPresencial::maximoDoBloco('avaliacao'));
        $this->assertSame(1.5, RubricaPresencial::maximoDoBloco('documentos'));
        $this->assertSame(1.82, RubricaPresencial::maximoDaSecao('metodologia'));
        $this->assertSame(0.418, RubricaPresencial::maximoDaSecao('pasta_referencia'));
        $this->assertSame(0.41, RubricaPresencial::maximoDaSecao('projeto_pesquisa'));
        $this->assertSame(10.0, RubricaPresencial::nota($this->cheias()));
    }

    public function test_cada_ponto_da_escala_vale_20_por_cento_do_peso(): void
    {
        // O exemplo do documento: a 6.1 (1,137) respondida "Muito ruim".
        $this->assertSame(0.2274, RubricaPresencial::nota(['apresentacao' => 2]));
        $this->assertSame(0.9096, RubricaPresencial::pontosDaSecao('apresentacao', ['apresentacao' => 8]));

        // O rótulo do zero é o de cada pergunta.
        $opcoes = fn (string $chave) => RubricaPresencial::opcoes(
            collect(RubricaPresencial::perguntas())->firstWhere('chave', $chave),
        )[0]['rotulo'];
        $this->assertSame('Não apresentou', $opcoes('apresentacao'));
        $this->assertSame('Não demonstra', $opcoes('diario_registro'));
        $this->assertSame('Não possui', $opcoes('objetivos'));
    }

    public function test_condicionais_sem_o_sim_valem_zero_e_saem_das_respostas(): void
    {
        // Sem diário manuscrito e sem a pasta: perde 0,263 + 0,301 e 0,239 + 0,049 + 0,029.
        $respostas = $this->cheias(troca: ['diario_manuscrito' => 'nao', 'pasta_referencias' => 'nao']);

        $this->assertSame(9.119, RubricaPresencial::nota($respostas));
        $limpas = RubricaPresencial::normalizar($respostas);
        $this->assertArrayNotHasKey('diario_registro', $limpas);
        $this->assertArrayNotHasKey('referencias_demais', $limpas);
        $this->assertArrayNotHasKey('referencias_destacadas', $limpas);
        $this->assertSame([], RubricaPresencial::pendentes($respostas));
    }

    public function test_quem_nao_usou_ia_ganha_a_14_2_como_sim(): void
    {
        $respostas = $this->cheias(troca: ['uso_ia' => 'nao']);
        unset($respostas['uso_ia_declarado']);

        $this->assertSame(10.0, RubricaPresencial::nota($respostas));
        $this->assertSame([], RubricaPresencial::pendentes($respostas));

        // Usou e não declarou: só aí perde o 0,157.
        $this->assertSame(9.843, RubricaPresencial::nota($this->cheias(troca: ['uso_ia_declarado' => 'nao'])));
    }

    public function test_com_qr_code_a_4_3_nao_se_aplica_e_vale_como_sim(): void
    {
        $respostas = $this->cheias(troca: ['referencias_destacadas' => RubricaPresencial::NAO_SE_APLICA]);
        $this->assertSame(10.0, RubricaPresencial::nota($respostas));

        // A terceira opção só existe na 4.3.
        $limpas = RubricaPresencial::normalizar(['projeto_impresso' => RubricaPresencial::NAO_SE_APLICA]);
        $this->assertArrayNotHasKey('projeto_impresso', $limpas);
    }

    public function test_sem_o_projeto_impresso_a_2_2_nao_aparece_e_vale_zero(): void
    {
        // Sprint 221: sem o projeto impresso não há o que conferir na ABNT —
        // perde 0,283 da 2.1 e os 0,127 da 2.2.
        $respostas = $this->cheias(troca: ['projeto_impresso' => 'nao']);

        $this->assertSame(9.59, RubricaPresencial::nota($respostas));
        $this->assertArrayNotHasKey('projeto_abnt', RubricaPresencial::normalizar($respostas));
        $this->assertSame([], RubricaPresencial::pendentes($respostas));
        $this->assertContains('projeto_abnt', RubricaPresencial::pendentes(['projeto_impresso' => 'sim']));
    }

    public function test_a_secao_de_documentos_de_etica_saiu_da_rubrica(): void
    {
        // Sprint 221: TCE e TCLE/TALE (1.1 e 1.2) não aparecem mais; a resposta
        // antiga é descartada e a rubrica começa no projeto de pesquisa.
        $this->assertSame('projeto_pesquisa', RubricaPresencial::SECOES[0]['chave']);
        $this->assertNotContains('etica_necessaria', RubricaPresencial::chaves());
        $this->assertArrayNotHasKey('etica_documento', RubricaPresencial::normalizar(['etica_documento' => 'nao']));
    }

    public function test_pendentes_cobra_so_o_que_se_aplica(): void
    {
        $pendentes = RubricaPresencial::pendentes([]);

        // Sem resposta de origem, as condicionais ainda não aparecem.
        $this->assertContains('projeto_impresso', $pendentes);
        $this->assertNotContains('projeto_abnt', $pendentes);
        $this->assertContains('uso_ia', $pendentes);
        $this->assertNotContains('diario_registro', $pendentes);
        $this->assertNotContains('uso_ia_declarado', $pendentes);

        $this->assertContains('diario_registro', RubricaPresencial::pendentes(['diario_manuscrito' => 'sim']));
    }

    public function test_aceita_booleano_no_sim_nao_e_descarta_o_que_nao_e_da_rubrica(): void
    {
        $limpas = RubricaPresencial::normalizar([
            'projeto_impresso' => true, 'projeto_abnt' => false, 'apresentacao' => 7, 'objetivos' => '8', 'clareza' => 10,
        ]);

        $this->assertSame(['projeto_impresso' => 'sim', 'projeto_abnt' => 'nao', 'objetivos' => 8], $limpas);
    }

    public function test_reconhece_respostas_da_rubrica_anterior(): void
    {
        $this->assertTrue(RubricaPresencial::deOutraRubrica(['clareza' => 10, 'participacao' => 8]));
        $this->assertFalse(RubricaPresencial::deOutraRubrica(['apresentacao' => 10]));
        $this->assertFalse(RubricaPresencial::deOutraRubrica([]));
        $this->assertFalse(RubricaPresencial::deOutraRubrica(null));
    }
}
