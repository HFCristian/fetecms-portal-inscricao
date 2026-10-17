<?php

namespace Tests\Feature;

use App\Enums\AbaAdmin;
use App\Enums\Categoria;
use App\Enums\StatusPresenca;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\AvaliadorProfile;
use App\Models\ContaTemporaria;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\EstandeProjeto;
use App\Models\Interprete;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ContaTemporariaService;
use App\Support\CodigoParticipante;
use App\Support\EtiquetasPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 218 — Credenciamento → Etiquetas avulsas: reimprimir a etiqueta de um
 * participante da lista final, de um avaliador, de um intérprete ou de um
 * voluntário, ou montar uma personalizada, numa folha já usada.
 */
class EtiquetasAvulsasTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDay(),
        ]);
    }

    private function finalista(bool $demo = false): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create(['name' => 'Marta Orientadora', 'is_demo' => $demo])->id,
            'titulo' => $demo ? 'Projeto de ensaio' : 'Bioplástico',
            'categoria' => Categoria::Fetecms,
            'area_id' => Area::create(['nome' => 'Ciências Agrárias '.($demo ? 'D' : ''), 'sigla' => 'AGR'])->id,
            'edicao_id' => $this->edicao->id,
        ]);
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => $demo ? 'Davi Ensaio' : 'Ana Aluna', 'cpf' => '52998224725']);

        ListaFinal::firstOrCreate(
            ['edicao_id' => $this->edicao->id, 'vigente' => true, 'demo' => $demo],
            ['nome' => $demo ? 'Demonstração' : 'Oficial', 'versao' => 1],
        )->projetos()->syncWithoutDetaching([$projeto->id => ['manual' => false]]);

        return $projeto;
    }

    private function codigoDaAluna(Projeto $projeto): string
    {
        $aluno = $projeto->alunos()->first();

        return CodigoParticipante::montar(2026, $projeto->id, $aluno->cpf, CodigoParticipante::PAPEL_ALUNO, $aluno->id);
    }

    private function contaTemporaria(string $setor = 'credenciamento', string $email = 'balcao@fetec.test'): ContaTemporaria
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Bruna Atendente',
            'email' => $email,
            'password' => 'senha-do-balcao',
            'cpf' => '52998224725',
            'curso' => 'Ciência da Computação',
            'horas' => 5,
        ], null, $setor);

        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();

        return $conta->refresh();
    }

    public function test_busca_participante_da_lista_final_por_nome_e_por_codigo(): void
    {
        $projeto = $this->finalista();
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => 'A', 'numero' => 42]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $linhas = $this->getJson('/api/v1/admin/credenciamento/etiquetas/busca?grupo=participantes&q=ana')
            ->assertOk()
            ->assertJsonPath('meta.por_folha', 16)
            ->json('data');

        $this->assertCount(1, $linhas);
        $this->assertSame('Ana Aluna', $linhas[0]['nome']);
        $this->assertSame($this->codigoDaAluna($projeto), $linhas[0]['codigo']);
        $this->assertSame($linhas[0]['codigo'], $linhas[0]['id']);
        $this->assertSame('Turno A (matutino)', $linhas[0]['turno']);
        $this->assertSame(42, $linhas[0]['estande']);
        $this->assertSame('Bioplástico', $linhas[0]['detalhe']);

        // O código do crachá também acha a pessoa.
        $this->getJson('/api/v1/admin/credenciamento/etiquetas/busca?grupo=participantes&q='.$this->codigoDaAluna($projeto))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_avaliador_ganha_codigo_de_papel_v_e_inativo_fica_de_fora(): void
    {
        $avaliador = User::factory()->avaliador()->create(['name' => 'Vera Avaliadora']);
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'cpf' => '39053344705']);
        User::factory()->avaliador()->create(['name' => 'Vera Inativa', 'is_active' => false]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $linhas = $this->getJson('/api/v1/admin/credenciamento/etiquetas/busca?grupo=avaliadores&q=vera')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $linhas);
        $this->assertSame("2026-0-390-V{$avaliador->id}", $linhas[0]['codigo']);
        $this->assertSame('Avaliador(a)', $linhas[0]['papel_label']);
        // O e-mail serve à busca, mas não volta para a tela.
        $this->assertArrayNotHasKey('email', $linhas[0]);
    }

    public function test_voluntario_ganha_codigo_de_papel_t_com_o_setor(): void
    {
        $conta = $this->contaTemporaria('almoxarifado');
        $removida = $this->contaTemporaria('credenciamento', 'removida@fetec.test');
        $removida->forceFill(['removida_em' => now()])->save();
        Sanctum::actingAs(User::factory()->admin()->create());

        $linhas = $this->getJson('/api/v1/admin/credenciamento/etiquetas/busca?grupo=voluntarios')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $linhas);
        $this->assertSame("2026-0-529-T{$conta->user_id}", $linhas[0]['codigo']);
        $this->assertSame('Voluntário(a)', $linhas[0]['papel_label']);
        $this->assertStringContainsString('Almoxarifado', $linhas[0]['detalhe']);
    }

    public function test_interprete_reaproveita_o_codigo_i(): void
    {
        $interprete = Interprete::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Helena Intérprete', 'cpf' => '52998224725', 'tipos' => ['libras'],
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $linhas = $this->getJson('/api/v1/admin/credenciamento/etiquetas/busca?grupo=interpretes&q=helena')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $linhas);
        $this->assertSame($interprete->codigo(), $linhas[0]['codigo']);
    }

    public function test_gera_o_pdf_e_registra_quem_imprimiu_e_de_quem(): void
    {
        $projeto = $this->finalista();
        $avaliador = User::factory()->avaliador()->create(['name' => 'Vera Avaliadora']);
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id]);
        $admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
        Sanctum::actingAs($admin);

        $resposta = $this->postJson('/api/v1/admin/credenciamento/etiquetas', [
            'inicio' => 5,
            'itens' => [
                ['grupo' => 'participantes', 'id' => $this->codigoDaAluna($projeto)],
                ['grupo' => 'avaliadores', 'id' => $avaliador->id],
                ['grupo' => 'personalizada', 'nome' => 'Convidado Especial', 'papel' => 'Palestrante', 'turno' => 'B', 'estande' => 7],
            ],
        ])->assertOk();

        $this->assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $resposta->getContent());

        $registro = RegistroAtividade::where('tipo', TipoRegistro::CredenciamentoEtiquetasAvulsas)->sole();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame(5, $registro->detalhes['inicio']);
        $this->assertSame([
            'Ana Aluna (Aluno(a))',
            'Vera Avaliadora (Avaliador(a))',
            'Convidado Especial (Palestrante)',
        ], $registro->detalhes['pessoas']);
        $this->assertSame('credenciamento', $registro->tipo->secao());
    }

    public function test_participante_fora_da_lista_barra_a_geracao(): void
    {
        $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/credenciamento/etiquetas', [
            'itens' => [['grupo' => 'participantes', 'id' => '2026-999-123-A1']],
        ])->assertStatus(422)->assertJsonValidationErrors('itens.0.id');

        $this->assertSame(0, RegistroAtividade::count());
    }

    public function test_personalizada_exige_nome_e_codigo_em_ascii(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/credenciamento/etiquetas', [
            'itens' => [['grupo' => 'personalizada', 'nome' => '']],
        ])->assertStatus(422)->assertJsonValidationErrors('itens.0.nome');

        $this->postJson('/api/v1/admin/credenciamento/etiquetas', [
            'itens' => [['grupo' => 'personalizada', 'nome' => 'Fulano', 'codigo' => 'AÇÃO-1']],
        ])->assertStatus(422)->assertJsonValidationErrors('itens.0.codigo');

        $this->postJson('/api/v1/admin/credenciamento/etiquetas', [
            'inicio' => 17,
            'itens' => [['grupo' => 'personalizada', 'nome' => 'Fulano']],
        ])->assertStatus(422)->assertJsonValidationErrors('inicio');

        // Sem código, sai só o texto.
        $this->postJson('/api/v1/admin/credenciamento/etiquetas', [
            'itens' => [['grupo' => 'personalizada', 'nome' => 'Fulano']],
        ])->assertOk();
    }

    public function test_conta_temporaria_do_credenciamento_gera_etiqueta(): void
    {
        $projeto = $this->finalista();
        Sanctum::actingAs($this->contaTemporaria()->user);

        $this->getJson('/api/v1/admin/credenciamento/etiquetas/busca?grupo=participantes')->assertOk();
        $this->postJson('/api/v1/admin/credenciamento/etiquetas', [
            'itens' => [['grupo' => 'participantes', 'id' => $this->codigoDaAluna($projeto)]],
        ])->assertOk();
    }

    public function test_admin_sem_a_aba_credenciamento_e_barrado(): void
    {
        User::factory()->admin()->create(); // guardião do acesso total
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => [AbaAdmin::Projetos->value]]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/credenciamento/etiquetas/busca?grupo=participantes')->assertForbidden();
    }

    public function test_modo_de_teste_busca_na_lista_demo(): void
    {
        $this->finalista();
        $this->finalista(demo: true);
        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $nomes = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'nome');

        $ensaio = $nomes('/api/v1/admin/credenciamento/etiquetas/busca?grupo=participantes&teste=1');
        $this->assertContains('Davi Ensaio', $ensaio);
        $this->assertNotContains('Ana Aluna', $ensaio);

        $oficial = $nomes('/api/v1/admin/credenciamento/etiquetas/busca?grupo=participantes');
        $this->assertContains('Ana Aluna', $oficial);
        $this->assertNotContains('Davi Ensaio', $oficial);
    }

    public function test_balcao_recusa_o_cracha_de_avaliador_e_de_voluntario_dizendo_de_quem_e(): void
    {
        $this->finalista();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/credenciamento/codigo', ['codigo' => '2026-0-390-V12'])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Este crachá é de avaliador(a), não de alguém da equipe de um projeto finalista.');

        $this->postJson('/api/v1/admin/credenciamento/codigo', ['codigo' => '2026-0-529-T3'])
            ->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Este crachá é de voluntário(a), não de alguém da equipe de um projeto finalista.');
    }

    public function test_codigo_le_os_papeis_novos(): void
    {
        $lido = CodigoParticipante::ler('2026-0-390-v12');

        $this->assertSame('V', $lido['papel']);
        $this->assertSame(12, $lido['participante_id']);
        $this->assertSame('T', CodigoParticipante::ler('2026-0-529-T3')['papel']);
        $this->assertNull(CodigoParticipante::recusaForaDaEquipe(CodigoParticipante::ler('2026-4-529-A3')));
    }

    public function test_pdf_comeca_na_posicao_escolhida_e_vira_a_folha(): void
    {
        $uma = [['papel_label' => 'Aluno(a)', 'nome' => 'Ana', 'codigo' => '2026-1-123-A1']];
        $semCodigo = [['papel_label' => '', 'nome' => 'Fulano', 'codigo' => null]];

        $paginas = fn (string $pdf) => preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf);

        $this->assertSame(1, $paginas(EtiquetasPdf::gerar('T', 'S', $uma, 16)));
        // A 16ª posição está ocupada pela primeira; a segunda vai para outra folha.
        $this->assertSame(2, $paginas(EtiquetasPdf::gerar('T', 'S', [...$uma, ...$uma], 16)));
        $this->assertSame(1, $paginas(EtiquetasPdf::gerar('T', 'S', $semCodigo, 1)));
    }
}
