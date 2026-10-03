<?php

namespace Tests\Feature;

use App\Models\ContaTemporaria;
use App\Models\User;
use App\Services\ContaTemporariaService;
use App\Support\LeitorPlanilha;
use App\Support\PlanilhaXlsx;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 155 — contas temporárias **em lote** (modelo → prévia → confirmação)
 * e **remoção** de conta, nas quatro abas que têm balcão.
 */
class ContaTemporariaLoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /** Um CPF válido a partir de nove dígitos-base. */
    private function cpf(int $base): string
    {
        $d = str_pad((string) $base, 9, '0', STR_PAD_LEFT);

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $d[$i] * (($t + 1) - $i);
            }
            $d .= ((10 * $soma) % 11) % 10;
        }

        return $d;
    }

    /** @param  list<list<string|int|float|null>>  $linhas */
    private function xlsx(array $cabecalho, array $linhas): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'lote').'.xlsx';
        file_put_contents($caminho, PlanilhaXlsx::gerar('Contas', $cabecalho, $linhas));

        return new UploadedFile($caminho, 'equipe.xlsx', null, null, true);
    }

    private function csv(string $conteudo, string $nome = 'equipe.csv'): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'lote');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, 'text/csv', null, true);
    }

    public function test_modelo_e_uma_planilha_com_o_cabecalho_do_setor(): void
    {
        $resposta = $this->get('/api/v1/admin/credenciamento/contas/modelo')->assertOk();
        $caminho = tempnam(sys_get_temp_dir(), 'modelo');
        file_put_contents($caminho, $resposta->getContent());

        $linhas = LeitorPlanilha::ler($caminho, 'xlsx');

        $this->assertCount(1, $linhas);
        $this->assertSame('Nome completo', $linhas[0][0]);
        $this->assertStringStartsWith('Início do acesso', $linhas[0][4]);

        // O voluntário da avaliação presencial informa turnos, não início/horas.
        $presencial = $this->get('/api/v1/admin/presencial/contas/modelo')->assertOk();
        file_put_contents($caminho, $presencial->getContent());
        $this->assertStringStartsWith('Turnos', LeitorPlanilha::ler($caminho, 'xlsx')[0][4]);
    }

    public function test_previa_aponta_o_que_passa_e_o_que_nao_passa(): void
    {
        User::factory()->create(['email' => 'ja.existe@balcao.test']);

        $arquivo = $this->xlsx(
            ['Nome completo', 'E-mail', 'CPF', 'Curso', 'Início do acesso', 'Horas de acesso'],
            [
                ['Ana Lima', 'ana@balcao.test', $this->cpf(123456789), 'Biologia', '', ''],
                ['Bruno Dias', 'bruno@balcao.test', '11111111111', 'Química', '', ''],
                ['Carla Souza', 'ja.existe@balcao.test', $this->cpf(223456789), 'Física', '', ''],
                ['Ana Repetida', 'ana@balcao.test', $this->cpf(323456789), 'Biologia', '', ''],
                ['', 'sem.nome@balcao.test', $this->cpf(423456789), 'Letras', '', '3'],
            ],
        );

        $resposta = $this->post('/api/v1/admin/credenciamento/contas/lote/previa', ['arquivo' => $arquivo, 'padroes' => ['horas' => 6]])
            ->assertOk()
            ->assertJsonPath('data.validas', 1)
            ->assertJsonPath('data.invalidas', 4);

        $linhas = collect($resposta->json('data.linhas'))->keyBy('linha');
        $this->assertSame([], $linhas[2]['erros']);
        $this->assertSame(6, $linhas[2]['horas']);
        $this->assertStringContainsString('CPF', implode(' ', $linhas[3]['erros']));
        $this->assertStringContainsString('já tem conta', implode(' ', $linhas[4]['erros']));
        $this->assertStringContainsString('repetido na planilha (linha 2)', implode(' ', $linhas[5]['erros']));
        $this->assertNotEmpty($linhas[6]['erros']);

        // A prévia não cria nada.
        $this->assertSame(0, ContaTemporaria::count());
    }

    public function test_csv_do_excel_em_portugues_com_cpf_sem_zero_da_frente(): void
    {
        $cpf = $this->cpf(12345678); // começa com zero
        $this->assertSame('0', $cpf[0]);

        // Excel em português: ";" e Windows-1252.
        $conteudo = mb_convert_encoding(
            "Nome completo;E-mail;CPF;Curso\nJoão Conceição;joao@balcao.test;".ltrim($cpf, '0').";Educação Física\n",
            'Windows-1252',
            'UTF-8',
        );

        $this->post('/api/v1/admin/credenciamento/contas/lote/previa', ['arquivo' => $this->csv($conteudo)])
            ->assertOk()
            ->assertJsonPath('data.validas', 1)
            ->assertJsonPath('data.linhas.0.name', 'João Conceição')
            ->assertJsonPath('data.linhas.0.cpf', $cpf)
            ->assertJsonPath('data.linhas.0.curso', 'Educação Física');
    }

    public function test_data_que_o_excel_transformou_em_numero_e_entendida(): void
    {
        // 46300.375 = 03/10/2026 09:00 no calendário do Excel.
        $serial = 46300 + 0.375;
        $esperado = gmdate('Y-m-d H:i', (int) round(($serial - 25569) * 86400));
        $this->travelTo(now()->setDate(2026, 9, 1));

        $arquivo = $this->xlsx(
            ['Nome completo', 'E-mail', 'CPF', 'Curso', 'Início do acesso', 'Horas de acesso'],
            [['Ana Lima', 'ana@balcao.test', $this->cpf(123456789), 'Biologia', $serial, 4]],
        );

        $this->post('/api/v1/admin/credenciamento/contas/lote/previa', ['arquivo' => $arquivo])
            ->assertOk()
            ->assertJsonPath('data.linhas.0.valido_de', $esperado)
            ->assertJsonPath('data.linhas.0.horas', 4)
            ->assertJsonPath('data.linhas.0.erros', []);
    }

    public function test_lote_cria_as_validas_e_devolve_a_planilha_de_acesso(): void
    {
        $linhas = [
            ['linha' => 2, 'name' => 'Ana Lima', 'email' => 'ana@balcao.test', 'cpf' => $this->cpf(123456789), 'curso' => 'Biologia'],
            ['linha' => 3, 'name' => 'Bruno Dias', 'email' => 'bruno@balcao.test', 'cpf' => $this->cpf(223456789), 'curso' => 'Química', 'horas' => 8],
            // Chegou inválida da tela: é pulada, não derruba as outras.
            ['linha' => 4, 'name' => 'Sem CPF', 'email' => 'semcpf@balcao.test', 'cpf' => '123', 'curso' => 'Física'],
        ];

        $resposta = $this->postJson('/api/v1/admin/almoxarifado/contas/lote', ['linhas' => $linhas, 'padroes' => ['horas' => 5]])
            ->assertCreated()
            ->assertJsonCount(2, 'data.contas')
            ->assertJsonCount(2, 'meta.criadas')
            ->assertJsonCount(1, 'meta.ignoradas')
            ->assertJsonPath('meta.ignoradas.0.linha', 4);

        $this->assertSame(2, ContaTemporaria::where('setor', 'almoxarifado')->count());

        // A senha devolvida é a que abre a conta — e só existe na resposta.
        $criada = collect($resposta->json('meta.criadas'))->firstWhere('email', 'ana@balcao.test');
        $this->assertTrue(Hash::check($criada['senha'], User::where('email', 'ana@balcao.test')->value('password')));

        $caminho = tempnam(sys_get_temp_dir(), 'acessos');
        file_put_contents($caminho, base64_decode($resposta->json('meta.arquivo.base64')));
        $planilha = LeitorPlanilha::ler($caminho, 'xlsx');
        $this->assertSame(['Nome', 'E-mail', 'Senha'], array_slice($planilha[0], 0, 3));
        $this->assertCount(3, $planilha);

        // Horas da linha valem mais que o padrão da tela.
        $bruno = ContaTemporaria::whereHas('user', fn ($q) => $q->where('email', 'bruno@balcao.test'))->first();
        $this->assertEqualsWithDelta(8, now()->floatDiffInHours($bruno->expira_em), 0.1);
    }

    public function test_voluntarios_vem_com_turnos_da_planilha(): void
    {
        $amanha = now()->addDay()->format('d/m/Y');
        $depois = now()->addDays(2)->format('d/m/Y');

        $arquivo = $this->xlsx(
            ['Nome completo', 'E-mail', 'CPF', 'Curso', 'Turnos'],
            [['Vera Voluntária', 'vera@balcao.test', $this->cpf(123456789), 'Pedagogia', "{$amanha} 08:00-12:00; {$depois} 13:00-17:00"]],
        );

        $previa = $this->post('/api/v1/admin/presencial/contas/lote/previa', ['arquivo' => $arquivo])
            ->assertOk()
            ->assertJsonPath('data.validas', 1)
            ->assertJsonCount(2, 'data.linhas.0.turnos');

        $this->postJson('/api/v1/admin/presencial/contas/lote', ['linhas' => $previa->json('data.linhas')])
            ->assertCreated();

        $conta = ContaTemporaria::where('setor', 'avaliacao_presencial')->first();
        $this->assertCount(2, $conta->turnos);
    }

    public function test_turno_mal_escrito_vira_erro_da_linha(): void
    {
        $arquivo = $this->xlsx(
            ['Nome completo', 'E-mail', 'CPF', 'Curso', 'Turnos'],
            [['Vera Voluntária', 'vera@balcao.test', $this->cpf(123456789), 'Pedagogia', 'sábado de manhã']],
        );

        $this->post('/api/v1/admin/presencial/contas/lote/previa', ['arquivo' => $arquivo])
            ->assertOk()
            ->assertJsonPath('data.invalidas', 1);
    }

    public function test_planilha_sem_cabecalho_reconhecivel_e_recusada(): void
    {
        $this->post('/api/v1/admin/credenciamento/contas/lote/previa', [
            'arquivo' => $this->csv("a;b;c\n1;2;3\n"),
        ])->assertStatus(422)->assertJsonValidationErrors('arquivo');
    }

    public function test_conta_sem_atendimento_e_apagada(): void
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Linha Errada', 'email' => 'errada@balcao.test', 'password' => 'senha-qualquer',
            'cpf' => $this->cpf(123456789), 'curso' => 'Biologia', 'horas' => 3,
        ], null, 'credenciamento');

        $this->deleteJson("/api/v1/admin/credenciamento/contas/{$conta->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.contas');

        $this->assertNull(User::where('email', 'errada@balcao.test')->first());
        $this->assertSame(0, ContaTemporaria::count());
    }

    public function test_conta_que_ja_atendeu_e_arquivada_e_o_nome_fica(): void
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Bruna Atendente', 'email' => 'bruna@balcao.test', 'password' => 'senha-do-balcao',
            'cpf' => $this->cpf(123456789), 'curso' => 'Biologia', 'horas' => 3,
        ], null, 'credenciamento');

        DB::table('registros_atividade')->insert([
            'tipo' => 'credenciamento_realizado', 'user_id' => $conta->user_id,
            'autor_email' => 'bruna@balcao.test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/admin/credenciamento/contas/{$conta->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.contas');

        $user = User::find($conta->user_id);
        $this->assertSame('Bruna Atendente', $user->name);
        $this->assertFalse($user->is_active);
        $this->assertNotSame('bruna@balcao.test', $user->email);
        $this->assertNotNull($conta->fresh()->removida_em);

        // A senha antiga não abre mais, e o e-mail ficou livre para um cadastro novo.
        $this->assertFalse(Hash::check('senha-do-balcao', $user->password));
        $this->postJson('/api/v1/admin/credenciamento/contas', [
            'name' => 'Bruna Atendente', 'email' => 'bruna@balcao.test',
            'password' => 'outra-senha-1', 'password_confirmation' => 'outra-senha-1',
            'cpf' => $this->cpf(123456789), 'curso' => 'Biologia',
        ])->assertCreated();

        // Removida não se remove de novo.
        $this->deleteJson("/api/v1/admin/credenciamento/contas/{$conta->id}")->assertNotFound();
    }

    public function test_cada_aba_so_remove_as_suas(): void
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Do Almoxarifado', 'email' => 'almox@balcao.test', 'password' => 'senha-qualquer',
            'cpf' => $this->cpf(123456789), 'curso' => 'Biologia', 'horas' => 3,
        ], null, 'almoxarifado');

        $this->deleteJson("/api/v1/admin/credenciamento/contas/{$conta->id}")->assertNotFound();
        $this->assertNotNull($conta->fresh());
    }
}
