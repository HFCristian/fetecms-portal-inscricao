<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\TipoRegistro;
use App\Enums\Turno;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Cidade;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\TurnoApresentacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 118 — Mapa do Evento → **Turnos de Apresentação**.
 *
 * A divisão dos finalistas entre o matutino e o vespertino: as cinco regras em
 * ordem de prioridade, o equilíbrio do resto, a capacidade como teto e a troca
 * manual registrada.
 */
class TurnosApresentacaoTest extends TestCase
{
    use RefreshDatabase;

    private Estado $ms;

    private Estado $sp;

    private Cidade $campoGrande;

    private Cidade $dourados;

    private Cidade $saoPaulo;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ms = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $this->sp = Estado::create(['nome' => 'São Paulo', 'uf' => 'SP']);
        $this->campoGrande = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $this->ms->id, 'capital' => true]);
        $this->dourados = Cidade::create(['nome' => 'Dourados', 'estado_id' => $this->ms->id, 'capital' => false]);
        $this->saoPaulo = Cidade::create(['nome' => 'São Paulo', 'estado_id' => $this->sp->id, 'capital' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Um finalista com escola na cidade informada. */
    private function finalista(string $titulo, Cidade $cidade, Categoria $categoria = Categoria::Fetecms): Projeto
    {
        $instituicao = Instituicao::create(['nome' => 'Escola '.$titulo, 'cidade_id' => $cidade->id]);

        return Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'categoria' => $categoria,
            'area_id' => $this->area->id,
            'instituicao_id' => $instituicao->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);
    }

    /** A lista final vigente com os projetos informados. */
    private function listaFinal(Projeto ...$projetos): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => Edicao::atual()?->id,
            'nome' => 'Lista oficial',
            'vigente' => true,
            'demo' => false,
            'versao' => 1,
        ]);

        $lista->projetos()->attach(collect($projetos)->pluck('id'));

        return $lista;
    }

    /** A configuração que a tela manda: capacidades + regras. */
    private function config(array $regras = [], int $a = 10, int $b = 10): array
    {
        return [
            'capacidade' => ['A' => $a, 'B' => $b],
            'regras' => $regras,
        ];
    }

    // --- As travas -------------------------------------------------------

    public function test_sem_lista_final_vigente_a_geracao_e_recusada(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config())
            ->assertStatus(422)
            ->assertJsonValidationErrors('lista_final');
    }

    public function test_lista_maior_que_os_dois_turnos_e_recusada_com_o_que_falta(): void
    {
        $this->admin();
        $this->listaFinal(
            $this->finalista('Um', $this->campoGrande),
            $this->finalista('Dois', $this->campoGrande),
            $this->finalista('Três', $this->campoGrande),
        );

        $resposta = $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([], 1, 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('capacidade');

        $this->assertStringContainsString('Faltam 1 lugares', $resposta->json('errors.capacidade.0'));
        $this->assertSame(0, TurnoApresentacao::count());
    }

    // --- O equilíbrio ----------------------------------------------------

    public function test_sem_regra_nenhuma_os_dois_turnos_ficam_equilibrados(): void
    {
        $this->admin();
        $this->listaFinal(...array_map(
            fn (int $i) => $this->finalista("Projeto {$i}", $this->campoGrande),
            range(1, 7),
        ));

        $dados = $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config())
            ->assertOk()
            ->json('data');

        // 7 projetos: 4 e 3 — a diferença nunca passa de um.
        $this->assertSame(4, $dados['turnos']['A']['total']);
        $this->assertSame(3, $dados['turnos']['B']['total']);
        $this->assertSame(7, $dados['total']);
    }

    // --- As regras de localidade -----------------------------------------

    public function test_regra_de_fora_do_ms_leva_os_projetos_para_o_turno_escolhido(): void
    {
        $this->admin();
        $deFora = $this->finalista('Vem de longe', $this->saoPaulo);
        $daqui = $this->finalista('Daqui mesmo', $this->campoGrande);
        $this->listaFinal($deFora, $daqui);

        $dados = $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([
            'fora_ms' => ['ativa' => true, 'turno' => 'B'],
        ]))->assertOk()->json('data');

        $this->assertSame('B', $this->turnoDe($deFora));
        $this->assertSame(
            'fora_ms',
            collect($dados['turnos']['B']['projetos'])->firstWhere('projeto_id', $deFora->id)['regra'],
        );
        // O que a regra não alcança cai no equilíbrio.
        $this->assertSame('A', $this->turnoDe($daqui));
    }

    public function test_interior_e_capital_sao_recortes_distintos(): void
    {
        $this->admin();
        $interior = $this->finalista('Do interior', $this->dourados);
        $capital = $this->finalista('Da capital', $this->campoGrande);
        $this->listaFinal($interior, $capital);

        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([
            'fora_capital' => ['ativa' => true, 'turno' => 'A'],
            'capital' => ['ativa' => true, 'turno' => 'B'],
        ]))->assertOk();

        $this->assertSame('A', $this->turnoDe($interior));
        $this->assertSame('B', $this->turnoDe($capital));
    }

    /** Um projeto de fora do MS também está "fora da capital" — a ordem decide. */
    public function test_a_ordem_de_prioridade_resolve_a_sobreposicao_das_regras(): void
    {
        $this->admin();
        $deFora = $this->finalista('Vem de longe', $this->saoPaulo);
        $this->listaFinal($deFora);

        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([
            'fora_ms' => ['ativa' => true, 'turno' => 'A'],
            'fora_capital' => ['ativa' => true, 'turno' => 'B'],
        ]))->assertOk();

        $this->assertSame('A', $this->turnoDe($deFora));
        $this->assertSame('fora_ms', TurnoApresentacao::where('projeto_id', $deFora->id)->first()->regra);
    }

    // --- As regras com lista ---------------------------------------------

    public function test_vestibular_ganha_das_regras_de_localidade(): void
    {
        $this->admin();
        $vestibulando = $this->finalista('Presta UFMS', $this->campoGrande);
        $this->listaFinal($vestibulando);

        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([
            'vestibular' => ['ativa' => true, 'listas' => [
                ['nome' => 'UFMS 2026', 'turno' => 'B', 'projetos' => [$vestibulando->id]],
            ]],
            'capital' => ['ativa' => true, 'turno' => 'A'],
        ]))->assertOk();

        $alocacao = TurnoApresentacao::where('projeto_id', $vestibulando->id)->first();

        $this->assertSame(Turno::B, $alocacao->turno);
        $this->assertSame('vestibular', $alocacao->regra);
        // O nome da lista fica junto: a tela explica a alocação sem reabrir a regra.
        $this->assertSame('UFMS 2026', $alocacao->origem);
    }

    /** Na justificativa o admin marca o turno IMPOSSÍVEL: o destino é o outro. */
    public function test_justificativa_aloca_no_turno_oposto_ao_impedimento(): void
    {
        $this->admin();
        $impedido = $this->finalista('Chega ao meio-dia', $this->dourados);
        $this->listaFinal($impedido);

        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([
            'justificativa' => ['ativa' => true, 'listas' => [[
                'nome' => 'Ônibus atrasado',
                'turno_indisponivel' => 'A',
                'origem' => 'whatsapp',
                'projetos' => [$impedido->id],
            ]]],
        ]))->assertOk();

        $this->assertSame('B', $this->turnoDe($impedido));
    }

    // --- A capacidade como teto ------------------------------------------

    public function test_turno_cheio_manda_o_projeto_para_o_outro_e_avisa(): void
    {
        $this->admin();
        $um = $this->finalista('Alfa', $this->saoPaulo);
        $dois = $this->finalista('Beta', $this->saoPaulo);
        $this->listaFinal($um, $dois);

        // Os dois pedem o turno A, mas lá só cabe um.
        $resposta = $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([
            'fora_ms' => ['ativa' => true, 'turno' => 'A'],
        ], 1, 1))->assertOk();

        $realocados = $resposta->json('meta.realocados');

        $this->assertCount(1, $realocados);
        $this->assertSame('Beta', $realocados[0]['projeto']);
        $this->assertStringContainsString('sem estande livre', $realocados[0]['motivo']);
        $this->assertStringContainsString('1 projeto(s) foram para o outro turno', $resposta->json('meta.message'));
    }

    // --- Gerar de novo e mover à mão -------------------------------------

    public function test_gerar_de_novo_substitui_a_lista_inteira(): void
    {
        $this->admin();
        $projeto = $this->finalista('Único', $this->campoGrande);
        $this->listaFinal($projeto);

        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([
            'capital' => ['ativa' => true, 'turno' => 'A'],
        ]))->assertOk();
        $this->assertSame('A', $this->turnoDe($projeto));

        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([
            'capital' => ['ativa' => true, 'turno' => 'B'],
        ]))->assertOk();

        $this->assertSame('B', $this->turnoDe($projeto));
        $this->assertSame(1, TurnoApresentacao::count(), 'A lista anterior sai inteira.');
        $this->assertSame(2, RegistroAtividade::where('tipo', TipoRegistro::TurnosGerados)->count());
    }

    public function test_mover_a_mao_nao_pede_justificativa_mas_fica_registrado(): void
    {
        $admin = $this->admin();
        $projeto = $this->finalista('Único', $this->campoGrande);
        $this->listaFinal($projeto);
        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config())->assertOk();

        $this->patchJson('/api/v1/admin/mapa/turnos/mover', [
            'projeto_id' => $projeto->id,
            'turno' => 'B',
        ])->assertOk();

        $alocacao = TurnoApresentacao::where('projeto_id', $projeto->id)->first();
        $this->assertSame(Turno::B, $alocacao->turno);
        $this->assertTrue($alocacao->manual);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::TurnosProjetoMovido)->firstOrFail();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($projeto->id, $registro->projeto_id);
        $this->assertSame(Turno::A->label(), $registro->detalhes['de']);
        $this->assertSame(Turno::B->label(), $registro->detalhes['para']);
    }

    public function test_nao_move_para_turno_lotado(): void
    {
        $this->admin();
        $um = $this->finalista('Alfa', $this->campoGrande);
        $dois = $this->finalista('Beta', $this->campoGrande);
        $this->listaFinal($um, $dois);
        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config([], 1, 1))->assertOk();

        $this->patchJson('/api/v1/admin/mapa/turnos/mover', [
            'projeto_id' => $um->id,
            'turno' => $this->turnoDe($um) === 'A' ? 'B' : 'A',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('turno');
    }

    // --- Configuração e exportação ---------------------------------------

    public function test_config_fica_salva_entre_geracoes(): void
    {
        $this->admin();

        $this->putJson('/api/v1/admin/mapa/turnos/config', $this->config([
            'vestibular' => ['ativa' => true, 'listas' => [
                ['nome' => 'UFMS 2026', 'turno' => 'B', 'projetos' => [1, 2]],
            ]],
        ], 230, 230))->assertOk();

        $config = $this->getJson('/api/v1/admin/mapa/turnos')->assertOk()->json('data.config');

        $this->assertSame(230, $config['capacidade']['A']);
        $this->assertTrue($config['regras']['vestibular']['ativa']);
        $this->assertSame('UFMS 2026', $config['regras']['vestibular']['listas'][0]['nome']);
        $this->assertSame([1, 2], $config['regras']['vestibular']['listas'][0]['projetos']);
    }

    public function test_busca_de_finalistas_acha_por_titulo_e_por_participante(): void
    {
        $this->admin();
        $projeto = $this->finalista('Bioplástico de mandioca', $this->campoGrande);
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Zuleica Nunes']);
        $this->listaFinal($projeto);

        $porTitulo = $this->getJson('/api/v1/admin/mapa/turnos/opcoes?q=mandioca')->assertOk()->json('data');
        $porAluno = $this->getJson('/api/v1/admin/mapa/turnos/opcoes?q=zuleica')->assertOk()->json('data');
        $semNada = $this->getJson('/api/v1/admin/mapa/turnos/opcoes?q=inexistente')->assertOk()->json('data');

        $this->assertCount(1, $porTitulo);
        $this->assertCount(1, $porAluno);
        $this->assertSame($projeto->id, $porAluno[0]['id']);
        $this->assertSame([], $semNada);
    }

    public function test_exporta_em_txt_csv_e_pdf(): void
    {
        $this->admin();
        $projeto = $this->finalista('Horta na escola', $this->dourados);
        $this->listaFinal($projeto);
        $this->postJson('/api/v1/admin/mapa/turnos/gerar', $this->config())->assertOk();

        $txt = $this->get('/api/v1/admin/mapa/turnos/exportar/txt')->assertOk();
        $this->assertStringContainsString('Horta na escola', $txt->getContent());

        $csv = $this->get('/api/v1/admin/mapa/turnos/exportar/csv')->assertOk();
        $this->assertStringContainsString('Dourados', $csv->getContent());

        $pdf = $this->get('/api/v1/admin/mapa/turnos/exportar/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    /** A aba é do RBAC como as outras. */
    public function test_admin_sem_a_aba_mapa_recebe_403(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => Edicao::atual()?->id]);
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/admin/mapa/turnos')->assertForbidden();
    }

    private function turnoDe(Projeto $projeto): string
    {
        return TurnoApresentacao::where('projeto_id', $projeto->id)->firstOrFail()->turno->value;
    }
}
