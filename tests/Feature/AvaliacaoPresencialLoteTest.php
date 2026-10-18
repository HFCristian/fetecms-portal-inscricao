<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorLiberacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\ChecagemEstande;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\LeitorPlanilha;
use App\Support\PlanilhaXlsx;
use App\Support\RubricaPresencial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 239 — a avaliação presencial em lote: o avaliador baixa a planilha
 * com os projetos designados, preenche e devolve; a prévia diz o que cada
 * linha vira, e o envio passa pelo mesmo fluxo do wizard.
 */
class AvaliacaoPresencialLoteTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $lista;

    private User $comite;

    private Projeto $abelhas;

    private Projeto $horta;

    private int $estande = 40;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 10, 20, 9, 0));

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => '2026-10-20 07:00', 'evento_ate' => '2026-10-21 18:00',
            'agenda_presencial' => self::agendaPresencial(['A' => ['inicio' => '08:00', 'fim' => '12:00'], 'B' => ['inicio' => '13:30', 'fim' => '17:30']], '2026-10-20', '2026-10-21'),
        ]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);

        $this->comite = $this->avaliador('Diana Comissão', comissao: true);
        $this->abelhas = $this->finalista('Abelhas nativas');
        $this->horta = $this->finalista('Horta vertical', 'B');

        // Um designado no estande (distribuição) e um pelo comitê, online.
        AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $this->abelhas->id, 'avaliador_id' => $this->comite->id,
            'status' => StatusAvaliacao::Designada, 'dia' => '2026-10-20', 'turno' => 'A', 'vaga' => 'comite',
        ]);
        Avaliacao::create([
            'projeto_id' => $this->horta->id, 'avaliador_id' => $this->comite->id,
            'status' => StatusAvaliacao::Designada, 'designacao_manual' => true, 'designada_pelo_comite' => true,
        ]);
    }

    private function avaliador(string $nome, bool $comissao = false): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'presencial' => true, 'comissao_especial' => $comissao]);
        AvaliadorLiberacaoPresencial::create(['edicao_id' => $this->edicao->id, 'user_id' => $user->id]);

        return $user;
    }

    private function finalista(string $titulo, string $turno = 'A'): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['role' => Role::Orientador->value])->id,
            'edicao_id' => $this->edicao->id,
            'titulo' => $titulo,
        ]);
        $this->lista->projetos()->attach($projeto->id);
        TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno]);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno, 'numero' => ++$this->estande]);
        Credenciamento::create(['projeto_id' => $projeto->id, 'lista_final_id' => $this->lista->id, 'finalizado_em' => now()]);
        ChecagemEstande::create(['projeto_id' => $projeto->id, 'lista_final_id' => $this->lista->id, 'verificado_em' => now()]);

        return $projeto;
    }

    /** @return list<list<string>> o modelo baixado, lido como a planilha que volta */
    private function modelo(): array
    {
        $bytes = $this->get('/api/v1/avaliador/presencial/lote/modelo')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="avaliacoes-presenciais.xlsx"')
            ->getContent();
        $caminho = tempnam(sys_get_temp_dir(), 'lote').'.xlsx';
        file_put_contents($caminho, $bytes);

        return LeitorPlanilha::ler($caminho, 'xlsx');
    }

    /** Todas as perguntas: 8 na escala e S no resto (o que abre as condicionais). */
    private function cheia(): array
    {
        return array_map(
            fn (array $p) => $p['tipo'] === RubricaPresencial::TIPO_ESCALA ? '8' : 'S',
            RubricaPresencial::perguntas(),
        );
    }

    /**
     * @param  list<list<string|int|null>>  $linhas  Turno, Stand, Código, Projeto, as respostas e o parecer
     * @param  list<string>|null  $cabecalho
     */
    private function planilha(array $linhas, ?array $cabecalho = null): UploadedFile
    {
        $cabecalho ??= $this->modelo()[0];

        return UploadedFile::fake()->createWithContent('avaliacoes.xlsx', PlanilhaXlsx::gerar('Avaliações', $cabecalho, $linhas));
    }

    private function previa(UploadedFile $arquivo): TestResponse
    {
        return $this->post('/api/v1/avaliador/presencial/lote/previa', ['arquivo' => $arquivo], ['Accept' => 'application/json']);
    }

    public function test_o_modelo_traz_os_designados_e_todas_as_perguntas(): void
    {
        Sanctum::actingAs($this->comite);
        $linhas = $this->modelo();

        [$turno, $stand, $codigo, $projeto] = array_slice($linhas[0], 0, 4);
        $this->assertSame(['Turno', 'Stand', 'Código', 'Projeto'], [$turno, $stand, $codigo, $projeto]);
        $this->assertSame('2.1 A equipe detém o projeto de pesquisa impresso? (S/N)', $linhas[0][4]);
        $this->assertContains('2.2 [só se 2.1 = S] O projeto de pesquisa está adequado conforme as normas da ABNT? (S/N)', $linhas[0]);
        $this->assertTrue(collect($linhas[0])->contains(fn ($t) => str_starts_with($t, '4.3 ') && str_ends_with($t, '(S/N/NA)')));
        $this->assertTrue(collect($linhas[0])->contains(fn ($t) => str_starts_with($t, '6.1 ') && str_ends_with($t, '(0-2-10)')));
        $this->assertSame('Parecer para a equipe (opcional)', end($linhas[0]));
        $this->assertCount(4 + count(RubricaPresencial::perguntas()) + 1, $linhas[0]);

        // Os dois designados, pelo estande.
        $this->assertSame(['41', '42'], array_column(array_slice($linhas, 1), 1));
        $this->assertSame(['Abelhas nativas', 'Horta vertical'], array_column(array_slice($linhas, 1), 3));
        $this->assertSame('Turno B (vespertino)', $linhas[2][0]);
    }

    public function test_o_rascunho_ja_salvo_vem_preenchido(): void
    {
        AvaliacaoPresencial::where('projeto_id', $this->abelhas->id)->update([
            'status' => StatusAvaliacao::EmAndamento->value, 'respostas' => json_encode(['projeto_impresso' => 'nao', 'apresentacao' => 6]),
            'comentario' => 'Ótima apresentação.',
        ]);
        Sanctum::actingAs($this->comite);

        $linha = $this->modelo()[1];
        $cabecalho = $this->modelo()[0];
        $coluna = fn (string $numero) => collect($cabecalho)->search(fn ($t) => str_starts_with($t, $numero.' '));

        $this->assertSame('N', $linha[$coluna('2.1')]);
        $this->assertSame('6', $linha[$coluna('6.1')]);
        $this->assertSame('Ótima apresentação.', end($linha));
    }

    public function test_previa_e_envio_de_varios_projetos_de_uma_vez(): void
    {
        Sanctum::actingAs($this->comite);
        $arquivo = $this->planilha([
            ['A', '041', '', 'Abelhas nativas', ...$this->cheia(), 'Muito bom.'],
            ['Turno B', '42', '', 'Horta vertical', ...$this->cheia(), ''],
        ]);

        $previa = $this->previa($arquivo)
            ->assertOk()
            ->assertJsonPath('data.resumo.validas', 2)
            ->assertJsonPath('data.resumo.com_erro', 0)
            ->assertJsonPath('data.envio.aberto', true)
            ->assertJsonPath('data.linhas.0.projeto_id', $this->abelhas->id)
            ->assertJsonPath('data.linhas.0.comentario', 'Muito bom.')
            ->assertJsonPath('data.linhas.1.projeto_id', $this->horta->id)
            ->json('data.linhas');
        // "Bom" na escala e Sim no resto: 80% dos 8,500 mais 1,500.
        $this->assertEquals(8.3, $previa[0]['nota']);
        // Nada foi gravado na prévia.
        $this->assertSame(0, AvaliacaoPresencial::where('status', 'concluida')->count());

        $this->postJson('/api/v1/avaliador/presencial/lote', ['linhas' => array_map(fn ($l) => [
            'linha' => $l['linha'], 'projeto_id' => $l['projeto_id'], 'respostas' => $l['respostas'], 'comentario' => $l['comentario'],
        ], $previa)])
            ->assertOk()
            ->assertJsonPath('data.resumo.enviadas', 2)
            ->assertJsonPath('meta.message', '2 avaliação(ões) enviada(s).');

        $this->assertSame(2, AvaliacaoPresencial::where('avaliador_id', $this->comite->id)->where('status', 'concluida')->count());
        $this->assertSame('Muito bom.', AvaliacaoPresencial::where('projeto_id', $this->abelhas->id)->sole()->comentario);
    }

    public function test_cabecalho_errado_recusa_o_arquivo_dizendo_qual_coluna(): void
    {
        Sanctum::actingAs($this->comite);
        $cabecalho = $this->modelo()[0];

        // Coluna renomeada.
        $renomeado = $cabecalho;
        $renomeado[3] = 'Observações';
        $this->previa($this->planilha([], $renomeado))
            ->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', 'Coluna não reconhecida: "Observações" (coluna D). Cada pergunta começa pelo número dela (ex.: "6.1 …"); não renomeie nem acrescente colunas.');

        // Pergunta apagada.
        $semColuna = array_values(array_filter($cabecalho, fn ($t) => ! str_starts_with($t, '6.1 ')));
        $this->previa($this->planilha([], $semColuna))
            ->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', 'Faltam colunas na planilha: 6.1. Baixe o modelo de novo: ele traz todas as perguntas da rubrica, inclusive as condicionais.');

        // Pergunta repetida.
        $repetido = [...$cabecalho, '6.1 Outra vez'];
        $this->previa($this->planilha([], $repetido))
            ->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', fn ($m) => str_contains($m, 'aparece duas vezes'));

        // Sem a coluna do estande.
        $this->previa($this->planilha([], ['Turno', 'Projeto']))
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
    }

    public function test_cada_erro_de_linha_vem_com_o_motivo(): void
    {
        Sanctum::actingAs($this->comite);
        $cabecalho = $this->modelo()[0];
        $col = fn (string $numero) => collect($cabecalho)->search(fn ($t) => str_starts_with($t, $numero.' ')) - 4;

        $errada = $this->cheia();
        $errada[$col('6.1')] = '7';
        $errada[$col('2.1')] = 'talvez';
        $condicional = $this->cheia();
        $condicional[$col('3.1')] = 'N';           // 3.2 continua preenchida
        $incompleta = $this->cheia();
        $incompleta[$col('7.1')] = '';
        $incompleta[$col('8.1')] = '';

        $linhas = $this->previa($this->planilha([
            ['A', '41', '', '', ...$errada, ''],
            ['B', '42', '', '', ...$condicional, ''],
            ['A', '41', '', '', ...$this->cheia(), ''],   // repetida
            ['A', '99', '', '', ...$this->cheia(), ''],   // não é dele
            ['A', '', '', '', ...array_fill(0, count($errada), ''), ''], // em branco: ignorada
        ]))
            ->assertOk()
            ->assertJsonPath('data.resumo.validas', 0)
            ->assertJsonPath('data.resumo.com_erro', 4)
            ->assertJsonPath('data.resumo.em_branco', 1)
            ->json('data.linhas');

        $this->assertContains('6.1: "7" não está na escala — use 0, 2, 4, 6, 8 ou 10.', $linhas[0]['erros']);
        $this->assertContains('2.1: "talvez" não é resposta válida — use S ou N.', $linhas[0]['erros']);
        // A inválida não aparece de novo como "falta".
        $this->assertEmpty(array_filter($linhas[0]['erros'], fn ($e) => str_starts_with($e, 'Faltam')));
        $this->assertContains('3.2: só se responde quando a 3.1 é "S". Apague o valor ou mude a 3.1.', $linhas[1]['erros']);
        $this->assertContains('Este projeto já aparece na linha 2.', $linhas[2]['erros']);
        $this->assertContains('Estande 099, Turno A (matutino) não está entre os projetos designados a você (ou já foi enviado).', $linhas[3]['erros']);

        $incompletas = $this->previa($this->planilha([['A', '41', '', '', ...$incompleta, '']]))->json('data.linhas.0.erros');
        $this->assertSame(['Faltam respostas: 7.1, 8.1.'], $incompletas);
    }

    public function test_aceita_sim_e_nao_escritos_por_extenso_e_o_na_da_4_3(): void
    {
        Sanctum::actingAs($this->comite);
        $cabecalho = $this->modelo()[0];
        $col = fn (string $numero) => collect($cabecalho)->search(fn ($t) => str_starts_with($t, $numero.' ')) - 4;
        $linha = $this->cheia();
        $linha[$col('2.1')] = 'Sim';
        $linha[$col('2.2')] = 'não';
        $linha[$col('4.3')] = 'NA';
        $linha[$col('14.1')] = 'N';
        $linha[$col('14.2')] = '';               // não se aplica sem IA
        $linha[$col('6.1')] = '10,0';

        $this->previa($this->planilha([['manhã', '41', '', '', ...$linha, '']]))
            ->assertOk()
            ->assertJsonPath('data.linhas.0.erros', [])
            ->assertJsonPath('data.linhas.0.respostas.projeto_abnt', 'nao')
            ->assertJsonPath('data.linhas.0.respostas.referencias_destacadas', 'nao_se_aplica')
            ->assertJsonPath('data.linhas.0.respostas.apresentacao', 10);
    }

    public function test_comite_fora_do_horario_fica_com_rascunho_e_envia_ao_subir_de_novo(): void
    {
        $this->travelTo(now()->setDateTime(2026, 10, 20, 12, 45));
        Sanctum::actingAs($this->comite);
        $arquivo = fn () => $this->planilha([['A', '41', '', '', ...$this->cheia(), '']]);

        $previa = $this->previa($arquivo())
            ->assertJsonPath('data.envio.aberto', false)
            ->assertJsonPath('data.envio.mensagem', 'Fora do horário de envio: as linhas válidas ficam salvas como rascunho. O envio abre em 20/10 às 13:30 — suba a mesma planilha de novo para enviar.')
            ->json('data.linhas.0');
        $corpo = ['linhas' => [['projeto_id' => $previa['projeto_id'], 'respostas' => $previa['respostas']]]];

        $this->postJson('/api/v1/avaliador/presencial/lote', $corpo)
            ->assertOk()
            ->assertJsonPath('data.resumo.rascunhos', 1)
            ->assertJsonPath('data.linhas.0.situacao', 'rascunho');
        $avaliacao = AvaliacaoPresencial::where('projeto_id', $this->abelhas->id)->sole();
        $this->assertSame(StatusAvaliacao::EmAndamento, $avaliacao->status);
        $this->assertTrue($avaliacao->aberta_pelo_comite);

        $this->travelTo(now()->setDateTime(2026, 10, 20, 13, 40));
        $this->postJson('/api/v1/avaliador/presencial/lote', $corpo)
            ->assertOk()->assertJsonPath('data.linhas.0.situacao', 'enviada');
        $this->assertSame(StatusAvaliacao::Concluida, $avaliacao->fresh()->status);
    }

    public function test_o_comum_fora_do_turno_recebe_o_motivo_na_linha(): void
    {
        $comum = $this->avaliador('Bruno Comum');
        $designada = AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $this->horta->id, 'avaliador_id' => $comum->id,
            'status' => StatusAvaliacao::Designada, 'dia' => '2026-10-20', 'turno' => 'B', 'vaga' => 'comum',
        ]);
        Sanctum::actingAs($comum);

        // Turno A em andamento; a designação é do turno B.
        $this->postJson('/api/v1/avaliador/presencial/lote', ['linhas' => [[
            'projeto_id' => $this->horta->id, 'respostas' => array_combine(RubricaPresencial::chaves(), array_map(fn ($v) => $v === 'S' ? 'sim' : (int) $v, $this->cheia())),
        ]]])
            ->assertOk()
            ->assertJsonPath('data.linhas.0.situacao', 'erro')
            ->assertJsonPath('data.linhas.0.mensagem', 'Esta avaliação é de um turno que ainda não começou.');
        $this->assertSame(StatusAvaliacao::Designada, $designada->fresh()->status);
    }

    public function test_o_envio_confere_de_novo_designacao_e_rubrica(): void
    {
        Sanctum::actingAs($this->comite);
        $outro = $this->finalista('Robô seguidor');

        $this->postJson('/api/v1/avaliador/presencial/lote', ['linhas' => [
            ['projeto_id' => $outro->id, 'respostas' => []],
            ['projeto_id' => $this->abelhas->id, 'respostas' => ['apresentacao' => 8]],
        ]])
            ->assertOk()
            ->assertJsonPath('data.linhas.0.mensagem', 'Este projeto não está mais entre os designados a você.')
            ->assertJsonPath('data.linhas.1.situacao', 'erro')
            ->assertJsonPath('data.linhas.1.mensagem', fn ($m) => str_starts_with($m, 'Faltam respostas: 2.1'));
        $this->assertSame(0, AvaliacaoPresencial::where('status', 'concluida')->count());
    }
}
