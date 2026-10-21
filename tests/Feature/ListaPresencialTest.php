<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\StatusPresenca;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\ListaPresencial;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Sprint 251 — as listas preliminares e finais do ranking presencial: o
 * recorte por cotas, a colocação por grupo, gerar/ativar/reativar, a união, o
 * apelido e os arquivos no formato da lista online.
 */
class ListaPresencialTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private ListaFinal $finalOnline;

    private Area $agr;

    private Area $bio;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->finalOnline = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
            'codigos_congelados_em' => now(),
        ]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $this->bio = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function finalista(string $titulo, Area $area, ?string $codigo = null, string $categoria = 'fetecms', ?ListaFinal $lista = null): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'area_id' => $area->id, 'categoria' => $categoria,
        ]);
        ($lista ?? $this->finalOnline)->projetos()->attach($projeto->id, ['codigo' => $codigo]);

        return $projeto;
    }

    private function nota(Projeto $projeto, float $nota): Projeto
    {
        $avaliador = User::factory()->create(['role' => Role::Avaliador->value]);
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'presencial' => true]);

        AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Concluida, 'nota' => $nota, 'vaga' => 'comum', 'concluida_em' => now(),
        ]);

        return $projeto;
    }

    /** Agrárias: A 9, B 8, C 8, D 6. Biológicas: E 7, F 5. */
    private function cenario(): array
    {
        return [
            'a' => $this->nota($this->finalista('Abelhas', $this->agr, 'FET.AGR-001'), 9),
            'b' => $this->nota($this->finalista('Bioinsumo', $this->agr, 'FET.AGR-002'), 8),
            'c' => $this->nota($this->finalista('Compostagem', $this->agr, 'FET.AGR-003'), 8),
            'd' => $this->nota($this->finalista('Drone na lavoura', $this->agr, 'FET.AGR-004'), 6),
            'e' => $this->nota($this->finalista('Enzimas', $this->bio, 'FET.BIO-001'), 7),
            'f' => $this->nota($this->finalista('Fungos', $this->bio, 'FET.BIO-002'), 5),
        ];
    }

    private function cotaPorArea(int $n): array
    {
        return ['categorias' => ['fetecms' => ['areas' => [
            $this->agr->id => ['cota' => ['tipo' => 'fixo', 'valor' => $n]],
            $this->bio->id => ['cota' => ['tipo' => 'fixo', 'valor' => $n]],
        ]]]];
    }

    public function test_opcoes_contam_os_avaliados_por_categoria_e_area(): void
    {
        $this->cenario();
        $this->finalista('Sem nota ainda', $this->agr);

        $opcoes = $this->getJson('/api/v1/admin/presencial/listas/opcoes')->assertOk()->json('data');

        $this->assertSame(6, $opcoes['total_disponivel']);
        $fetecms = collect($opcoes['categorias'])->firstWhere('value', 'fetecms');
        $this->assertSame(6, $fetecms['disponiveis']);
        $this->assertSame(4, collect($fetecms['areas'])->firstWhere('id', $this->agr->id)['disponiveis']);
        $this->assertSame('Lista final', $opcoes['lista_final']['nome']);
    }

    public function test_rascunho_pelas_cotas_com_colocacao_por_grupo_e_codigo_do_finalista(): void
    {
        $this->cenario();

        $resposta = $this->postJson('/api/v1/admin/presencial/listas', [
            ...$this->cotaPorArea(3), 'tipo' => 'preliminar', 'nome' => 'Pódio por área',
        ])->assertCreated();

        $itens = $resposta->json('data.itens');
        $this->assertSame(['Abelhas', 'Bioinsumo', 'Compostagem', 'Enzimas', 'Fungos'], array_column($itens, 'titulo'));
        // Empate em 8 divide o 2º lugar.
        $this->assertSame([1, 2, 2, 1, 2], array_column($itens, 'colocacao'));
        $this->assertSame('FET.AGR-001', $itens[0]['codigo']);
        $this->assertSame('Pódio por área', $resposta->json('data.lista.nome'));
        $this->assertTrue($resposta->json('data.lista.rascunho'));
        $this->assertFalse($resposta->json('data.lista.vigente'));
        // O que ficou de fora está entre os candidatos, com a média.
        $this->assertSame('Drone na lavoura', $resposta->json('data.candidatos.0.titulo'));
    }

    public function test_cota_da_categoria_total_e_zero(): void
    {
        $this->cenario();
        $this->nota($this->finalista('Jr Robô', $this->agr, null, 'fetec_jr'), 10);

        $itens = $this->postJson('/api/v1/admin/presencial/listas', [
            'total' => ['tipo' => 'fixo', 'valor' => 3],
            'categorias' => ['fetec_jr' => ['cota' => ['tipo' => 'fixo', 'valor' => 0]]],
        ])->assertCreated()->json('data.itens');

        // Jr zerada fica de fora; o total corta nos três melhores da FETECMS.
        $this->assertSame(['Abelhas', 'Bioinsumo', 'Compostagem'], array_column($itens, 'titulo'));

        $itens = $this->postJson('/api/v1/admin/presencial/listas', [
            'categorias' => ['fetecms' => ['cota' => ['tipo' => 'percentual', 'valor' => 50]]],
        ])->assertCreated()->json('data.itens');

        // A porcentagem da categoria é sobre o total avaliado (7): 50% = 4. A
        // FETEC Jr, sem cota, entra inteira.
        $this->assertSame(['Abelhas', 'Bioinsumo', 'Compostagem', 'Enzimas', 'Jr Robô'], array_column($itens, 'titulo'));
    }

    public function test_gerar_final_ativa_sem_mexer_na_lista_final_online(): void
    {
        $this->cenario();
        $id = $this->postJson('/api/v1/admin/presencial/listas', [...$this->cotaPorArea(1), 'tipo' => 'final'])->json('data.lista.id');

        $this->postJson("/api/v1/admin/presencial/listas/{$id}/gerar")
            ->assertOk()
            ->assertJsonPath('data.lista.vigente', true)
            ->assertJsonPath('data.lista.rascunho', false);

        $this->assertSame($id, ListaPresencial::vigente()?->id);
        // A final online continua sendo a que define os finalistas.
        $this->assertSame($this->finalOnline->id, ListaFinal::vigente()?->id);
        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::ListaPresencialGerada->value)->exists());

        // Outra final desativa a primeira; reativar pede justificativa.
        $outra = $this->postJson('/api/v1/admin/presencial/listas', [...$this->cotaPorArea(2), 'tipo' => 'final'])->json('data.lista.id');
        $this->postJson("/api/v1/admin/presencial/listas/{$outra}/gerar")->assertOk();
        $this->assertSame($outra, ListaPresencial::vigente()?->id);

        $this->postJson("/api/v1/admin/presencial/listas/{$id}/reativar", ['justificativa' => ''])->assertUnprocessable();
        $this->postJson("/api/v1/admin/presencial/listas/{$id}/reativar", ['justificativa' => 'Erro na segunda.'])
            ->assertOk()
            ->assertJsonPath('data.lista.vigente', true);
        $this->assertSame($id, ListaPresencial::vigente()?->id);
        $this->assertSame(
            'Erro na segunda.',
            RegistroAtividade::where('tipo', TipoRegistro::ListaPresencialReativada->value)->first()->detalhes['justificativa'],
        );
    }

    public function test_preliminar_nao_vira_ativa_e_lista_vazia_nao_gera(): void
    {
        $this->cenario();
        $id = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->json('data.lista.id');
        $this->postJson("/api/v1/admin/presencial/listas/{$id}/gerar")->assertOk()->assertJsonPath('data.lista.vigente', false);
        $this->postJson("/api/v1/admin/presencial/listas/{$id}/gerar")->assertUnprocessable();
        $this->postJson("/api/v1/admin/presencial/listas/{$id}/reativar", ['justificativa' => 'Não é final.'])->assertUnprocessable();

        $vazia = $this->postJson('/api/v1/admin/presencial/listas', [
            'categorias' => ['fetecms' => ['cota' => ['tipo' => 'fixo', 'valor' => 0]]],
        ])->json('data.lista.id');
        $this->postJson("/api/v1/admin/presencial/listas/{$vazia}/gerar")->assertUnprocessable();
    }

    public function test_uniao_de_listas_geradas_sem_repetir(): void
    {
        $this->cenario();
        $um = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->json('data.lista.id');
        $dois = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(2))->json('data.lista.id');

        // Rascunho não serve de origem.
        $this->postJson('/api/v1/admin/presencial/listas/uniao', ['listas' => [$um], 'tipo' => 'final'])->assertUnprocessable();

        $this->postJson("/api/v1/admin/presencial/listas/{$um}/gerar")->assertOk();
        $this->postJson("/api/v1/admin/presencial/listas/{$dois}/gerar")->assertOk();

        $resposta = $this->postJson('/api/v1/admin/presencial/listas/uniao', [
            'listas' => [$um, $dois], 'tipo' => 'final', 'nome' => 'Junção',
        ])->assertCreated();

        // A de um por área é contida na de dois (o empate em 8 cortou Compostagem).
        $this->assertSame(['Abelhas', 'Bioinsumo', 'Enzimas', 'Fungos'], array_column($resposta->json('data.itens'), 'titulo'));
        $this->assertCount(2, $resposta->json('data.lista.origens'));
        $this->assertSame('final', $resposta->json('data.lista.tipo'));
    }

    public function test_relacao_apelido_e_trilha_de_teste(): void
    {
        $this->cenario();
        $id = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->json('data.lista.id');

        $this->patchJson("/api/v1/admin/presencial/listas/{$id}", ['nome' => 'Primeiros', 'descricao' => 'Um por área.'])
            ->assertOk()
            ->assertJsonPath('data.lista.nome', 'Primeiros')
            ->assertJsonPath('data.lista.descricao', 'Um por área.');
        $this->patchJson("/api/v1/admin/presencial/listas/{$id}", ['nome' => ''])
            ->assertJsonPath('data.lista.nome', 'Lista preliminar presencial · XVI FETECMS');

        $this->getJson('/api/v1/admin/presencial/listas')->assertOk()->assertJsonCount(1, 'data');

        // A conta demo em modo de teste trabalha na lista final demo e não vê as oficiais.
        $demo = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Demo', 'tipo' => 'final', 'vigente' => true, 'demo' => true, 'versao' => 1,
        ]);
        $this->nota($this->finalista('Do ensaio', $this->agr, null, 'fetecms', $demo), 9);
        Sanctum::actingAs(User::factory()->admin()->create(['is_demo' => true]));

        $this->getJson('/api/v1/admin/presencial/listas?teste=1')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/admin/presencial/listas', ['teste' => 1])
            ->assertCreated()
            ->assertJsonPath('data.lista.demo', true)
            ->assertJsonPath('data.itens.0.titulo', 'Do ensaio')
            ->assertJsonCount(1, 'data.itens');
    }

    public function test_sem_lista_final_nao_ha_o_que_listar(): void
    {
        $this->finalOnline->update(['vigente' => false]);

        $this->postJson('/api/v1/admin/presencial/listas', [])->assertUnprocessable()->assertJsonValidationErrors('lista');
    }

    public function test_txt_no_formato_da_lista_online_com_a_colocacao(): void
    {
        $p = $this->cenario();
        Aluno::factory()->create(['projeto_id' => $p['a']->id, 'nome' => 'Zé Silva']);
        Aluno::factory()->create(['projeto_id' => $p['a']->id, 'nome' => 'Ana Lima']);
        $id = $this->postJson('/api/v1/admin/presencial/listas', [...$this->cotaPorArea(1), 'tipo' => 'final'])->json('data.lista.id');

        $txt = $this->get("/api/v1/admin/presencial/listas/{$id}/exportar/txt")->assertOk()->getContent();

        $this->assertStringStartsWith("LISTA FINAL PRESENCIAL · XVI FETECMS\n", $txt);
        $this->assertStringContainsString("== PREMIADOS POR NOTA\n\n== FETECMS · CIÊNCIAS AGRÁRIAS\n\n1º lugar\nFET.AGR-001 - Abelhas\n", $txt);
        // Os estudantes em ordem alfabética e o orientador, como no arquivo online.
        $this->assertMatchesRegularExpression('/Ana Lima\nZé Silva\n.+ - Orientador\(a\)/u', $txt);
        $this->assertStringContainsString("== FETECMS · CIÊNCIAS BIOLÓGICAS\n\n1º lugar\nFET.BIO-001 - Enzimas", $txt);
    }

    public function test_csv_xlsx_e_pdf(): void
    {
        $this->cenario();
        $id = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->json('data.lista.id');

        $csv = $this->get("/api/v1/admin/presencial/listas/{$id}/exportar/csv")->assertOk()->getContent();
        $this->assertStringContainsString('Seção;Premiação;Colocação;Código;Projeto', $csv);
        $this->assertStringContainsString('"Por nota";"1º lugar · Ciências Agrárias · FETECMS";1º;FET.AGR-001;Abelhas', $csv);
        $this->assertStringContainsString(';9,000', $csv);

        $xlsx = $this->get("/api/v1/admin/presencial/listas/{$id}/exportar/xlsx")->assertOk()->getContent();
        $caminho = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($caminho, $xlsx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho) === true);
        $planilha = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($caminho);
        $this->assertStringContainsString('<v>9</v>', $planilha);

        $pdf = $this->get("/api/v1/admin/presencial/listas/{$id}/exportar/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_lista_de_outra_edicao_nao_abre(): void
    {
        $this->cenario();
        $outra = Edicao::create(['nome' => 'XVII', 'ano' => 2027]);
        $lista = ListaPresencial::create(['edicao_id' => $outra->id, 'nome' => 'Outra', 'tipo' => 'final']);

        $this->getJson("/api/v1/admin/presencial/listas/{$lista->id}")->assertNotFound();
    }

    public function test_o_voluntario_nao_chega_nas_listas(): void
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Vera Voluntária', 'email' => 'vera@fetec.test', 'password' => 'senha-do-turno',
            'cpf' => '52998224725', 'curso' => 'Biologia', 'horas' => 5,
        ], null, 'avaliacao_presencial');
        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();
        Sanctum::actingAs($conta->user);

        $this->getJson('/api/v1/admin/presencial/listas')->assertForbidden();
        $this->postJson('/api/v1/admin/presencial/listas', [])->assertForbidden();
    }
}
