<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Services\ContaTemporariaService;
use App\Support\LeitorPlanilha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 163 — aba Certificados: avaliadores com as fases separadas,
 * participantes com CPF e função, e a declaração nominal de cada avaliador.
 */
class CertificadosTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private User $ana;

    private User $bruno;

    private Projeto $finalista;

    private Projeto $naoFinalista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);

        $this->finalista = Projeto::factory()->submetido()->create(['titulo' => 'Biofiltro', 'edicao_id' => $this->edicao->id]);
        $this->naoFinalista = Projeto::factory()->submetido()->create(['titulo' => 'Compostagem', 'edicao_id' => $this->edicao->id]);
        Aluno::factory()->create(['projeto_id' => $this->finalista->id, 'nome' => 'Zuleica Nunes', 'cpf' => '11144477735']);
        Aluno::factory()->create(['projeto_id' => $this->naoFinalista->id, 'nome' => 'Wagner Lima', 'cpf' => '22233344405']);

        $lista = ListaFinal::create(['edicao_id' => $this->edicao->id, 'nome' => 'Oficial', 'vigente' => true, 'demo' => false, 'versao' => 1]);
        $lista->projetos()->attach($this->finalista->id);

        $this->ana = $this->avaliador('Ana Avaliadora', '52998224725', comissao: true);
        $this->bruno = $this->avaliador('Bruno Avaliador', '11144477735');

        // Ana: duas online e uma presencial. Bruno: só presencial.
        $this->concluida($this->ana, $this->finalista);
        $this->concluida($this->ana, $this->naoFinalista);
        AvaliacaoPresencial::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $this->finalista->id, 'avaliador_id' => $this->ana->id, 'status' => StatusAvaliacao::Concluida, 'concluida_em' => now()]);
        AvaliacaoPresencial::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $this->finalista->id, 'avaliador_id' => $this->bruno->id, 'status' => StatusAvaliacao::Concluida, 'concluida_em' => now()]);
        // Em andamento não conta.
        Avaliacao::create(['projeto_id' => $this->finalista->id, 'avaliador_id' => $this->bruno->id, 'status' => StatusAvaliacao::EmAndamento]);

        Sanctum::actingAs(User::factory()->admin()->create(['name' => 'Olga Organizadora', 'cpf' => '33344455566']));
    }

    private function avaliador(string $nome, string $cpf, bool $comissao = false): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador, 'name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'cpf' => $cpf, 'comissao_especial' => $comissao]);

        return $user;
    }

    private function concluida(User $avaliador, Projeto $projeto): void
    {
        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Concluida, 'nota' => 8, 'concluida_em' => now(),
        ]);
    }

    /** @return list<list<string>> */
    private function ler(string $conteudo, string $extensao = 'xlsx'): array
    {
        $caminho = tempnam(sys_get_temp_dir(), 'cert');
        file_put_contents($caminho, $conteudo);

        return LeitorPlanilha::ler($caminho, $extensao);
    }

    public function test_avaliadores_da_fase_online_com_as_duas_fases_separadas(): void
    {
        $resposta = $this->get('/api/v1/admin/certificados/avaliadores/exportar?fase=online&formato=xlsx')->assertOk();
        $linhas = $this->ler($resposta->getContent());

        $this->assertSame(['Nome completo', 'CPF', 'E-mail', 'Área de avaliação', 'Projetos avaliados (fase online)', 'Projetos avaliados (fase presencial)'], $linhas[0]);
        // Bruno não avaliou online: fora do recorte da fase online.
        $this->assertCount(2, $linhas);
        $this->assertSame('Ana Avaliadora', $linhas[1][0]);
        $this->assertSame('529.982.247-25', $linhas[1][1]);
        $this->assertSame(['2', '1'], [$linhas[1][4], $linhas[1][5]]);

        $presencial = $this->ler($this->get('/api/v1/admin/certificados/avaliadores/exportar?fase=presencial&formato=xlsx')->getContent());
        $this->assertCount(3, $presencial);
    }

    public function test_a_tabela_de_avaliadores_online_mostra_a_fase_presencial_no_mesmo_cadastro(): void
    {
        $linhas = collect($this->getJson('/api/v1/admin/avaliacao/avaliadores')->assertOk()->json('data'));

        $bruno = $linhas->firstWhere('nome', 'Bruno Avaliador');
        $this->assertSame(0, $bruno['avaliou']);
        $this->assertSame(1, $bruno['presenciais']);
    }

    public function test_participantes_com_cpf_funcao_e_projeto(): void
    {
        $query = http_build_query([
            'grupos' => ['estudantes', 'comissao', 'avaliadores_online', 'organizacao'],
            'escopo' => 'finalistas', 'formato' => 'csv',
        ]);
        $csv = $this->get('/api/v1/admin/certificados/participantes/exportar?'.$query)->assertOk()->getContent();

        $this->assertStringContainsString('"Zuleica Nunes";111.444.777-35;', $csv);
        $this->assertStringContainsString(';Estudante;Biofiltro;Sim', $csv);
        // Só finalistas: o estudante do projeto que ficou de fora não entra.
        $this->assertStringNotContainsString('Wagner Lima', $csv);
        $this->assertStringContainsString('"Comissão especial de avaliação"', $csv);
        $this->assertStringContainsString('"Avaliação de 2 projeto(s) na fase online"', $csv);
        $this->assertStringContainsString('"Olga Organizadora";333.444.555-66', $csv);

        // Todos os submetidos: entra, marcado como não finalista.
        $todos = $this->get('/api/v1/admin/certificados/participantes/exportar?'.http_build_query([
            'grupos' => ['estudantes'], 'escopo' => 'submetidos', 'formato' => 'csv',
        ]))->getContent();
        $this->assertStringContainsString(';Estudante;Compostagem;Não', $todos);
    }

    public function test_voluntarios_entram_com_o_setor(): void
    {
        app(ContaTemporariaService::class)->criar([
            'name' => 'Vera Voluntária', 'email' => 'vera@balcao.test', 'password' => 'senha-qualquer',
            'cpf' => '52998224725', 'curso' => 'Pedagogia', 'horas' => 5,
        ], null, 'almoxarifado');

        $csv = $this->get('/api/v1/admin/certificados/participantes/exportar?'.http_build_query([
            'grupos' => ['voluntarios'], 'escopo' => 'finalistas', 'formato' => 'csv',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('"Vera Voluntária";529.982.247-25;vera@balcao.test;"Voluntário(a) — Almoxarifado"', $csv);
    }

    public function test_declaracao_nominal_lista_os_titulos_por_fase(): void
    {
        $this->getJson("/api/v1/admin/certificados/avaliadores/{$this->ana->id}/projetos")
            ->assertOk()
            ->assertJsonCount(2, 'data.online')
            ->assertJsonCount(1, 'data.presencial')
            ->assertJsonPath('data.presencial.0.titulo', 'Biofiltro');

        $pdf = $this->get("/api/v1/admin/certificados/avaliadores/{$this->ana->id}/declaracao")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $nominais = $this->ler($this->get('/api/v1/admin/certificados/avaliacoes/exportar?fase=todas&formato=xlsx')->getContent());
        $this->assertCount(5, $nominais); // cabeçalho + 2 online da Ana + 1 presencial da Ana + 1 do Bruno
    }

    public function test_cpf_do_admin_e_opcional_no_cadastro(): void
    {
        $this->postJson('/api/v1/admin/admins', [
            'name' => 'Paula', 'email' => 'paula@fetec.test', 'cpf' => '529.982.247-25',
            'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1',
        ])->assertCreated()->assertJsonPath('data.cpf', '52998224725');

        $this->postJson('/api/v1/admin/admins', [
            'name' => 'Rui', 'email' => 'rui@fetec.test', 'cpf' => '123',
            'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1',
        ])->assertStatus(422)->assertJsonValidationErrors('cpf');
    }
}
