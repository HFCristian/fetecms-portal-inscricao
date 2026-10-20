<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\ChecagemEstande;
use App\Models\Cidade;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\Estado;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\SaidaAntecipada;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Services\MapaAvaliacoesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mapa do Evento → **avaliações por projeto** (Sprint 248).
 *
 * O que estes testes seguram:
 * - a categoria sai das vagas **enviadas**, comum e do comitê, pela vaga
 *   gravada — e comum a mais não cobre o comitê;
 * - a avaliação **em andamento** aparece à parte e não muda a categoria; a
 *   vencida não aparece;
 * - todo projeto do turno entra, e quem não está pronto ou saiu ganha marca;
 * - o dia escolhido corta o que tinha acontecido;
 * - a lista e o mapa saem em PDF, no recorte do filtro.
 */
class MapaAvaliacoesTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Cidade $cidade;

    protected function setUp(): void
    {
        parent::setUp();

        // 10h: dentro do horário de avaliação do turno A de hoje (8h–12h30).
        $this->travelTo(now()->setTime(10, 0));

        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $this->cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $estado->id, 'capital' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        $dias = array_map(fn (int $d) => now()->subDays($d)->toDateString(), [2, 1, 0]);

        Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subDays(2)->startOfDay(),
            'evento_ate' => now()->addDay()->endOfDay(),
            'agenda_presencial' => self::agendaPresencial(['A' => ['inicio' => '08:00', 'fim' => '12:00']], ...$dias),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function noEstande(string $titulo, int $numero, Turno $turno = Turno::A, bool $pronto = true): Projeto
    {
        $instituicao = Instituicao::create(['nome' => 'EE '.$titulo, 'cidade_id' => $this->cidade->id]);

        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'categoria' => Categoria::Fetecms,
            'area_id' => $this->area->id,
            'instituicao_id' => $instituicao->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);

        TurnoApresentacao::create(['edicao_id' => Edicao::atual()?->id, 'projeto_id' => $projeto->id, 'turno' => $turno->value]);
        EstandeProjeto::create(['edicao_id' => Edicao::atual()?->id, 'projeto_id' => $projeto->id, 'turno' => $turno->value, 'numero' => $numero]);

        if ($pronto) {
            Credenciamento::create([
                'projeto_id' => $projeto->id,
                'credenciado_por' => User::factory()->admin()->create()->id,
                'finalizado_em' => now()->subDays(2),
            ]);
            ChecagemEstande::create([
                'projeto_id' => $projeto->id,
                'verificado_por' => User::factory()->admin()->create()->id,
                'verificado_em' => now()->subDays(2),
            ]);
        }

        return $projeto;
    }

    private function avaliacao(Projeto $projeto, string $vaga, string $status = 'concluida', array $extra = []): AvaliacaoPresencial
    {
        return AvaliacaoPresencial::create([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'avaliador_id' => User::factory()->avaliador()->create()->id,
            'status' => $status,
            'vaga' => $vaga,
            'nota' => $status === 'concluida' ? 8 : null,
            'iniciada_em' => now()->subHour(),
            'concluida_em' => $status === 'concluida' ? now() : null,
        ] + $extra);
    }

    /** @return array<int, array<string, mixed>> */
    private function porEstande(array $dados): array
    {
        return collect($dados['linhas'])->keyBy('numero')->all();
    }

    public function test_as_seis_categorias_saem_das_vagas_enviadas(): void
    {
        $this->admin();

        $p1 = $this->noEstande('Nenhuma', 1);
        $p2 = $this->noEstande('Uma comum', 2);
        $this->avaliacao($p2, 'comum');
        $p3 = $this->noEstande('Só o comitê', 3);
        $this->avaliacao($p3, 'comite');
        $p4 = $this->noEstande('Duas comuns', 4);
        $this->avaliacao($p4, 'comum');
        $this->avaliacao($p4, 'comum');
        $p5 = $this->noEstande('Comum e comitê', 5);
        $this->avaliacao($p5, 'comum');
        $this->avaliacao($p5, 'comite');
        $p6 = $this->noEstande('Fechada', 6);
        $this->avaliacao($p6, 'comum');
        $this->avaliacao($p6, 'comum');
        $this->avaliacao($p6, 'comite');

        $dados = $this->getJson('/api/v1/admin/mapa/avaliacoes?turno=A')->assertOk()->json('data');

        // As seis, na ordem em que a organização as nomeia, com uma cor cada.
        $this->assertSame([
            'Faltam 3 avaliações',
            'Faltam 2 avaliações (1 comitê)',
            'Faltam 2 avaliações (comum)',
            'Falta somente comitê',
            'Falta somente comum',
            'Avaliação fechada',
        ], array_column($dados['categorias'], 'label'));
        $this->assertSame([1, 1, 1, 1, 1, 1], array_column($dados['categorias'], 'total'));
        $this->assertCount(6, array_unique(array_column($dados['categorias'], 'cor')));
        $this->assertSame(['comuns' => 2, 'comite' => 1, 'total' => 3], $dados['vagas']);

        $linhas = $this->porEstande($dados);
        $this->assertSame('Faltam 3 avaliações', $linhas[1]['situacao_label']);
        $this->assertSame('Faltam 2 avaliações (1 comitê)', $linhas[2]['situacao_label']);
        $this->assertSame('Faltam 2 avaliações (comum)', $linhas[3]['situacao_label']);
        $this->assertSame('Falta somente comitê', $linhas[4]['situacao_label']);
        $this->assertSame('Falta somente comum', $linhas[5]['situacao_label']);
        $this->assertSame('Avaliação fechada', $linhas[6]['situacao_label']);

        $this->assertSame(['enviadas' => 2, 'em_andamento' => 0, 'vagas' => 2, 'faltam' => 0], $linhas[4]['comum']);
        $this->assertSame(['enviadas' => 0, 'em_andamento' => 0, 'vagas' => 1, 'faltam' => 1], $linhas[4]['comite']);
        $this->assertSame('001', $linhas[1]['estande']);
        $this->assertSame($p1->id, $linhas[1]['projeto_id']);
    }

    public function test_comum_a_mais_nao_cobre_a_vaga_do_comite(): void
    {
        $this->admin();

        // A designação manual passa do teto: três comuns, e o comitê ainda falta.
        $p = $this->noEstande('Três comuns', 7);
        $this->avaliacao($p, 'comum');
        $this->avaliacao($p, 'comum');
        $this->avaliacao($p, 'comum');

        $linha = $this->getJson('/api/v1/admin/mapa/avaliacoes?turno=A')->json('data.linhas.0');

        $this->assertSame('Falta somente comitê', $linha['situacao_label']);
        $this->assertSame(3, $linha['comum']['enviadas']);
        $this->assertSame(0, $linha['comum']['faltam']);
    }

    public function test_em_andamento_aparece_a_parte_e_a_vencida_nao_conta(): void
    {
        $this->admin();

        $p = $this->noEstande('Com rascunho', 8);
        $this->avaliacao($p, 'comum');
        // Aberta hoje, no turno de hoje: ainda está sendo preenchida.
        $this->avaliacao($p, 'comum', 'em_andamento', ['dia' => now()->toDateString(), 'turno' => 'A', 'iniciada_em' => now()->subMinutes(5)]);
        // O rascunho do comitê não vence.
        $this->avaliacao($p, 'comite', 'em_andamento', ['dia' => now()->subDays(2)->toDateString(), 'turno' => 'A', 'aberta_pelo_comite' => true]);
        // Aberta anteontem e nunca enviada: o prazo do turno passou.
        $vencida = $this->noEstande('Vencida', 9);
        $this->avaliacao($vencida, 'comum', 'em_andamento', ['dia' => now()->subDays(2)->toDateString(), 'turno' => 'A', 'iniciada_em' => now()->subDays(2)]);

        $dados = $this->getJson('/api/v1/admin/mapa/avaliacoes?turno=A')->json('data');
        $linhas = $this->porEstande($dados);

        $this->assertSame('Faltam 2 avaliações (1 comitê)', $linhas[8]['situacao_label']);
        $this->assertSame(1, $linhas[8]['comum']['em_andamento']);
        $this->assertSame(1, $linhas[8]['comite']['em_andamento']);
        $this->assertSame(2, $linhas[8]['em_andamento']);
        $this->assertSame(0, $linhas[9]['em_andamento']);
        $this->assertSame(1, $dados['marcas']['em_andamento']);
    }

    public function test_quem_nao_esta_pronto_ou_saiu_entra_com_marca(): void
    {
        $this->admin();

        $this->noEstande('Pronto', 10);
        $this->noEstande('Sem credenciamento', 11, pronto: false);
        $saiu = $this->noEstande('Foi embora', 12);
        SaidaAntecipada::create([
            'projeto_id' => $saiu->id,
            'dia' => now()->toDateString(),
            'saiu_em' => now()->subMinute(),
            'registrado_por' => User::factory()->admin()->create()->id,
        ]);
        $this->noEstande('Do outro turno', 13, Turno::B);

        $dados = $this->getJson('/api/v1/admin/mapa/avaliacoes?turno=A')->json('data');
        $linhas = $this->porEstande($dados);

        $this->assertSame(3, $dados['total']);
        $this->assertArrayNotHasKey(13, $linhas);
        $this->assertTrue($linhas[10]['pronto']);
        $this->assertFalse($linhas[11]['pronto']);
        $this->assertSame('Faltam 3 avaliações', $linhas[11]['situacao_label']);
        $this->assertNotNull($linhas[12]['saida']);
        $this->assertSame(['em_andamento' => 0, 'nao_pronto' => 1, 'saiu' => 1], $dados['marcas']);
    }

    public function test_o_dia_escolhido_corta_o_que_tinha_sido_enviado(): void
    {
        $this->admin();

        $p = $this->noEstande('Avaliado hoje', 14);
        $this->avaliacao($p, 'comum', extra: ['iniciada_em' => now()->subMinutes(30), 'concluida_em' => now()->subMinutes(10)]);

        $ontem = now()->subDay()->toDateString();
        $linha = $this->getJson("/api/v1/admin/mapa/avaliacoes?turno=A&dia={$ontem}")->json('data.linhas.0');

        // Ontem ela nem tinha sido aberta.
        $this->assertSame('Faltam 3 avaliações', $linha['situacao_label']);
        $this->assertSame(0, $linha['em_andamento']);

        $hoje = $this->getJson('/api/v1/admin/mapa/avaliacoes?turno=A')->json('data.linhas.0');
        $this->assertSame('Faltam 2 avaliações (1 comitê)', $hoje['situacao_label']);
    }

    public function test_filtro_soma_categorias_e_em_andamento(): void
    {
        $this->admin();

        $a = $this->noEstande('Nenhuma', 15);
        $b = $this->noEstande('Com rascunho', 16);
        $this->avaliacao($b, 'comum', 'em_andamento', ['dia' => now()->toDateString(), 'turno' => 'A']);
        $c = $this->noEstande('Fechada', 17);
        $this->avaliacao($c, 'comum');
        $this->avaliacao($c, 'comum');
        $this->avaliacao($c, 'comite');

        $servico = app(MapaAvaliacoesService::class);
        $painel = $servico->painel(null, 'A');

        $ids = fn (array $filtros) => array_column($servico->filtrar($painel['linhas'], $filtros), 'projeto_id');

        $this->assertSame([$a->id, $b->id, $c->id], $ids([]));
        $this->assertSame([$a->id, $b->id], $ids(['categorias' => ['comum2-comite1']]));
        $this->assertSame([$b->id], $ids(['categorias' => ['comum2-comite1'], 'em_andamento' => true]));
        $this->assertSame([$c->id], $ids(['categorias' => ['comum0-comite0']]));
        $this->assertSame(
            'Faltam 3 avaliações, Avaliação fechada · com avaliação em andamento',
            $servico->rotuloDoFiltro($painel, ['categorias' => ['comum2-comite1', 'comum0-comite0'], 'em_andamento' => true]),
        );
    }

    public function test_lista_e_mapa_saem_em_pdf(): void
    {
        $this->admin();

        $p = $this->noEstande('Projeto com acentuação', 18);
        $this->avaliacao($p, 'comite');
        $this->noEstande('Fora da planta', 950);

        foreach (['lista', 'mapa'] as $tipo) {
            $resposta = $this->get("/api/v1/admin/mapa/avaliacoes/exportar/{$tipo}?turno=A&categorias[]=comum2-comite0&em_andamento=0")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');

            $this->assertStringStartsWith('%PDF', $resposta->getContent());
            $this->assertStringContainsString("avaliacoes-{$tipo}.pdf", $resposta->headers->get('Content-Disposition'));
        }

        $this->get('/api/v1/admin/mapa/avaliacoes/exportar/outro')->assertNotFound();
    }

    public function test_admin_sem_a_aba_mapa_e_negado(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::atual()?->id]);
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/mapa/avaliacoes')->assertForbidden();
        $this->get('/api/v1/admin/mapa/avaliacoes/exportar/lista')->assertForbidden();
    }
}
