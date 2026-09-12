<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\Edicao;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\User;
use App\Support\CodigoParticipante;
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
