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
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\FotografiaEstande;
use App\Models\ListaFinal;
use App\Models\ListaPresencial;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ContaTemporariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Imagens;
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

    /** Premia até o `$n`º lugar nas duas áreas da FETECMS. */
    private function cotaPorArea(?int $n): array
    {
        return ['categorias' => ['fetecms' => ['areas' => [
            $this->agr->id => ['lugares' => $n],
            $this->bio->id => ['lugares' => $n],
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

        // A do 1º lugar é contida na do 2º (que leva os dois empatados em 2º).
        $this->assertSame(['Abelhas', 'Bioinsumo', 'Compostagem', 'Enzimas', 'Fungos'], array_column($resposta->json('data.itens'), 'titulo'));
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

    public function test_rascunho_inclui_e_retira_sem_justificativa(): void
    {
        $p = $this->cenario();
        $id = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->json('data.lista.id');

        $this->postJson("/api/v1/admin/presencial/listas/{$id}/projetos", ['projeto_id' => $p['d']->id])
            ->assertOk()
            ->assertJsonPath('data.lista.versao', 1)
            ->assertJsonCount(3, 'data.itens');
        $drone = collect($this->getJson("/api/v1/admin/presencial/listas/{$id}")->json('data.itens'))->firstWhere('titulo', 'Drone na lavoura');
        $this->assertTrue($drone['manual']);
        // Com Abelhas (9) no grupo, o Drone (6) é o 2º de Agrárias.
        $this->assertSame(2, $drone['colocacao']);

        $this->deleteJson("/api/v1/admin/presencial/listas/{$id}/projetos/{$p['a']->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.itens');
        $this->assertFalse(RegistroAtividade::where('tipo', TipoRegistro::ListaPresencialProjetoAdicionado->value)->exists());

        $this->postJson("/api/v1/admin/presencial/listas/{$id}/projetos", ['projeto_id' => $p['d']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('projeto_id');
    }

    public function test_lista_gerada_pede_justificativa_sobe_versao_e_registra(): void
    {
        $p = $this->cenario();
        $id = $this->postJson('/api/v1/admin/presencial/listas', [...$this->cotaPorArea(1), 'tipo' => 'final'])->json('data.lista.id');
        $this->postJson("/api/v1/admin/presencial/listas/{$id}/gerar")->assertOk();

        $this->postJson("/api/v1/admin/presencial/listas/{$id}/projetos", ['projeto_id' => $p['b']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('justificativa');

        $this->postJson("/api/v1/admin/presencial/listas/{$id}/projetos", ['projeto_id' => $p['b']->id, 'justificativa' => 'Empate técnico.'])
            ->assertOk()
            ->assertJsonPath('data.lista.versao', 2);
        $this->deleteJson("/api/v1/admin/presencial/listas/{$id}/projetos/{$p['e']->id}", ['justificativa' => 'Desclassificado.'])
            ->assertOk()
            ->assertJsonPath('data.lista.versao', 3);

        $incluido = RegistroAtividade::where('tipo', TipoRegistro::ListaPresencialProjetoAdicionado->value)->first();
        $this->assertSame('Bioinsumo', $incluido->detalhes['para']);
        $this->assertSame('Empate técnico.', $incluido->detalhes['justificativa']);
        $retirado = RegistroAtividade::where('tipo', TipoRegistro::ListaPresencialProjetoRemovido->value)->first();
        $this->assertSame('Enzimas', $retirado->detalhes['de']);
    }

    public function test_so_finalista_entra_na_lista(): void
    {
        $this->cenario();
        $id = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->json('data.lista.id');
        $fora = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id]);

        $this->postJson("/api/v1/admin/presencial/listas/{$id}/projetos", ['projeto_id' => $fora->id])
            ->assertUnprocessable()->assertJsonValidationErrors('projeto_id');
    }
    // --- Premiações (Sprint 253) ---------------------------------------------

    private function credencial(string $nome, array $projetos = [], string $tipo = 'credencial', bool $ativa = true, int $ordem = 0, ?string $orgao = null): Credencial
    {
        $c = Credencial::create([
            'edicao_id' => $this->edicao->id, 'tipo' => $tipo, 'nome' => $nome, 'orgao' => $orgao, 'ativa' => $ativa, 'ordem' => $ordem,
        ]);
        $c->projetos()->attach(collect($projetos)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['atribuida_em' => now()]])->all());

        return $c;
    }

    public function test_a_lista_nasce_com_a_copia_das_credenciais_e_premios_ativos(): void
    {
        $p = $this->cenario();
        $fora = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id]);
        $this->credencial('MOSTRATEC', [$p['d'], $fora], orgao: 'Fundação Liberato');
        $this->credencial('Destaque da feira', [$p['f']], 'premio', ordem: 1);
        $this->credencial('Vaga sem ninguém', [], ordem: 2);
        $this->credencial('Desativada', [$p['a']], ativa: false);

        $premiacoes = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->assertCreated()->json('data.premiacoes');

        $this->assertSame(['MOSTRATEC', 'Destaque da feira', 'Vaga sem ninguém'], array_column($premiacoes, 'nome'));
        $this->assertSame('MOSTRATEC — Fundação Liberato', $premiacoes[0]['titulo']);
        // Só finalista entra na cópia.
        $this->assertSame(['Drone na lavoura'], array_column($premiacoes[0]['itens'], 'titulo'));
        $this->assertSame('FET.AGR-004', $premiacoes[0]['itens'][0]['codigo']);
        $this->assertSame('premio', $premiacoes[1]['tipo']);
        $this->assertSame([], $premiacoes[2]['itens']);
    }

    public function test_editar_a_premiacao_da_lista_nao_mexe_em_credenciais_e_premios(): void
    {
        $p = $this->cenario();
        $mostratec = $this->credencial('MOSTRATEC', [$p['d']]);
        $resposta = $this->postJson('/api/v1/admin/presencial/listas', [...$this->cotaPorArea(1), 'tipo' => 'final'])->json('data');
        $id = $resposta['lista']['id'];
        $premio = $resposta['premiacoes'][0]['id'];

        $this->postJson("/api/v1/admin/presencial/listas/{$id}/premiacoes/{$premio}/projetos", ['projeto_id' => $p['e']->id])
            ->assertOk()
            ->assertJsonCount(2, 'data.premiacoes.0.itens');
        $this->deleteJson("/api/v1/admin/presencial/listas/{$id}/premiacoes/{$premio}/projetos/{$p['d']->id}")
            ->assertOk()
            ->assertJsonPath('data.premiacoes.0.itens.0.titulo', 'Enzimas');

        $this->assertSame([$p['d']->id], $mostratec->projetos()->pluck('projetos.id')->all());

        // Gerada, pede justificativa e registra com o nome da credencial.
        $this->postJson("/api/v1/admin/presencial/listas/{$id}/gerar")->assertOk();
        $this->postJson("/api/v1/admin/presencial/listas/{$id}/premiacoes/{$premio}/projetos", ['projeto_id' => $p['f']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('justificativa');
        $this->postJson("/api/v1/admin/presencial/listas/{$id}/premiacoes/{$premio}/projetos", ['projeto_id' => $p['f']->id, 'justificativa' => 'Indicação da banca.'])
            ->assertOk()
            ->assertJsonPath('data.lista.versao', 2);
        $registro = RegistroAtividade::where('tipo', TipoRegistro::ListaPresencialProjetoAdicionado->value)->first();
        $this->assertStringContainsString('MOSTRATEC', $registro->detalhes['campo']);
    }

    public function test_recarregar_traz_o_que_esta_em_credenciais_agora(): void
    {
        $p = $this->cenario();
        $mostratec = $this->credencial('MOSTRATEC');
        $id = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->json('data.lista.id');

        $mostratec->projetos()->attach($p['b']->id, ['atribuida_em' => now()]);
        $this->credencial('Prêmio novo', [$p['c']], 'premio', ordem: 1);

        $premiacoes = $this->postJson("/api/v1/admin/presencial/listas/{$id}/premiacoes/recarregar")->assertOk()->json('data.premiacoes');

        $this->assertSame(['MOSTRATEC', 'Prêmio novo'], array_column($premiacoes, 'nome'));
        $this->assertSame('Bioinsumo', $premiacoes[0]['itens'][0]['titulo']);
    }

    public function test_lista_so_com_premiacoes_pode_ser_gerada_e_a_uniao_junta_a_mesma_credencial(): void
    {
        $p = $this->cenario();
        $mostratec = $this->credencial('MOSTRATEC', [$p['a']]);
        $um = $this->postJson('/api/v1/admin/presencial/listas', ['categorias' => ['fetecms' => ['cota' => ['tipo' => 'fixo', 'valor' => 0]]]])
            ->json('data.lista.id');
        $this->postJson("/api/v1/admin/presencial/listas/{$um}/gerar")->assertOk();

        $mostratec->projetos()->sync([$p['b']->id]);
        $dois = $this->postJson('/api/v1/admin/presencial/listas', ['categorias' => ['fetecms' => ['cota' => ['tipo' => 'fixo', 'valor' => 0]]]])
            ->json('data.lista.id');
        $this->postJson("/api/v1/admin/presencial/listas/{$dois}/gerar")->assertOk();

        $premiacoes = $this->postJson('/api/v1/admin/presencial/listas/uniao', ['listas' => [$um, $dois], 'tipo' => 'final'])
            ->assertCreated()->json('data.premiacoes');

        $this->assertCount(1, $premiacoes);
        $this->assertSame(['Abelhas', 'Bioinsumo'], array_column($premiacoes[0]['itens'], 'titulo'));
    }

    public function test_arquivos_trazem_a_secao_de_premiacoes_no_mesmo_formato(): void
    {
        $p = $this->cenario();
        $this->credencial('MOSTRATEC', [$p['d']], orgao: 'Fundação Liberato');
        $this->credencial('Vaga sem ninguém', ordem: 1);
        $id = $this->postJson('/api/v1/admin/presencial/listas', $this->cotaPorArea(1))->json('data.lista.id');

        $txt = $this->get("/api/v1/admin/presencial/listas/{$id}/exportar/txt")->assertOk()->getContent();
        $this->assertStringContainsString('== PREMIAÇÕES

== [CREDENCIAL] MOSTRATEC — Fundação Liberato

FET.AGR-004 - Drone na lavoura
', $txt);
        $this->assertStringContainsString('== [CREDENCIAL] VAGA SEM NINGUÉM

(nenhum projeto contemplado)', $txt);

        $csv = $this->get("/api/v1/admin/presencial/listas/{$id}/exportar/csv")->assertOk()->getContent();
        $this->assertStringContainsString('Credencial;"MOSTRATEC — Fundação Liberato";;FET.AGR-004;"Drone na lavoura"', $csv);

        $this->assertStringStartsWith('%PDF', $this->get("/api/v1/admin/presencial/listas/{$id}/exportar/pdf")->assertOk()->getContent());
    }
    // --- Slides (Sprint 254) -------------------------------------------------

    private function foto(Projeto $projeto, string $base64 = Imagens::JPEG, string $mime = 'image/jpeg'): void
    {
        $caminho = "fotografias-estandes/{$projeto->id}.img";
        Storage::disk('local')->put($caminho, base64_decode($base64));
        FotografiaEstande::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'caminho' => $caminho, 'mime' => $mime,
            'registrado_em' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function partesDoPptx(string $caminho): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho) === true);
        $partes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $partes[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $partes;
    }

    public function test_slides_na_ordem_do_palco_com_a_foto_do_estande(): void
    {
        Storage::fake('local');
        $p = $this->cenario();
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $p['a']->id, 'turno' => 'A', 'numero' => 42]);
        $this->foto($p['a']);
        $this->foto($p['f'], Imagens::WEBP, 'image/webp');
        $this->credencial('MOSTRATEC', [$p['d']], orgao: 'Fundação Liberato');
        $id = $this->postJson('/api/v1/admin/presencial/listas', [...$this->cotaPorArea(2), 'tipo' => 'final'])->json('data.lista.id');

        // Rascunho não tem slides.
        $this->get("/api/v1/admin/presencial/listas/{$id}/slides")->assertUnprocessable();

        $tela = $this->postJson("/api/v1/admin/presencial/listas/{$id}/gerar")->assertOk()->json('data.slides');
        // Até o 2º lugar: Abelhas, Bioinsumo e Compostagem (empatados em 2º),
        // Enzimas e Fungos; mais o Drone na MOSTRATEC.
        $this->assertSame(6, $tela['premiados']);
        $this->assertSame(18, $tela['slides']);
        $semFoto = collect($tela['sem_foto'])->keyBy('titulo');
        $this->assertFalse($semFoto->has('Abelhas'));
        $this->assertStringContainsString('formato', $semFoto['Fungos']['motivo']);
        $this->assertSame('Sem fotografia registrada', $semFoto['Bioinsumo']['motivo']);

        $resposta = $this->get("/api/v1/admin/presencial/listas/{$id}/slides")->assertOk();
        $resposta->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        $this->assertStringContainsString('.pptx', (string) $resposta->headers->get('Content-Disposition'));
        $partes = $this->partesDoPptx($resposta->baseResponse->getFile()->getPathname());

        $this->assertSame(18, substr_count($partes['ppt/presentation.xml'], '<p:sldId '));
        // Agrárias do 2º para o 1º (empate pelo título), depois Biológicas,
        // depois a credencial.
        $this->assertStringContainsString('2º lugar', $partes['ppt/slides/slide1.xml']);
        $this->assertStringContainsString('Ciências Agrárias · FETECMS', $partes['ppt/slides/slide1.xml']);
        $this->assertStringContainsString('Bioinsumo', $partes['ppt/slides/slide3.xml']);
        $this->assertStringContainsString('Compostagem', $partes['ppt/slides/slide6.xml']);
        $this->assertStringContainsString('1º lugar', $partes['ppt/slides/slide7.xml']);
        $this->assertStringContainsString('Estande 042 · FET.AGR-001', $partes['ppt/slides/slide8.xml']);
        $this->assertArrayHasKey('ppt/media/image8.jpeg', $partes);
        $this->assertStringContainsString('FET.AGR-001', $partes['ppt/slides/slide9.xml']);
        $this->assertStringContainsString('Ciências Biológicas', $partes['ppt/slides/slide10.xml']);
        $this->assertStringContainsString('formato', $partes['ppt/slides/slide11.xml']);
        $this->assertStringContainsString('MOSTRATEC', $partes['ppt/slides/slide16.xml']);
        $this->assertStringContainsString('Fundação Liberato', $partes['ppt/slides/slide16.xml']);
        $this->assertStringContainsString('Sem fotografia registrada', $partes['ppt/slides/slide17.xml']);
        $this->assertStringContainsString('Drone na lavoura', $partes['ppt/slides/slide18.xml']);
    }
    // --- Até o Nº lugar (Sprint 255) ----------------------------------------

    public function test_por_padrao_premia_do_primeiro_ao_quarto_lugar_de_cada_area(): void
    {
        $this->cenario();
        // Agrárias: 9, 8, 8, 6 (4º) e agora 5 (5º).
        $this->nota($this->finalista('Erva-mate', $this->agr), 5);

        $this->getJson('/api/v1/admin/presencial/listas/opcoes')->assertJsonPath('data.lugares_padrao', 4);

        $itens = $this->postJson('/api/v1/admin/presencial/listas', [])->assertCreated()->json('data.itens');

        $agrarias = collect($itens)->where('area', 'Ciências Agrárias');
        $this->assertSame(['Abelhas', 'Bioinsumo', 'Compostagem', 'Drone na lavoura'], $agrarias->pluck('titulo')->all());
        $this->assertSame([1, 2, 2, 4], $agrarias->pluck('colocacao')->all());
        $this->assertCount(2, collect($itens)->where('area', 'Ciências Biológicas'));
    }

    public function test_empate_no_ultimo_lugar_leva_os_dois(): void
    {
        $this->cenario();

        // Até o 2º lugar em Agrárias: Bioinsumo e Compostagem empatam em 2º.
        $itens = $this->postJson('/api/v1/admin/presencial/listas', ['categorias' => ['fetecms' => ['areas' => [
            $this->agr->id => ['lugares' => 2],
        ]]]])->assertCreated()->json('data.itens');

        $this->assertSame(
            ['Abelhas', 'Bioinsumo', 'Compostagem'],
            collect($itens)->where('area', 'Ciências Agrárias')->pluck('titulo')->values()->all(),
        );
        // Biológicas não veio no pedido: segue o padrão (até o 4º).
        $this->assertCount(2, collect($itens)->where('area', 'Ciências Biológicas'));
    }

    public function test_zero_tira_a_area_e_em_branco_nao_limita(): void
    {
        $this->cenario();
        $this->nota($this->finalista('Erva-mate', $this->agr), 5);

        $itens = $this->postJson('/api/v1/admin/presencial/listas', ['categorias' => ['fetecms' => ['areas' => [
            $this->agr->id => ['lugares' => null],
            $this->bio->id => ['lugares' => 0],
        ]]]])->assertCreated()->json('data.itens');

        $this->assertSame(
            ['Abelhas', 'Bioinsumo', 'Compostagem', 'Drone na lavoura', 'Erva-mate'],
            array_column($itens, 'titulo'),
        );

        $this->postJson('/api/v1/admin/presencial/listas', ['categorias' => ['fetecms' => ['areas' => [
            $this->agr->id => ['lugares' => -1],
        ]]]])->assertUnprocessable()->assertJsonValidationErrors("categorias.fetecms.areas.{$this->agr->id}.lugares");
    }
}
