<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusPresenca;
use App\Enums\TipoCredencial;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\CerimonialCheckin;
use App\Models\Cidade;
use App\Models\ContaTemporaria;
use App\Models\Coorientador;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\ContaTemporariaService;
use App\Support\CodigoParticipante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Aba **Cerimonial**: o check-in da cerimônia de premiação.
 *
 * O que estes testes seguram:
 * - quem pode entrar (finalista da lista vigente) e **sem depender** do
 *   credenciamento — são dois momentos diferentes do evento;
 * - o check-in é **por pessoa**, e a contagem de projetos distingue a equipe
 *   parcialmente presente da completa;
 * - **medalha segue a pessoa** e **credencial segue o projeto**, contando só o
 *   que tem objeto na mesa (prêmio não entra);
 * - desfazer pede justificativa e deixa rastro — é o número que decide o que
 *   vai ser entregue no palco;
 * - a **conta temporária** do setor atende a porta e nada mais: a Visão Geral
 *   responde 403 mesmo com a URL na mão.
 */
class CerimonialTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    /**
     * Distingue os CPFs de um teste para o outro: `orientador_profiles.cpf` é
     * único, e vários testes montam dois projetos.
     */
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'evento_de' => now()->subHour(), 'evento_ate' => now()->addDays(2),
        ]);
    }

    /** Um projeto finalista com equipe: dois alunos, orientador e coorientador. */
    private function projeto(string $titulo = 'Bioplástico de mandioca'): Projeto
    {
        $this->seq++;
        $cpf = fn (int $pessoa) => str_pad((string) ($this->seq * 10 + $pessoa), 11, '0', STR_PAD_LEFT);

        $estado = Estado::firstOrCreate(['uf' => 'MS'], ['nome' => 'Mato Grosso do Sul']);
        $cidade = Cidade::firstOrCreate(
            ['nome' => 'Campo Grande', 'estado_id' => $estado->id],
            ['capital' => true],
        );
        $escola = Instituicao::firstOrCreate(['nome' => 'EE Maria Constança', 'cidade_id' => $cidade->id]);

        $orientador = User::factory()->create([
            'role' => Role::Orientador->value,
            'name' => 'Marta Orientadora',
        ]);
        OrientadorProfile::factory()->create(['user_id' => $orientador->id, 'cpf' => $cpf(1)]);

        $projeto = Projeto::factory()->submetido()->create([
            'user_id' => $orientador->id,
            'titulo' => $titulo,
            'area_id' => Area::firstOrCreate(['nome' => 'Ciências Agrárias'])->id,
            'instituicao_id' => $escola->id,
        ]);

        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Ana Paula', 'cpf' => $cpf(2)]);
        Aluno::factory()->create(['projeto_id' => $projeto->id, 'nome' => 'Bruno Lima', 'cpf' => $cpf(3)]);
        Coorientador::factory()->create([
            'projeto_id' => $projeto->id, 'nome' => 'Carlos Coorientador', 'cpf' => $cpf(4),
        ]);

        return $projeto->fresh();
    }

    /** @param  list<Projeto>  $projetos */
    private function publicar(array $projetos, bool $demo = false): ListaFinal
    {
        $lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id,
            'nome' => $demo ? 'Lista demo' : 'Lista final',
            'vigente' => true,
            'versao' => 1,
            'demo' => $demo,
        ]);

        $lista->projetos()->attach(
            collect($projetos)->mapWithKeys(fn (Projeto $p) => [$p->id => ['manual' => false]])->all(),
        );

        return $lista;
    }

    private function comoAdmin(array $over = []): User
    {
        $admin = User::factory()->admin()->create($over + ['name' => 'Ana Admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Premia o projeto — credencial (com objeto a separar) ou prêmio. */
    private function premiar(Projeto $projeto, TipoCredencial $tipo = TipoCredencial::Credencial, string $nome = 'MOSTRATEC 2027'): Credencial
    {
        $credencial = Credencial::create([
            'edicao_id' => $this->edicao->id,
            'tipo' => $tipo,
            'nome' => $nome,
            'ativa' => true,
        ]);

        $credencial->projetos()->attach($projeto->id, ['atribuida_em' => now()]);

        return $credencial;
    }

    /** A conta da porta: admin restrito à aba Cerimonial, com presença aprovada. */
    private function contaTemporaria(): ContaTemporaria
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Bruna Voluntária',
            'email' => 'porta@fetec.test',
            'password' => 'senha-da-porta',
            'cpf' => '52998224725',
            'curso' => 'Ciência da Computação',
            'horas' => 5,
        ], null, ContaTemporaria::SETOR_CERIMONIAL);

        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();

        return $conta->refresh();
    }

    private function chaveAluno(Projeto $projeto, string $nome): string
    {
        return CodigoParticipante::PAPEL_ALUNO.$projeto->alunos->firstWhere('nome', $nome)->id;
    }

    // ------------------------------------------------------------------ //
    // Check-in                                                            //
    // ------------------------------------------------------------------ //

    public function test_check_in_de_varias_pessoas_de_uma_vez(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $resposta = $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", [
            'participantes' => [
                $this->chaveAluno($projeto, 'Ana Paula'),
                CodigoParticipante::PAPEL_ORIENTADOR.$projeto->user_id,
            ],
        ])->assertOk();

        $this->assertSame(2, $resposta->json('data.presentes'));
        $this->assertSame(4, $resposta->json('data.total'));
        $this->assertDatabaseCount('cerimonial_checkins', 2);

        // Uma linha de auditoria por pessoa — é nominalmente que a trilha
        // precisa responder quem estava na sala.
        $this->assertSame(2, RegistroAtividade::where('tipo', TipoRegistro::CerimonialCheckin)->count());
    }

    public function test_check_in_repetido_nao_duplica_a_pessoa(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();
        $chave = $this->chaveAluno($projeto, 'Ana Paula');

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", ['participantes' => [$chave]])->assertOk();
        // A equipe chega em levas: remarcar a mesma caixa não pode dar erro.
        $resposta = $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", ['participantes' => [$chave]])->assertOk();

        $this->assertSame(1, $resposta->json('data.presentes'));
        $this->assertDatabaseCount('cerimonial_checkins', 1);
        $this->assertSame(1, RegistroAtividade::where('tipo', TipoRegistro::CerimonialCheckin)->count());
    }

    public function test_projeto_fora_da_lista_final_nao_faz_check_in(): void
    {
        $finalista = $this->projeto();
        $deFora = $this->projeto('Projeto que não passou');
        $this->publicar([$finalista]);
        $this->comoAdmin();

        $this->getJson("/api/v1/admin/cerimonial/projetos/{$deFora->id}")->assertNotFound();
        $this->postJson("/api/v1/admin/cerimonial/projetos/{$deFora->id}/checkin", [
            'participantes' => [$this->chaveAluno($deFora, 'Ana Paula')],
        ])->assertNotFound();
    }

    public function test_check_in_independe_do_credenciamento(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        // Nenhum credenciamento existe, e mesmo assim a ficha abre e registra:
        // a cerimônia é outro momento do evento.
        $ficha = $this->getJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}")->assertOk();
        $this->assertFalse($ficha->json('data.credenciado'));

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertOk();
    }

    public function test_fora_da_janela_do_evento_nao_registra(): void
    {
        $this->edicao->update(['evento_de' => now()->addDays(3), 'evento_ate' => now()->addDays(4)]);
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        // A consulta continua aberta; o registro, não.
        $this->getJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}")->assertOk();
        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------ //
    // Leitura do crachá e busca                                           //
    // ------------------------------------------------------------------ //

    public function test_cracha_lido_devolve_o_projeto_a_abrir(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $aluno = $projeto->alunos->firstWhere('nome', 'Ana Paula');
        $codigo = CodigoParticipante::montar(
            2026, $projeto->id, $aluno->cpf, CodigoParticipante::PAPEL_ALUNO, $aluno->id,
        );

        $resposta = $this->postJson('/api/v1/admin/cerimonial/codigo', ['codigo' => $codigo])->assertOk();

        $this->assertSame($projeto->id, $resposta->json('data.projeto_id'));
        $this->assertSame('Ana Paula', $resposta->json('data.participante.nome'));
    }

    public function test_cracha_de_cpf_trocado_e_recusado(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $aluno = $projeto->alunos->firstWhere('nome', 'Ana Paula');
        // Mesmo id, CPF de outra pessoa: é o crachá trocado entre colegas.
        $codigo = CodigoParticipante::montar(
            2026, $projeto->id, '99988877766', CodigoParticipante::PAPEL_ALUNO, $aluno->id,
        );

        $this->postJson('/api/v1/admin/cerimonial/codigo', ['codigo' => $codigo])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    public function test_busca_por_nome_e_por_cpf(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $porNome = $this->getJson('/api/v1/admin/cerimonial/busca?busca=ana')->assertOk();
        $this->assertSame('Ana Paula', $porNome->json('data.0.nome'));

        // Sem acento e com o CPF: os dois caminhos da fila.
        $this->assertNotEmpty($this->getJson('/api/v1/admin/cerimonial/busca?busca=marta')->json('data'));
        $cpfDaAna = $projeto->alunos->firstWhere('nome', 'Ana Paula')->cpf;
        $porCpf = $this->getJson('/api/v1/admin/cerimonial/busca?busca='.$cpfDaAna)->assertOk();
        $this->assertSame('Ana Paula', $porCpf->json('data.0.nome'));
    }

    public function test_busca_nao_alcanca_quem_nao_e_finalista(): void
    {
        $finalista = $this->projeto();
        $this->projeto('Projeto de fora');
        $this->publicar([$finalista]);
        $this->comoAdmin();

        $resultados = $this->getJson('/api/v1/admin/cerimonial/busca?busca=ana')->json('data');

        $this->assertNotEmpty($resultados);
        foreach ($resultados as $r) {
            $this->assertSame($finalista->id, $r['projeto_id']);
        }
    }

    // ------------------------------------------------------------------ //
    // Desfazer                                                            //
    // ------------------------------------------------------------------ //

    public function test_desfazer_exige_justificativa_e_fica_registrado(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();
        $chave = $this->chaveAluno($projeto, 'Ana Paula');

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", ['participantes' => [$chave]])->assertOk();

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/desfazer", [
            'participante' => $chave,
        ])->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $resposta = $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/desfazer", [
            'participante' => $chave,
            'justificativa' => 'Crachá lido por engano: era o colega de equipe.',
        ])->assertOk();

        $this->assertSame(0, $resposta->json('data.presentes'));
        $this->assertDatabaseCount('cerimonial_checkins', 0);

        // A linha some, mas o registro é a memória de que ela existiu.
        $registro = RegistroAtividade::where('tipo', TipoRegistro::CerimonialCheckinDesfeito)->firstOrFail();
        $this->assertSame('Ana Paula', $registro->detalhes['pessoa']);
        $this->assertStringContainsString('engano', $registro->detalhes['justificativa']);
    }

    // ------------------------------------------------------------------ //
    // Visão geral                                                         //
    // ------------------------------------------------------------------ //

    public function test_visao_geral_conta_pessoas_projetos_medalhas_e_credenciais(): void
    {
        $premiado = $this->projeto('Projeto premiado');
        $comum = $this->projeto('Projeto sem prêmio');
        $this->publicar([$premiado, $comum]);
        $this->premiar($premiado);
        $this->comoAdmin();

        // Dois dos quatro integrantes do premiado chegaram: equipe parcial.
        $this->postJson("/api/v1/admin/cerimonial/projetos/{$premiado->id}/checkin", [
            'participantes' => [
                $this->chaveAluno($premiado, 'Ana Paula'),
                CodigoParticipante::PAPEL_ORIENTADOR.$premiado->user_id,
            ],
        ])->assertOk();

        $dados = $this->getJson('/api/v1/admin/cerimonial/visao-geral')->assertOk()->json('data');

        $this->assertSame(2, $dados['pessoas']['presentes']);
        $this->assertSame(8, $dados['pessoas']['total']);
        $this->assertSame(6, $dados['pessoas']['faltam']);
        $this->assertSame(1, $dados['pessoas']['por_papel']['alunos']);
        $this->assertSame(1, $dados['pessoas']['por_papel']['orientadores']);

        // Um projeto com gente dentro, nenhum completo.
        $this->assertSame(1, $dados['projetos']['presentes']);
        $this->assertSame(1, $dados['projetos']['parciais']);
        $this->assertSame(0, $dados['projetos']['completos']);
        $this->assertSame(2, $dados['projetos']['total']);

        $this->assertSame(1, $dados['premiados']['presentes']);
        $this->assertSame(1, $dados['premiados']['total']);

        // Medalha segue a pessoa: duas chegaram, quatro no total do premiado.
        $this->assertSame(2, $dados['medalhas']['separar']);
        $this->assertSame(4, $dados['medalhas']['total']);

        // Credencial segue o projeto: uma, porque o projeto tem alguém na sala.
        $this->assertSame(1, $dados['credenciais']['separar']);
        $this->assertSame(1, $dados['credenciais']['total']);
    }

    public function test_premio_faz_premiado_mas_nao_entra_nas_credenciais_a_separar(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        // Só prêmio: anuncia-se no palco, não se entrega em mãos.
        $this->premiar($projeto, TipoCredencial::Premio, 'Destaque da feira');
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertOk();

        $dados = $this->getJson('/api/v1/admin/cerimonial/visao-geral')->assertOk()->json('data');

        $this->assertSame(1, $dados['premiados']['presentes']);
        $this->assertSame(1, $dados['medalhas']['separar']);
        $this->assertSame(0, $dados['credenciais']['separar']);
        $this->assertSame(0, $dados['credenciais']['total']);
    }

    public function test_detalhe_do_card_lista_quem_chegou_e_quem_falta(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertOk();

        $dados = $this->getJson('/api/v1/admin/cerimonial/visao-geral/detalhe?card=pessoas')
            ->assertOk()->json('data');

        $this->assertCount(1, $dados['presentes']);
        $this->assertSame('Ana Paula', $dados['presentes'][0]['nome']);
        $this->assertCount(3, $dados['faltantes']);
    }

    public function test_premiados_trazem_cada_participante_com_a_situacao(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->premiar($projeto);
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertOk();

        $premiados = $this->getJson('/api/v1/admin/cerimonial/premiados')->assertOk()->json('data');

        $this->assertCount(1, $premiados);
        $this->assertSame(1, $premiados[0]['presentes']);
        $this->assertSame(4, $premiados[0]['total']);
        $this->assertFalse($premiados[0]['completo']);

        $ana = collect($premiados[0]['pessoas'])->firstWhere('nome', 'Ana Paula');
        $this->assertTrue($ana['presente']);
        $this->assertFalse(collect($premiados[0]['pessoas'])->firstWhere('nome', 'Bruno Lima')['presente']);
    }

    // ------------------------------------------------------------------ //
    // Modo de teste                                                       //
    // ------------------------------------------------------------------ //

    public function test_modo_de_teste_usa_a_lista_demo_e_nao_suja_a_contagem_real(): void
    {
        $oficial = $this->projeto('Finalista de verdade');
        $ensaio = $this->projeto('Projeto de ensaio');
        $this->publicar([$oficial]);
        $this->publicar([$ensaio], demo: true);
        $this->comoAdmin(['is_demo' => true]);

        // Em modo de teste, o finalista de verdade não é alcançável.
        $this->getJson("/api/v1/admin/cerimonial/projetos/{$oficial->id}?teste=1")->assertNotFound();

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$ensaio->id}/checkin?teste=1", [
            'participantes' => [$this->chaveAluno($ensaio, 'Ana Paula')],
        ])->assertOk();

        $this->assertTrue(CerimonialCheckin::firstOrFail()->demo);

        // O painel de verdade continua zerado.
        $this->assertSame(0, $this->getJson('/api/v1/admin/cerimonial/visao-geral')
            ->json('data.pessoas.presentes'));
    }

    public function test_conta_nao_demo_nao_entra_em_modo_de_teste(): void
    {
        $oficial = $this->projeto();
        $ensaio = $this->projeto('Projeto de ensaio');
        $this->publicar([$oficial]);
        $this->publicar([$ensaio], demo: true);
        $this->comoAdmin();

        // O `teste=1` na URL não vale nada para quem não é demo: quem responde
        // é a lista oficial.
        $this->getJson("/api/v1/admin/cerimonial/projetos/{$ensaio->id}?teste=1")->assertNotFound();
        $this->getJson("/api/v1/admin/cerimonial/projetos/{$oficial->id}?teste=1")->assertOk();
    }

    // ------------------------------------------------------------------ //
    // Conta temporária: só o check-in                                     //
    // ------------------------------------------------------------------ //

    public function test_conta_temporaria_do_cerimonial_atende_o_check_in(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $conta = $this->contaTemporaria();
        Sanctum::actingAs($conta->user);

        $this->getJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}")->assertOk();
        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertOk();
    }

    public function test_conta_temporaria_nao_abre_a_visao_geral_nem_as_contas(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $conta = $this->contaTemporaria();
        Sanctum::actingAs($conta->user);

        // A porta não precisa saber quantas medalhas há na mesa — e a trava é
        // do servidor, não do menu escondido.
        $this->getJson('/api/v1/admin/cerimonial/visao-geral')->assertForbidden();
        $this->getJson('/api/v1/admin/cerimonial/visao-geral/detalhe?card=pessoas')->assertForbidden();
        $this->getJson('/api/v1/admin/cerimonial/premiados')->assertForbidden();
        $this->getJson('/api/v1/admin/cerimonial/contas')->assertForbidden();
        $this->patchJson('/api/v1/admin/cerimonial/atualizacao', ['segundos' => 10])->assertForbidden();
    }

    public function test_conta_temporaria_de_outro_setor_nao_abre_o_cerimonial(): void
    {
        $conta = app(ContaTemporariaService::class)->criar([
            'name' => 'Balcão do credenciamento',
            'email' => 'balcao@fetec.test',
            'password' => 'senha-do-balcao',
            'cpf' => '52998224725',
            'curso' => 'Pedagogia',
            'horas' => 5,
        ], null, ContaTemporaria::SETOR_CREDENCIAMENTO);
        $conta->forceFill(['presenca_status' => StatusPresenca::Aprovada])->save();

        Sanctum::actingAs($conta->user);

        $this->getJson('/api/v1/admin/cerimonial/config')->assertForbidden();
    }

    // ------------------------------------------------------------------ //
    // Intervalo de atualização do painel                                  //
    // ------------------------------------------------------------------ //

    public function test_admin_define_o_intervalo_de_atualizacao_do_painel(): void
    {
        $this->comoAdmin();

        $this->patchJson('/api/v1/admin/cerimonial/atualizacao', ['segundos' => 10])->assertOk();
        $this->assertSame(10, $this->edicao->fresh()->cerimonial_atualizacao_segundos);

        // Em branco desliga o polling e deixa só o botão de atualizar.
        $this->patchJson('/api/v1/admin/cerimonial/atualizacao', ['segundos' => null])->assertOk();
        $this->assertNull($this->edicao->fresh()->cerimonial_atualizacao_segundos);
    }
}
