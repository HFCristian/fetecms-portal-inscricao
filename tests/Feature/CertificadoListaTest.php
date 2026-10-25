<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\CertificadoLista;
use App\Models\ContaTemporaria;
use App\Models\Coorientador;
use App\Models\Credenciamento;
use App\Models\CredenciamentoPessoa;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\User;
use App\Support\LeitorPlanilha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprints 259–260 — o assistente da lista de certificados: fases, público,
 * carga horária com teto, nomes corrigidos só na lista, a lista gerada
 * congelada e o arquivo no modelo do SICERT.
 */
class CertificadoListaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/certificados';

    private Edicao $edicao;

    private User $admin;

    private User $orientadora;

    private Projeto $biofiltro;

    private Projeto $compostagem;

    private Projeto $manual;

    private Aluno $zuleica;

    private Aluno $xavier;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'avaliacao_liberada_em' => '2026-08-01 08:00:00', 'avaliacao_encerrada_em' => '2026-09-15 18:00:00',
            'evento_de' => '2026-10-20 08:00:00', 'evento_ate' => '2026-10-22 18:00:00',
        ]);

        $this->orientadora = User::factory()->create(['name' => 'Olívia Orientadora', 'email' => 'olivia@escola.test']);
        OrientadorProfile::factory()->create(['user_id' => $this->orientadora->id, 'cpf' => '52998224725', 'telefone' => '67999998888']);

        $this->biofiltro = $this->projeto('Biofiltro', Categoria::Fetecms);
        $this->compostagem = $this->projeto('Compostagem', Categoria::FetecJr);
        // Cadastro manual: não passou pela fase online, só pela presencial.
        $this->manual = $this->projeto('Robô Seguidor', Categoria::Fetecms, ['cadastro_manual' => true]);

        $this->zuleica = Aluno::factory()->create(['projeto_id' => $this->biofiltro->id, 'nome' => 'Zuleica Nunes', 'cpf' => '11144477735', 'telefone' => '6733334444']);
        $this->xavier = Aluno::factory()->create(['projeto_id' => $this->biofiltro->id, 'nome' => 'Xavier Lima', 'cpf' => null]);
        Aluno::factory()->create(['projeto_id' => $this->compostagem->id, 'nome' => 'Wagner Souza']);
        Aluno::factory()->create(['projeto_id' => $this->manual->id, 'nome' => 'Mara Manual']);
        Coorientador::factory()->create(['projeto_id' => $this->biofiltro->id, 'nome' => 'Carlos Coorientador']);

        // Biofiltro credenciado, com o Xavier marcado como ausente; Compostagem
        // ficou em rascunho no balcão; o manual foi credenciado inteiro.
        $credenciamento = Credenciamento::create(['projeto_id' => $this->biofiltro->id, 'iniciado_em' => now(), 'finalizado_em' => now()]);
        CredenciamentoPessoa::create(['credenciamento_id' => $credenciamento->id, 'pessoa_tipo' => 'aluno', 'pessoa_id' => $this->xavier->id, 'pessoa_nome' => 'Xavier Lima', 'presente' => false]);
        Credenciamento::create(['projeto_id' => $this->compostagem->id, 'iniciado_em' => now()]);
        Credenciamento::create(['projeto_id' => $this->manual->id, 'iniciado_em' => now(), 'finalizado_em' => now()]);

        // Ana: 5 avaliações online (12h30, acima de um teto de 10h). Bruno: 1 no estande.
        $this->ana = $this->avaliador('Ana Avaliadora', '52998224725');
        foreach (range(1, 5) as $i) {
            $p = $i <= 2 ? [$this->biofiltro, $this->compostagem][$i - 1] : $this->projeto("Extra {$i}", Categoria::Fetecms);
            Avaliacao::create(['projeto_id' => $p->id, 'avaliador_id' => $this->ana->id, 'status' => StatusAvaliacao::Concluida, 'nota' => 8, 'concluida_em' => now()]);
        }
        $bruno = $this->avaliador('Bruno Avaliador', '11144477735');
        AvaliacaoPresencial::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $this->biofiltro->id, 'avaliador_id' => $bruno->id, 'status' => StatusAvaliacao::Concluida, 'concluida_em' => now()]);

        // Voluntários: Vera com três turnos; Vítor sem turno (a janela conta um).
        $vera = $this->voluntario('Vera Voluntária', ContaTemporaria::SETOR_PRESENCIAL);
        foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $dia) {
            $vera->turnos()->create(['inicio' => "{$dia} 08:00:00", 'fim' => "{$dia} 12:00:00"]);
        }
        $this->voluntario('Vítor Voluntário', ContaTemporaria::SETOR_CREDENCIAMENTO);

        $this->admin = User::factory()->admin()->create(['name' => 'Olga Organizadora', 'cpf' => '33344455566']);
        Sanctum::actingAs($this->admin);
    }

    /** @param  array<string, mixed>  $extra */
    private function projeto(string $titulo, Categoria $categoria, array $extra = []): Projeto
    {
        return Projeto::factory()->submetido()->create($extra + [
            'titulo' => $titulo, 'categoria' => $categoria, 'edicao_id' => $this->edicao->id, 'user_id' => $this->orientadora->id,
        ]);
    }

    private function avaliador(string $nome, string $cpf): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador, 'name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'cpf' => $cpf]);

        return $user;
    }

    private function voluntario(string $nome, string $setor): ContaTemporaria
    {
        $user = User::factory()->admin()->create(['name' => $nome]);

        return ContaTemporaria::create([
            'user_id' => $user->id, 'cpf' => '52998224725', 'curso' => 'Pedagogia', 'setor' => $setor,
            'expira_em' => now()->addDay(),
        ]);
    }

    /**
     * Cria a lista com as duas fases e o público pedido, já com a carga.
     *
     * @param  list<string>  $publicos
     * @param  array<string, array<string, mixed>>  $categorias
     */
    private function lista(array $publicos, array $categorias = [], array $fases = ['online', 'presencial']): int
    {
        return $this->postJson(self::URL.'/listas', [
            'nome' => 'Certificados XVI',
            'passo' => 2,
            'config' => ['fases' => $fases, 'publicos' => $publicos, 'categorias' => $categorias],
        ])->assertCreated()->json('data.id');
    }

    /** @return list<array<string, mixed>> */
    private function pessoas(int $lista, string $categoria): array
    {
        return $this->getJson(self::URL."/listas/{$lista}/categorias/{$categoria}")->assertOk()->json('data');
    }

    /** @return list<list<string>> */
    private function ler(string $conteudo, string $extensao): array
    {
        $caminho = tempnam(sys_get_temp_dir(), 'cert');
        file_put_contents($caminho, $conteudo);

        return LeitorPlanilha::ler($caminho, $extensao);
    }

    public function test_as_fases_definem_as_categorias_e_os_padroes(): void
    {
        $id = $this->lista(['avaliadores_online', 'avaliadores_presencial', 'orientadores', 'voluntarios', 'admins'], fases: ['online']);

        $dados = $this->getJson(self::URL."/listas/{$id}")->assertOk()->json('data');

        // Só fase online: avaliador presencial e voluntário somem; orientador vira "fase online".
        $this->assertSame(['avaliadores_online', 'orientadores', 'admins'], $dados['config']['publicos']);
        $this->assertSame(['avaliadores_online', 'orientadores_online', 'admins'], array_column($dados['categorias'], 'chave'));

        // O avaliador nasce com a regra do perfil; o período vem de Datas e períodos.
        $avaliador = $dados['config']['categorias']['avaliadores_online'];
        $this->assertSame(['por_projeto', 150, 7200], [$avaliador['modo'], $avaliador['minutos'], $avaliador['teto_minutos']]);
        $this->assertSame(['2026-08-01', '2026-09-15'], [$avaliador['data_inicio'], $avaliador['data_fim']]);
        $this->assertNull($dados['config']['categorias']['orientadores_online']['minutos']);
        $this->assertSame('rascunho', $dados['status']);
    }

    public function test_cada_passo_grava_e_o_modo_invalido_volta_ao_padrao(): void
    {
        $id = $this->lista(['orientadores']);

        $this->putJson(self::URL."/listas/{$id}", [
            'nome' => 'Certificados de orientação',
            'passo' => 3,
            'config' => [
                'fases' => ['presencial'], 'publicos' => ['orientadores'],
                'categorias' => ['orientadores_presencial' => ['modo' => 'por_projeto', 'minutos' => 600]],
            ],
        ])->assertOk()
            ->assertJsonPath('data.nome', 'Certificados de orientação')
            ->assertJsonPath('data.passo', 3)
            // Orientador só tem carga fixa.
            ->assertJsonPath('data.config.categorias.orientadores_presencial.modo', 'fixa')
            ->assertJsonPath('data.config.categorias.orientadores_presencial.minutos', 600);

        $this->putJson(self::URL."/listas/{$id}", [
            'config' => [
                'fases' => ['presencial'], 'publicos' => ['orientadores'],
                'categorias' => ['orientadores_presencial' => ['minutos' => 600, 'data_inicio' => '2026-10-22', 'data_fim' => '2026-10-20']],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('config.categorias.orientadores_presencial.data_fim');
    }

    public function test_online_e_a_equipe_submetida_e_presencial_quem_compareceu(): void
    {
        $id = $this->lista(['estudantes', 'orientadores', 'coorientadores']);

        $online = collect($this->pessoas($id, 'estudantes_online'))->pluck('nome_original')->all();
        // O projeto do cadastro manual não passou pela fase online.
        $this->assertEqualsCanonicalizing(['Zuleica Nunes', 'Xavier Lima', 'Wagner Souza'], $online);

        // Presencial: só credenciamento concluído, e o Xavier foi marcado ausente.
        $presencial = collect($this->pessoas($id, 'estudantes_presencial'))->pluck('nome_original')->all();
        $this->assertEqualsCanonicalizing(['Zuleica Nunes', 'Mara Manual'], $presencial);

        // Um certificado por projeto: a orientadora tem os dois do credenciamento concluído.
        $orientacao = collect($this->pessoas($id, 'orientadores_presencial'));
        $this->assertSame(['Biofiltro', 'Robô Seguidor'], $orientacao->pluck('titulo')->sort()->values()->all());
        $this->assertSame(['(67) 99999-8888'], $orientacao->pluck('telefone')->unique()->values()->all());

        $this->assertSame(['Carlos Coorientador'], collect($this->pessoas($id, 'coorientadores_presencial'))->pluck('nome')->all());
    }

    public function test_carga_por_projeto_respeita_o_teto_e_arredonda_para_cima(): void
    {
        $id = $this->lista(['avaliadores_online', 'avaliadores_presencial'], [
            'avaliadores_online' => ['modo' => 'por_projeto', 'minutos' => 150, 'teto_minutos' => 600],
            'avaliadores_presencial' => ['modo' => 'por_projeto', 'minutos' => 90, 'teto_minutos' => null],
        ]);

        $ana = collect($this->pessoas($id, 'avaliadores_online'))->firstWhere('nome', 'Ana Avaliadora');
        $this->assertSame([5, 600, true, 10], [$ana['quantidade'], $ana['minutos'], $ana['teto_aplicado'], $ana['horas']]);
        $this->assertSame('Avaliação de 5 projeto(s)', $ana['titulo']);
        $this->assertSame($this->ana->id, $ana['avaliador_id']);

        // 1 × 1h30 = 2h no arquivo.
        $bruno = $this->pessoas($id, 'avaliadores_presencial')[0];
        $this->assertSame([90, false, 2], [$bruno['minutos'], $bruno['teto_aplicado'], $bruno['horas']]);
    }

    public function test_voluntario_por_turno_e_admin_fixo(): void
    {
        $id = $this->lista(['voluntarios', 'admins'], [
            'voluntarios' => ['modo' => 'por_turno', 'minutos' => 240],
            'admins' => ['minutos' => 1200],
        ]);

        $voluntarios = collect($this->pessoas($id, 'voluntarios'))->keyBy('nome');
        $this->assertSame([3, 720], [$voluntarios['Vera Voluntária']['quantidade'], $voluntarios['Vera Voluntária']['minutos']]);
        $this->assertSame([1, 240], [$voluntarios['Vítor Voluntário']['quantidade'], $voluntarios['Vítor Voluntário']['minutos']]);
        $this->assertSame('Avaliação presencial', $voluntarios['Vera Voluntária']['titulo']);

        // Conta temporária não é da organização.
        $admins = collect($this->pessoas($id, 'admins'));
        $this->assertSame(['Olga Organizadora'], $admins->pluck('nome')->all());
        $this->assertSame(['Organização', 1200, 'Organização da XVI FETECMS'], [$admins[0]['tipo_atividade'], $admins[0]['minutos'], $admins[0]['titulo']]);
        // Sem fase: do começo da online ao fim da presencial.
        $this->assertSame(['2026-08-01', '2026-10-22'], [$admins[0]['data_inicio'], $admins[0]['data_fim']]);
    }

    public function test_nome_corrigido_vale_so_na_lista_e_em_todas_as_categorias_da_pessoa(): void
    {
        $id = $this->lista(['estudantes']);
        $chave = "aluno:{$this->zuleica->id}";

        $this->putJson(self::URL."/listas/{$id}/nomes", ['nomes' => [$chave => '  Zuleica  Nunes da Silva ']])->assertOk();

        foreach (['estudantes_online', 'estudantes_presencial'] as $categoria) {
            $linha = collect($this->pessoas($id, $categoria))->firstWhere('pessoa_chave', $chave);
            $this->assertSame(['Zuleica Nunes da Silva', 'Zuleica Nunes', true], [$linha['nome'], $linha['nome_original'], $linha['corrigido']]);
        }
        $this->assertSame('Zuleica Nunes', $this->zuleica->fresh()->nome);

        // Voltar ao nome do cadastro desfaz a correção.
        $this->putJson(self::URL."/listas/{$id}/nomes", [
            'nomes' => [$chave => 'Zuleica Nunes'], 'originais' => [$chave => 'Zuleica Nunes'],
        ])->assertOk()->assertJsonPath('data.nomes', []);

        $this->putJson(self::URL."/listas/{$id}/nomes", ['nomes' => ['projeto:1' => 'X']])->assertStatus(422);
    }

    public function test_gerar_cobra_a_carga_de_cada_categoria(): void
    {
        $id = $this->lista(['orientadores', 'admins'], ['orientadores_online' => ['minutos' => 300]]);

        $this->postJson(self::URL."/listas/{$id}/gerar")->assertStatus(422)
            ->assertJsonValidationErrors(['config.categorias.orientadores_presencial.minutos', 'config.categorias.admins.minutos'])
            ->assertJsonMissingValidationErrors('config.categorias.orientadores_online.minutos');
    }

    public function test_gerar_congela_a_lista_e_editar_marca_desatualizada(): void
    {
        $id = $this->lista(['estudantes', 'avaliadores_online'], [
            'estudantes_online' => ['minutos' => 300],
            'estudantes_presencial' => ['minutos' => 1200],
        ]);
        $this->putJson(self::URL."/listas/{$id}/nomes", ['nomes' => ["aluno:{$this->zuleica->id}" => 'Zuleica N. Silva']]);

        $this->postJson(self::URL."/listas/{$id}/gerar")->assertOk()
            ->assertJsonPath('data.status', 'gerada')
            ->assertJsonPath('data.gerada_por', 'Olga Organizadora')
            ->assertJsonPath('data.total_certificados', 3 + 2 + 1)
            // Zuleica está na online e na presencial: uma pessoa, dois certificados.
            ->assertJsonPath('data.total_pessoas', 3 + 1 + 1);

        // O retrato não muda com o cadastro.
        Aluno::factory()->create(['projeto_id' => $this->compostagem->id, 'nome' => 'Chegou Depois']);
        $linhas = $this->getJson(self::URL."/listas/{$id}/linhas?categoria=estudantes_online")->assertOk();
        $this->assertSame(3, $linhas->json('meta.total'));
        $this->assertSame('Zuleica N. Silva', collect($linhas->json('data'))->firstWhere('nome_original', 'Zuleica Nunes')['nome']);
        // Na ordem do público: avaliadores primeiro, como no assistente.
        $this->assertSame(['avaliadores_online', 'estudantes_online', 'estudantes_presencial'], array_column($linhas->json('meta.resumo'), 'chave'));

        $this->getJson(self::URL.'/listas')->assertOk()
            ->assertJsonPath('data.0.desatualizada', false)
            ->assertJsonPath('data.0.publicos', ['Avaliadores Online', 'Estudantes']);

        $this->putJson(self::URL."/listas/{$id}", ['config' => [
            'fases' => ['online', 'presencial'], 'publicos' => ['estudantes', 'avaliadores_online'],
            'categorias' => ['estudantes_online' => ['minutos' => 360], 'estudantes_presencial' => ['minutos' => 1200]],
        ]])->assertOk()->assertJsonPath('data.desatualizada', true);

        $this->postJson(self::URL."/listas/{$id}/gerar")->assertOk()
            ->assertJsonPath('data.desatualizada', false)
            ->assertJsonPath('data.total_certificados', 4 + 2 + 1);
    }

    public function test_csv_e_excel_seguem_o_modelo_do_sicert(): void
    {
        $id = $this->lista(['estudantes', 'avaliadores_online'], [
            'estudantes_online' => ['minutos' => 270],
            'estudantes_presencial' => ['minutos' => 1200],
            'avaliadores_online' => ['modo' => 'por_projeto', 'minutos' => 150, 'teto_minutos' => 600],
        ]);
        $this->postJson(self::URL."/listas/{$id}/gerar")->assertOk();

        $csv = $this->get(self::URL."/listas/{$id}/exportar/csv?categoria=estudantes_online")->assertOk()->getContent();
        // CRLF, como o modelo salvo no Windows.
        $linhas = explode("\r\n", trim($csv));

        $this->assertSame(
            'CPF;NOME;E-MAIL;TELEFONE;COAUTORES;FUNCAO;TIPO DA ATIVIDADE;TITULO DO TRABALHO/ATIVIDADE;CAMPO GENERICO;CARGA HORARIA;DATA INICIO;DATA FIM',
            $linhas[0],
        );
        $this->assertCount(4, $linhas);
        // 4h30 sai 5; CPF só dígitos; sem CPF fica vazio.
        $this->assertStringContainsString('11144477735;Zuleica Nunes;', $csv);
        $this->assertStringContainsString(';(67) 3333-4444;;Estudante;Fase online;Biofiltro;FETECMS;5;01/08/2026;15/09/2026', $csv);
        $this->assertMatchesRegularExpression('/^;Xavier Lima;/m', $csv);

        $xlsx = $this->ler($this->get(self::URL."/listas/{$id}/exportar/xlsx")->assertOk()->getContent(), 'xlsx');
        $this->assertSame('CPF', $xlsx[0][0]);
        $ana = collect($xlsx)->first(fn ($l) => $l[1] === 'Ana Avaliadora');
        $this->assertSame(['52998224725', 'Avaliador(a)', 'Fase online', 'Avaliação de 5 projeto(s)', '10'], [$ana[0], $ana[5], $ana[6], $ana[7], $ana[9]]);
    }

    public function test_pdf_e_txt_da_lista_gerada(): void
    {
        $id = $this->lista(['admins'], ['admins' => ['minutos' => 600]]);

        $this->get(self::URL."/listas/{$id}/exportar/pdf")->assertStatus(422);
        $this->postJson(self::URL."/listas/{$id}/gerar")->assertOk();

        $pdf = $this->get(self::URL."/listas/{$id}/exportar/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $txt = $this->get(self::URL."/listas/{$id}/exportar/txt")->assertOk()->getContent();
        $this->assertStringContainsString('== ADMINS CADASTRADOS (1) · 10h fixas', $txt);
        $this->assertStringContainsString('Olga Organizadora · CPF 333.444.555-66 · 10h', $txt);
    }

    public function test_lista_de_outra_edicao_nao_aparece(): void
    {
        $id = $this->lista(['admins']);
        $outra = Edicao::create(['nome' => 'XVII FETECMS', 'ano' => 2027]);
        $this->admin->forceFill(['edicao_id' => $outra->id])->save();

        $this->getJson(self::URL.'/listas')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL."/listas/{$id}")->assertNotFound();
    }

    public function test_aba_fechada_para_quem_nao_tem_o_escopo(): void
    {
        $escopo = EscopoAdmin::create(['nome' => 'Comunicação', 'abas' => ['comunicacao']]);
        $this->admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);

        $this->getJson(self::URL.'/listas')->assertForbidden();
        $this->assertSame(0, CertificadoLista::count());
    }
}
