<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\Coorientador;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\ListaFinal;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\User;
use App\Support\CodigoParticipante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 124 — a **leitura do crachá** no balcão do credenciamento.
 *
 * O código lido (QR pela câmera ou barras pelo leitor USB) precisa levar à ficha
 * certa — e recusar, com o motivo, tudo que não for dali: etiqueta de outro
 * evento, projeto que não é finalista, crachá trocado entre colegas e ensaio
 * tentando alcançar um finalista de verdade.
 */
class LeituraCodigoCredenciamentoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            // O balcão só opera dentro da janela do evento.
            'evento_de' => now()->subDay(), 'evento_ate' => now()->addDay(),
        ]);
    }

    private function admin(bool $demo = false): User
    {
        $admin = User::factory()->admin()->create(['is_demo' => $demo]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function finalista(string $titulo = 'Bioplástico de mandioca'): Projeto
    {
        $orientador = User::factory()->create(['name' => 'Marta Orientadora']);
        OrientadorProfile::factory()->create([
            'user_id' => $orientador->id,
            'cpf' => str_pad((string) (User::count() + 1), 11, '5', STR_PAD_LEFT),
        ]);

        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id,
            'titulo' => $titulo,
            'edicao_id' => $this->edicao->id,
        ]);

        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Zuleica Nunes', 'cpf' => '11144477735']);
        Coorientador::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Caio Silva', 'cpf' => '33344455566']);

        return $projeto->fresh(['alunos', 'coorientador', 'user.orientadorProfile']);
    }

    private function lista(Projeto $projeto, bool $demo = false): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id,
            'nome' => $demo ? 'Lista de demonstração' : 'Lista oficial',
            'vigente' => true,
            'demo' => $demo,
            'versao' => 1,
        ]);

        $lista->projetos()->attach($projeto->id);

        return $lista;
    }

    private function ler(string $codigo, bool $teste = false)
    {
        return $this->postJson('/api/v1/admin/credenciamento/codigo', [
            'codigo' => $codigo,
            ...($teste ? ['teste' => 1] : []),
        ]);
    }

    private function codigoDoAluno(Projeto $projeto): string
    {
        $aluno = $projeto->alunos->first();

        return CodigoParticipante::montar(2026, $projeto->id, $aluno->cpf, CodigoParticipante::PAPEL_ALUNO, $aluno->id);
    }

    // --- O caminho feliz --------------------------------------------------

    public function test_codigo_do_aluno_leva_a_ficha_do_projeto(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        $this->lista($projeto);

        $dados = $this->ler($this->codigoDoAluno($projeto))->assertOk()->json('data');

        $this->assertSame($projeto->id, $dados['projeto']['id']);
        $this->assertSame('Bioplástico de mandioca', $dados['projeto']['titulo']);
        $this->assertSame('Zuleica Nunes', $dados['participante']['nome']);
        $this->assertSame('Aluno(a)', $dados['participante']['papel_label']);
        $this->assertFalse($dados['credenciado']);
    }

    public function test_codigo_do_orientador_e_do_coorientador_tambem_abrem(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        $this->lista($projeto);

        $orientador = CodigoParticipante::montar(
            2026, $projeto->id, $projeto->user->orientadorProfile->cpf,
            CodigoParticipante::PAPEL_ORIENTADOR, $projeto->user->id,
        );
        $coorientador = CodigoParticipante::montar(
            2026, $projeto->id, $projeto->coorientador->cpf,
            CodigoParticipante::PAPEL_COORIENTADOR, $projeto->coorientador->id,
        );

        $this->assertSame('Orientador(a)', $this->ler($orientador)->assertOk()->json('data.participante.papel_label'));
        $this->assertSame('Caio Silva', $this->ler($coorientador)->assertOk()->json('data.participante.nome'));
    }

    // --- O que é recusado, e por quê --------------------------------------

    public function test_codigo_fora_do_formato_e_recusado(): void
    {
        $this->admin();

        $this->ler('etiqueta rasgada')
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    public function test_projeto_fora_da_lista_final_e_recusado(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        // Existe uma lista vigente, mas este projeto não está nela.
        $this->lista($this->finalista('Outro projeto'));

        $resposta = $this->ler($this->codigoDoAluno($projeto))->assertStatus(422);

        $this->assertStringContainsString('lista final vigente', $resposta->json('errors.codigo.0'));
    }

    /** O crachá de um colega no projeto de outro: os três dígitos do CPF pegam. */
    public function test_cpf_que_nao_confere_e_recusado(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        $this->lista($projeto);
        $aluno = $projeto->alunos->first();

        $trocado = CodigoParticipante::montar(
            2026, $projeto->id, '99999999999', CodigoParticipante::PAPEL_ALUNO, $aluno->id,
        );

        $resposta = $this->ler($trocado)->assertStatus(422);

        $this->assertStringContainsString('não confere com o cadastro', $resposta->json('errors.codigo.0'));
    }

    public function test_pessoa_que_saiu_da_equipe_e_recusada(): void
    {
        $this->admin();
        $projeto = $this->finalista();
        $this->lista($projeto);
        $codigo = $this->codigoDoAluno($projeto);

        $projeto->alunos()->delete();

        $resposta = $this->ler($codigo)->assertStatus(422);

        $this->assertStringContainsString('não está mais na equipe', $resposta->json('errors.codigo.0'));
    }

    /** O ensaio não alcança finalista de verdade — nem forçando `teste=1`. */
    public function test_modo_de_teste_so_le_cracha_da_lista_de_demonstracao(): void
    {
        $this->admin(demo: true);
        $oficial = $this->finalista('Projeto oficial');
        $this->lista($oficial);

        $demo = $this->finalista('Projeto de ensaio');
        $this->lista($demo, demo: true);

        // Em modo de teste, o crachá do finalista de verdade é recusado…
        $resposta = $this->ler($this->codigoDoAluno($oficial), teste: true)->assertStatus(422);
        $this->assertStringContainsString('lista de demonstração', $resposta->json('errors.codigo.0'));

        // …e o do projeto de ensaio abre.
        $this->assertSame(
            $demo->id,
            $this->ler($this->codigoDoAluno($demo), teste: true)->assertOk()->json('data.projeto.id'),
        );

        // Fora do modo de teste, o inverso.
        $this->ler($this->codigoDoAluno($demo))->assertStatus(422);
        $this->ler($this->codigoDoAluno($oficial))->assertOk();
    }

    public function test_admin_sem_a_aba_credenciamento_recebe_403(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/admin/credenciamento/codigo', ['codigo' => '2026-1-111-A1'])
            ->assertForbidden();
    }
}
