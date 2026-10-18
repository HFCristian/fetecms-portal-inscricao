<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusPresenca;
use App\Enums\StatusSuporte;
use App\Enums\TipoRegistro;
use App\Enums\TipoSuporte;
use App\Enums\Turno;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Cidade;
use App\Models\ContaTemporaria;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\Interprete;
use App\Models\InterpreteCheckin;
use App\Models\InterpreteVinculo;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\SuporteProjeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Services\ContaTemporariaService;
use App\Support\CodigoParticipante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprints 214–215 — Avaliação presencial → **Intérpretes**: cadastro com só
 * nome e CPF obrigatórios, vínculos ao projeto inteiro ou a participantes, a
 * designação no mapa (que segue o projeto), os pedidos de suporte, a
 * exportação com o código do crachá e a chegada por dia × turno.
 */
class InterpretesTest extends TestCase
{
    use RefreshDatabase;

    private const CPF = '52998224725';

    private const CPF_2 = '11144477735';

    private Edicao $edicao;

    private ListaFinal $lista;

    private Area $area;

    private Cidade $cidade;

    protected function setUp(): void
    {
        parent::setUp();

        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $this->cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $estado->id, 'capital' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => '2026-10-20 07:00:00',
            'evento_ate' => '2026-10-21 19:00:00',
            'agenda_presencial' => self::agendaPresencial(['A' => ['inicio' => '08:00', 'fim' => '12:00'], 'B' => ['inicio' => '13:00', 'fim' => '17:00']], '2026-10-20', '2026-10-21'),
            'estandes_gerados_em' => now(),
        ]);

        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista oficial', 'vigente' => true, 'demo' => false, 'versao' => 1,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function finalista(string $titulo, Turno $turno = Turno::A, int $numero = 1): Projeto
    {
        $instituicao = Instituicao::create(['nome' => 'Escola '.$titulo, 'cidade_id' => $this->cidade->id]);
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'categoria' => Categoria::Fetecms, 'area_id' => $this->area->id,
            'instituicao_id' => $instituicao->id, 'edicao_id' => $this->edicao->id,
        ]);
        $this->lista->projetos()->attach($projeto->id);

        TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno->value, 'regra' => 'equilibrio']);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno->value, 'numero' => $numero]);

        return $projeto;
    }

    private function interprete(array $over = []): Interprete
    {
        return Interprete::create($over + [
            'edicao_id' => $this->edicao->id,
            'nome' => 'Helena Intérprete',
            'cpf' => self::CPF,
            'tipos' => ['libras'],
        ]);
    }

    // --- Cadastro -----------------------------------------------------------

    public function test_cadastro_so_com_nome_e_cpf(): void
    {
        $admin = $this->admin();

        $resp = $this->postJson('/api/v1/admin/presencial/interpretes', [
            'nome' => '  Helena Intérprete ',
            'cpf' => '529.982.247-25',
        ])->assertCreated();

        $resp->assertJsonPath('data.nome', 'Helena Intérprete')
            ->assertJsonPath('data.cpf', '529.982.247-25');

        $interprete = Interprete::firstOrFail();
        $this->assertSame(self::CPF, $interprete->cpf);
        $this->assertSame('2026-0-529-I'.$interprete->id, $resp->json('data.codigo'));
        $this->assertSame(1, RegistroAtividade::where('tipo', TipoRegistro::InterpreteCadastrado)->where('user_id', $admin->id)->count());
    }

    public function test_cpf_invalido_ou_repetido_na_edicao_e_recusado(): void
    {
        $this->admin();
        $this->interprete();

        $this->postJson('/api/v1/admin/presencial/interpretes', ['nome' => 'Outro', 'cpf' => '12345678900'])
            ->assertStatus(422)->assertJsonValidationErrors('cpf');

        $this->postJson('/api/v1/admin/presencial/interpretes', ['nome' => 'Outro', 'cpf' => self::CPF])
            ->assertStatus(422)->assertJsonValidationErrors('cpf');

        $this->postJson('/api/v1/admin/presencial/interpretes', ['nome' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['nome', 'cpf']);
    }

    public function test_tipos_e_outros_pedem_o_texto(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/presencial/interpretes', [
            'nome' => 'Helena', 'cpf' => self::CPF, 'tipos' => ['espanhol', 'lingua_outra'],
        ])->assertStatus(422)->assertJsonValidationErrors('outra_lingua');

        $this->postJson('/api/v1/admin/presencial/interpretes', [
            'nome' => 'Helena', 'cpf' => self::CPF, 'tipos' => ['espanhol', 'lingua_outra', 'libras'],
            'outra_lingua' => 'Guarani', 'data_nascimento' => '1990-05-10',
        ])->assertCreated()
            ->assertJsonPath('data.tipos_labels', ['Espanhol', 'Guarani', 'Libras'])
            ->assertJsonPath('data.data_nascimento_label', '10/05/1990');
    }

    public function test_vinculo_ao_projeto_inteiro_e_a_participantes_de_varios_projetos(): void
    {
        $this->admin();
        $um = $this->finalista('Bioplástico', Turno::A, 12);
        $dois = $this->finalista('Sensor', Turno::B, 3);
        $aluna = Aluno::factory()->create(['projeto_id' => $dois->id, 'nome' => 'Ana Surda']);

        $resp = $this->postJson('/api/v1/admin/presencial/interpretes', [
            'nome' => 'Helena', 'cpf' => self::CPF,
            'vinculos' => [
                ['projeto_id' => $um->id],
                ['projeto_id' => $dois->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $aluna->id],
            ],
        ])->assertCreated();

        $this->assertSame(2, InterpreteVinculo::count());
        $this->assertSame('Ana Surda', $resp->json('data.vinculos.1.pessoa_nome'));
        $this->assertSame('Turno A · Estande 012 / Turno B · Estande 003', $resp->json('data.locais_label'));
    }

    public function test_vinculo_so_com_finalista_e_pessoa_da_equipe(): void
    {
        $this->admin();
        $fora = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        $projeto = $this->finalista('Bioplástico');
        $deOutro = Aluno::factory()->create(['projeto_id' => $fora->id]);

        $this->postJson('/api/v1/admin/presencial/interpretes', [
            'nome' => 'Helena', 'cpf' => self::CPF, 'vinculos' => [['projeto_id' => $fora->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('vinculos');

        $this->postJson('/api/v1/admin/presencial/interpretes', [
            'nome' => 'Helena', 'cpf' => self::CPF,
            'vinculos' => [['projeto_id' => $projeto->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $deOutro->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('vinculos');
    }

    public function test_editar_preserva_a_origem_dos_vinculos_que_ficam(): void
    {
        $this->admin();
        $um = $this->finalista('Bioplástico');
        $dois = $this->finalista('Sensor', Turno::A, 2);
        $interprete = $this->interprete();
        $interprete->vinculos()->create(['projeto_id' => $um->id, 'origem' => 'mapa']);

        $this->putJson("/api/v1/admin/presencial/interpretes/{$interprete->id}", [
            'nome' => 'Helena Souza', 'cpf' => self::CPF,
            'vinculos' => [['projeto_id' => $um->id], ['projeto_id' => $dois->id]],
        ])->assertOk();

        $this->assertSame('mapa', InterpreteVinculo::where('projeto_id', $um->id)->value('origem'));
        $this->assertSame('cadastro', InterpreteVinculo::where('projeto_id', $dois->id)->value('origem'));
        $this->assertSame('Helena Souza', $interprete->fresh()->nome);
    }

    public function test_excluir_registra_e_apaga_os_vinculos(): void
    {
        $this->admin();
        $interprete = $this->interprete();
        $interprete->vinculos()->create(['projeto_id' => $this->finalista('Bioplástico')->id]);

        $this->deleteJson("/api/v1/admin/presencial/interpretes/{$interprete->id}")->assertOk();

        $this->assertSame(0, Interprete::count());
        $this->assertSame(0, InterpreteVinculo::count());
        $this->assertSame(1, RegistroAtividade::where('tipo', TipoRegistro::InterpreteExcluido)->count());
    }

    // --- Mapa -----------------------------------------------------------------

    public function test_designar_no_estande_vincula_ao_projeto_e_segue_o_projeto(): void
    {
        $this->admin();
        $projeto = $this->finalista('Bioplástico', Turno::A, 7);
        $interprete = $this->interprete();

        $this->putJson("/api/v1/admin/presencial/interpretes/{$interprete->id}/estandes", [
            'numero' => 7, 'turno' => 'A', 'designado' => true,
        ])->assertOk()->assertJsonPath('data.locais.0.numero', 7);

        $this->assertSame('mapa', InterpreteVinculo::where('projeto_id', $projeto->id)->value('origem'));

        // O projeto muda de lugar: a designação vai junto.
        EstandeProjeto::where('projeto_id', $projeto->id)->update(['turno' => 'B', 'numero' => 30]);
        TurnoApresentacao::where('projeto_id', $projeto->id)->update(['turno' => 'B']);

        $this->getJson('/api/v1/admin/presencial/interpretes')->assertOk()
            ->assertJsonPath('data.interpretes.0.locais_label', 'Turno B · Estande 030');
    }

    public function test_tirar_a_designacao_desfaz_so_o_vinculo_do_mapa(): void
    {
        $this->admin();
        $projeto = $this->finalista('Bioplástico', Turno::A, 7);
        $interprete = $this->interprete();
        $interprete->vinculos()->create(['projeto_id' => $projeto->id, 'origem' => 'mapa']);
        $interprete->vinculos()->create(['projeto_id' => $projeto->id, 'pessoa_tipo' => 'orientador', 'pessoa_id' => $projeto->user_id, 'origem' => 'cadastro']);

        $this->putJson("/api/v1/admin/presencial/interpretes/{$interprete->id}/estandes", [
            'numero' => 7, 'turno' => 'A', 'designado' => false,
        ])->assertOk();

        $this->assertSame(['cadastro'], InterpreteVinculo::pluck('origem')->all());
    }

    public function test_estande_vazio_nao_recebe_designacao(): void
    {
        $this->admin();
        $interprete = $this->interprete();

        $this->putJson("/api/v1/admin/presencial/interpretes/{$interprete->id}/estandes", [
            'numero' => 99, 'turno' => 'A', 'designado' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('numero');
    }

    // --- Pedidos de suporte ---------------------------------------------------

    public function test_pedido_aprovado_sem_interprete_aparece_e_e_atendido(): void
    {
        $this->admin();
        $projeto = $this->finalista('Bioplástico');
        $aluna = Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana']);
        $pedido = SuporteProjeto::create([
            'projeto_id' => $projeto->id, 'tipo' => TipoSuporte::InterpreteLibras, 'aluno_id' => $aluna->id,
            'status' => StatusSuporte::Aprovado,
        ]);
        SuporteProjeto::create(['projeto_id' => $projeto->id, 'tipo' => TipoSuporte::InterpreteLingua, 'status' => StatusSuporte::Pendente]);
        $interprete = $this->interprete();

        $this->getJson('/api/v1/admin/presencial/interpretes')->assertOk()
            ->assertJsonCount(1, 'data.pedidos')
            ->assertJsonPath('data.pedidos.0.aluno', 'Ana');

        $this->postJson("/api/v1/admin/presencial/interpretes/{$interprete->id}/pedidos/{$pedido->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.pedidos');

        $vinculo = InterpreteVinculo::firstOrFail();
        $this->assertSame('aluno', $vinculo->pessoa_tipo);
        $this->assertSame($aluna->id, $vinculo->pessoa_id);
        $this->assertSame('pedido', $vinculo->origem);
    }

    // --- Exportação -----------------------------------------------------------

    public function test_exporta_a_lista_com_os_codigos_e_as_etiquetas(): void
    {
        $this->admin();
        $interprete = $this->interprete();

        $csv = $this->get('/api/v1/admin/presencial/interpretes/exportar/csv')->assertOk()->getContent();
        $this->assertStringContainsString('Helena Intérprete', $csv);
        $this->assertStringContainsString($interprete->codigo(), $csv);

        $xlsx = $this->get('/api/v1/admin/presencial/interpretes/exportar/xlsx')->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        $pdf = $this->get('/api/v1/admin/presencial/interpretes/etiquetas')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    // --- Chegada ----------------------------------------------------------------

    public function test_cracha_do_interprete_registra_a_chegada_na_ocorrencia(): void
    {
        $this->admin();
        $interprete = $this->interprete();

        $this->postJson('/api/v1/admin/presencial/interpretes/checkin/codigo', [
            'codigo' => $interprete->codigo(), 'dia' => '2026-10-20', 'turno' => 'A',
        ])->assertOk()
            ->assertJsonPath('data.ja_presente', false)
            ->assertJsonPath('data.participante.papel_label', 'Intérprete');

        $this->postJson('/api/v1/admin/presencial/interpretes/checkin/codigo', [
            'codigo' => $interprete->codigo(), 'dia' => '2026-10-20', 'turno' => 'A',
        ])->assertOk()->assertJsonPath('data.ja_presente', true);

        $this->assertSame(1, InterpreteCheckin::count());
    }

    public function test_cracha_com_cpf_trocado_ou_de_participante_e_recusado(): void
    {
        $this->admin();
        $interprete = $this->interprete();
        $trocado = CodigoParticipante::montar(2026, 0, self::CPF_2, CodigoParticipante::PAPEL_INTERPRETE, $interprete->id);

        $this->postJson('/api/v1/admin/presencial/interpretes/checkin/codigo', [
            'codigo' => $trocado, 'dia' => '2026-10-20', 'turno' => 'A',
        ])->assertStatus(422)->assertJsonValidationErrors('codigo');

        $this->postJson('/api/v1/admin/presencial/interpretes/checkin/codigo', [
            'codigo' => '2026-31-123-A45', 'dia' => '2026-10-20', 'turno' => 'A',
        ])->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_dia_fora_do_evento_e_recusado(): void
    {
        $this->admin();
        $interprete = $this->interprete();

        $this->postJson("/api/v1/admin/presencial/interpretes/{$interprete->id}/checkin", [
            'dia' => '2026-10-25', 'turno' => 'A',
        ])->assertStatus(422)->assertJsonValidationErrors('dia');
    }

    public function test_cracha_de_interprete_no_credenciamento_explica_onde_registrar(): void
    {
        $this->admin();
        $interprete = $this->interprete();

        $this->postJson('/api/v1/admin/credenciamento/codigo', ['codigo' => $interprete->codigo()])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Este crachá é de intérprete. A chegada dele é registrada em Avaliação presencial → Intérpretes.');
    }

    public function test_acompanhamento_mostra_quem_devia_estar_no_turno(): void
    {
        $admin = $this->admin();
        $manha = $this->finalista('Bioplástico', Turno::A, 5);
        $tarde = $this->finalista('Sensor', Turno::B, 6);

        $presente = $this->interprete(['nome' => 'Bia', 'cpf' => self::CPF]);
        $presente->vinculos()->create(['projeto_id' => $manha->id]);
        $falta = $this->interprete(['nome' => 'Caio', 'cpf' => self::CPF_2]);
        $falta->vinculos()->create(['projeto_id' => $manha->id]);
        $outroTurno = $this->interprete(['nome' => 'Davi', 'cpf' => '39053344705']);
        $outroTurno->vinculos()->create(['projeto_id' => $tarde->id]);

        InterpreteCheckin::create(['interprete_id' => $presente->id, 'dia' => '2026-10-20', 'turno' => 'A', 'registrado_por' => $admin->id]);
        InterpreteCheckin::create(['interprete_id' => $outroTurno->id, 'dia' => '2026-10-20', 'turno' => 'A', 'registrado_por' => $admin->id]);

        $dados = $this->getJson('/api/v1/admin/presencial/interpretes/acompanhamento?dia=2026-10-20&turno=A')
            ->assertOk()->json('data');

        $this->assertSame(['esperados' => 2, 'presentes' => 1, 'faltando' => 1, 'extras' => 1], $dados['totais']);
        $this->assertSame('Caio', $dados['interpretes'][0]['nome'], 'Quem falta vem primeiro.');
        $this->assertSame([5], $dados['interpretes'][0]['estandes']);
    }

    public function test_desfazer_a_chegada_exige_justificativa_e_registra(): void
    {
        $admin = $this->admin();
        $interprete = $this->interprete();
        InterpreteCheckin::create(['interprete_id' => $interprete->id, 'dia' => '2026-10-20', 'turno' => 'A', 'registrado_por' => $admin->id]);

        $url = "/api/v1/admin/presencial/interpretes/{$interprete->id}/checkin/desfazer";

        $this->postJson($url, ['dia' => '2026-10-20', 'turno' => 'A'])
            ->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->postJson($url, ['dia' => '2026-10-20', 'turno' => 'A', 'justificativa' => 'Bipado por engano'])->assertOk();

        $this->assertSame(0, InterpreteCheckin::count());
        $registro = RegistroAtividade::where('tipo', TipoRegistro::InterpreteCheckinDesfeito)->firstOrFail();
        $this->assertSame('Bipado por engano', $registro->detalhes['justificativa']);
    }

    // --- Quem acessa ------------------------------------------------------------

    public function test_conta_temporaria_da_aba_registra_chegada_mas_nao_ve_o_cadastro(): void
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Voluntária', 'email' => 'vol@fetec.test', 'password' => 'senha-segura',
            'cpf' => '39053344705', 'curso' => 'Letras', 'horas' => 5,
        ], null, ContaTemporaria::SETOR_PRESENCIAL);
        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();
        Sanctum::actingAs($conta->user);
        $interprete = $this->interprete();

        $this->getJson('/api/v1/admin/presencial/interpretes/checkin')->assertOk();
        $this->postJson("/api/v1/admin/presencial/interpretes/{$interprete->id}/checkin", ['dia' => '2026-10-20', 'turno' => 'A'])
            ->assertOk();

        $this->getJson('/api/v1/admin/presencial/interpretes')->assertForbidden();
        $this->getJson('/api/v1/admin/presencial/interpretes/acompanhamento?dia=2026-10-20&turno=A')->assertForbidden();
        $this->get('/api/v1/admin/presencial/interpretes/exportar/csv')->assertForbidden();
    }

    public function test_config_traz_as_ocorrencias_do_evento(): void
    {
        $this->admin();

        $this->getJson('/api/v1/admin/presencial/interpretes/checkin')->assertOk()
            ->assertJsonCount(4, 'data.ocorrencias')
            ->assertJsonPath('data.ocorrencias.0.chave', '2026-10-20|A');
    }
}
