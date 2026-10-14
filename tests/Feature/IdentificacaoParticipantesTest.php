<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\CodigoParticipante;
use App\Support\EtiquetasPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 123 — **identificação dos participantes** da lista final: o QR Code e o
 * código de barras de cada aluno, orientador e coorientador.
 *
 * O código é derivado (ano + projeto + 3 dígitos do CPF + papel/id), e é por
 * isso que ele sobrevive a uma mudança de versão da lista — o teste guarda essa
 * promessa.
 */
class IdentificacaoParticipantesTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Um finalista com equipe completa: dois alunos, orientador e coorientador. */
    private function finalista(): Projeto
    {
        // O CPF é único no perfil do orientador: cada finalista precisa do seu.
        $sequencia = User::count() + 1;
        $orientador = User::factory()->create(['name' => 'Marta Orientadora']);
        OrientadorProfile::factory()->create([
            'user_id' => $orientador->id,
            'cpf' => str_pad((string) $sequencia, 11, '5', STR_PAD_LEFT),
        ]);

        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id,
            'titulo' => 'Bioplástico de mandioca',
            'edicao_id' => $this->edicao->id,
            'instituicao_id' => Instituicao::create(['nome' => 'EE Maria Constança'])->id,
        ]);

        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Zuleica Nunes', 'cpf' => '11144477735']);
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana Paula', 'cpf' => '22233344405']);
        // (o CPF do aluno é único por projeto, então os mesmos números servem
        //  para o segundo finalista do teste de versão)
        Coorientador::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Caio Silva', 'cpf' => '33344455566']);

        return $projeto;
    }

    private function listaCom(Projeto ...$projetos): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id,
            'nome' => 'Lista oficial',
            'vigente' => true,
            'demo' => false,
            'versao' => 1,
        ]);

        $lista->projetos()->attach(collect($projetos)->pluck('id'));

        return $lista;
    }

    // --- O código ---------------------------------------------------------

    public function test_o_codigo_junta_ano_projeto_cpf_e_papel(): void
    {
        $codigo = CodigoParticipante::montar(2026, 31, '123.456.789-09', CodigoParticipante::PAPEL_ALUNO, 45);

        $this->assertSame('2026-31-123-A45', $codigo);
        $this->assertSame([
            'ano' => 2026, 'projeto_id' => 31, 'cpf3' => '123', 'papel' => 'A', 'participante_id' => 45,
        ], CodigoParticipante::ler($codigo));
    }

    /** O prefixo de papel existe porque os ids das três tabelas se repetem. */
    public function test_mesmo_id_em_papeis_diferentes_da_codigos_diferentes(): void
    {
        $aluno = CodigoParticipante::montar(2026, 1, '11144477735', CodigoParticipante::PAPEL_ALUNO, 7);
        $coorientador = CodigoParticipante::montar(2026, 1, '11144477735', CodigoParticipante::PAPEL_COORIENTADOR, 7);

        $this->assertNotSame($aluno, $coorientador);
    }

    public function test_codigo_malformado_nao_e_lido(): void
    {
        $this->assertNull(CodigoParticipante::ler('nada disso'));
        $this->assertNull(CodigoParticipante::ler('2026-31-12-A45'));   // CPF curto
        $this->assertNull(CodigoParticipante::ler('2026-31-123-X45'));  // papel inexistente
    }

    // --- A lista ----------------------------------------------------------

    public function test_lista_traz_todos_os_participantes_com_codigo(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        $lista = $this->listaCom($projeto);

        $dados = $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao")
            ->assertOk()
            ->json('data');

        // 2 alunos + orientador + coorientador.
        $this->assertSame(4, $dados['total']);
        $this->assertSame(2, $dados['por_papel']['A']);
        $this->assertSame(1, $dados['por_papel']['O']);
        $this->assertSame(1, $dados['por_papel']['C']);

        $codigos = array_column($dados['participantes'], 'codigo');
        $this->assertCount(4, array_unique($codigos), 'Nenhum código se repete.');

        foreach ($codigos as $codigo) {
            $lido = CodigoParticipante::ler($codigo);
            $this->assertNotNull($lido);
            $this->assertSame(2026, $lido['ano']);
            $this->assertSame($projeto->id, $lido['projeto_id']);
        }
    }

    /**
     * A promessa do formato: o crachá impresso hoje continua valendo depois de
     * a composição da lista mudar de versão.
     */
    public function test_o_codigo_nao_muda_quando_a_lista_sobe_de_versao(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        $lista = $this->listaCom($projeto);

        $antes = array_column(
            $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao")->json('data.participantes'),
            'codigo',
        );

        // A lista muda: outro projeto entra e a numeração é refeita.
        $lista->projetos()->attach($this->finalista()->id);
        $lista->update(['versao' => 2]);

        $depois = array_column(
            $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao")->json('data.participantes'),
            'codigo',
        );

        foreach ($antes as $codigo) {
            $this->assertContains($codigo, $depois);
        }
    }

    /**
     * Sprint 189 — a lista sai em ordem alfabética de nome, misturando papéis e
     * projetos: a folha de etiquetas é procurada pela pessoa.
     */
    public function test_participantes_saem_em_ordem_alfabetica_de_nome(): void
    {
        $this->admin();
        $lista = $this->listaCom($this->finalista());

        $nomes = array_column(
            $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao")->json('data.participantes'),
            'nome',
        );

        $this->assertSame(['Ana Paula', 'Caio Silva', 'Marta Orientadora', 'Zuleica Nunes'], $nomes);
    }

    public function test_acento_e_caixa_nao_mudam_a_ordem_alfabetica(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ângela Souza', 'cpf' => '44455566677']);
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'beatriz Lima', 'cpf' => '55566677788']);
        $lista = $this->listaCom($projeto);

        $nomes = array_column(
            $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao")->json('data.participantes'),
            'nome',
        );

        $this->assertSame(['Ana Paula', 'Ângela Souza', 'beatriz Lima', 'Caio Silva'], array_slice($nomes, 0, 4));
    }

    // --- Os arquivos ------------------------------------------------------

    public function test_serve_o_qr_e_o_codigo_de_barras_em_svg(): void
    {
        $this->admin();
        $lista = $this->listaCom($this->finalista());
        $codigo = $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao")
            ->json('data.participantes.0.codigo');

        $qr = $this->get("/api/v1/admin/avaliacao/identificacao/qr/{$codigo}.svg")->assertOk();
        $this->assertSame('image/svg+xml', $qr->headers->get('content-type'));
        $this->assertStringContainsString('<svg', $qr->getContent());

        $barras = $this->get("/api/v1/admin/avaliacao/identificacao/barras/{$codigo}.svg")->assertOk();
        $this->assertStringContainsString('<svg', $barras->getContent());
        // Dois desenhos diferentes para o mesmo código.
        $this->assertNotSame($qr->getContent(), $barras->getContent());
    }

    public function test_codigo_invalido_nao_vira_imagem(): void
    {
        $this->admin();

        $this->getJson('/api/v1/admin/avaliacao/identificacao/qr/2026-1-12-Z9.svg')
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    public function test_pdf_de_etiquetas_sai_com_todo_mundo(): void
    {
        $this->admin();
        $lista = $this->listaCom($this->finalista());

        $pdf = $this->get("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao/pdf")->assertOk();

        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        // O texto de cada etiqueta está na página (as fontes padrão do PDF
        // gravam em Windows-1252, por isso a conversão para procurar).
        $texto = $this->textoDoPdf($pdf->getContent());
        foreach (['Zuleica Nunes', 'Ana Paula', 'Marta Orientadora', 'Caio Silva'] as $nome) {
            $this->assertStringContainsString(mb_convert_encoding($nome, 'Windows-1252', 'UTF-8'), $texto);
        }
    }

    /**
     * Sprint 188 — a etiqueta é da pessoa: nome com o papel ao lado, turno e
     * estande; o título do projeto e a escola saíram.
     */
    public function test_etiqueta_traz_papel_ao_lado_do_nome_turno_e_estande_sem_projeto_nem_escola(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => 'B', 'numero' => 42]);
        $lista = $this->listaCom($projeto);

        $texto = $this->textoDoPdf(
            $this->get("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao/pdf")->assertOk()->getContent()
        );
        $win = fn (string $s) => mb_convert_encoding($s, 'Windows-1252', 'UTF-8');

        $this->assertStringContainsString('(Zuleica Nunes)', $texto);
        // Os parênteses do papel chegam escapados na string do PDF.
        $this->assertStringContainsString('( - aluno\\(a\\))', $texto);
        $this->assertStringContainsString('( - orientador\\(a\\))', $texto);
        $this->assertStringContainsString('( - coorientador\\(a\\))', $texto);
        $this->assertStringContainsString($win('Turno B \\(vespertino\\) · Estande 42'), $texto);
        $this->assertStringNotContainsString($win('Bioplástico'), $texto);
        $this->assertStringNotContainsString($win('Maria Constança'), $texto);
    }

    public function test_sem_estande_vale_o_turno_e_sem_nenhum_a_linha_some(): void
    {
        $this->admin();
        $comTurno = $this->finalista();
        TurnoApresentacao::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $comTurno->id, 'turno' => 'A']);
        $lista = $this->listaCom($comTurno);

        $dados = $this->getJson("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao")->json('data.participantes');
        $this->assertSame('Turno A (matutino)', $dados[0]['turno']);
        $this->assertNull($dados[0]['estande']);

        $texto = $this->textoDoPdf($this->get("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao/pdf")->getContent());
        $this->assertStringContainsString('(Turno A \\(matutino\\))', $texto);
        $this->assertStringNotContainsString('Estande', $texto);
    }

    /**
     * Relato de produção: o PDF de etiquetas dava erro. O HTML do Dompdf não
     * pintava o SVG embutido e estourava a memória a partir de ~200 pessoas; a
     * lista real tem perto de 1.800. Desenhada direto na página, a lista inteira
     * cabe com folga: 16 etiquetas por folha e memória quase constante.
     */
    public function test_pdf_de_etiquetas_aguenta_a_lista_inteira(): void
    {
        $etiquetas = [];
        for ($i = 1; $i <= 1200; $i++) {
            $etiquetas[] = [
                'papel_label' => 'Aluno(a)',
                'nome' => "Participante número {$i} com nome comprido de verdade",
                'turno' => 'Turno B (vespertino)',
                'estande' => $i % 230,
                'codigo' => "2026-{$i}-123-A{$i}",
            ];
        }

        $antes = memory_get_usage();
        $pdf = EtiquetasPdf::gerar('Identificação', 'teste', $etiquetas);

        $this->assertStringStartsWith('%PDF', $pdf);
        // 16 por folha desde a Sprint 189.
        $this->assertSame(75, preg_match_all('#/Type /Page\b#', $pdf));
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $antes);
    }

    /** Junta o conteúdo descomprimido de todas as streams do PDF. */
    private function textoDoPdf(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);

        return implode("\n", array_map(fn ($s) => @gzuncompress($s) ?: $s, $m[1]));
    }

    public function test_zip_traz_dois_svgs_por_participante(): void
    {
        $this->admin();
        $lista = $this->listaCom($this->finalista());

        $resposta = $this->get("/api/v1/admin/avaliacao/listas-finais/{$lista->id}/identificacao/zip")->assertOk();

        $caminho = tempnam(sys_get_temp_dir(), 'teste-zip-');
        file_put_contents($caminho, $resposta->streamedContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($caminho) === true);
        // 4 participantes × (qr + barras).
        $this->assertSame(8, $zip->numFiles);

        $nomes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nomes[] = $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($caminho);

        // Uma pasta por projeto, para o balcão separar por equipe.
        $this->assertStringContainsString('Bioplastico', implode(' ', $nomes));
        $this->assertStringContainsString('-qr.svg', implode(' ', $nomes));
        $this->assertStringContainsString('-barras.svg', implode(' ', $nomes));
    }
}
