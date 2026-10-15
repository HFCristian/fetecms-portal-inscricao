<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\Role;
use App\Enums\StatusPresenca;
use App\Enums\TipoCredencial;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\CerimonialCheckin;
use App\Models\CerimonialEvento;
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

    /** O evento de cerimônia que recebe todas as categorias — a premiação única de antes. */
    private CerimonialEvento $evento;

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

        $this->evento = CerimonialEvento::create([
            'edicao_id' => $this->edicao->id,
            'nome' => 'Cerimônia de premiação',
            'categorias' => ['fetecms', 'fetec_jr', 'fetecms_fundect'],
        ]);
    }

    /** A URL com o evento da porta: logo depois do "?", para aceitar concatenação. */
    private function comEvento(string $url, ?CerimonialEvento $evento = null): string
    {
        $parametro = 'evento_id='.($evento ?? $this->evento)->id;

        return str_contains($url, '?')
            ? preg_replace('/\?/', '?'.$parametro.'&', $url, 1)
            : $url.'?'.$parametro;
    }

    /** Um projeto finalista com equipe: dois alunos, orientador e coorientador. */
    private function projeto(string $titulo = 'Bioplástico de mandioca', Categoria $categoria = Categoria::Fetecms): Projeto
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
            'categoria' => $categoria,
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

        $resposta = $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), [
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

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), ['participantes' => [$chave]])->assertOk();
        // A equipe chega em levas: remarcar a mesma caixa não pode dar erro.
        $resposta = $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), ['participantes' => [$chave]])->assertOk();

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

        $this->getJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$deFora->id}"))->assertNotFound();
        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$deFora->id}/checkin"), [
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
        $ficha = $this->getJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}"))->assertOk();
        $this->assertFalse($ficha->json('data.credenciado'));

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), [
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
        $this->getJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}"))->assertOk();
        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------ //
    // Leitura do crachá e busca                                           //
    // ------------------------------------------------------------------ //

    /** O código impresso no crachá de um aluno do projeto. */
    private function codigoDoAluno(Projeto $projeto, string $nome, ?string $cpf = null): string
    {
        $aluno = $projeto->alunos->firstWhere('nome', $nome);

        return CodigoParticipante::montar(
            2026, $projeto->id, $cpf ?? $aluno->cpf, CodigoParticipante::PAPEL_ALUNO, $aluno->id,
        );
    }

    public function test_cracha_lido_ja_registra_a_entrada_da_pessoa(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $resposta = $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoAluno($projeto, 'Ana Paula'),
        ])->assertOk();

        $this->assertSame($projeto->id, $resposta->json('data.projeto_id'));
        $this->assertSame('Ana Paula', $resposta->json('data.participante.nome'));
        $this->assertFalse($resposta->json('data.ja_presente'));
        $this->assertSame(now()->format('H:i'), $resposta->json('data.checkin_em'));

        // Só ela: a leitura do crachá não marca a equipe.
        $this->assertSame(1, CerimonialCheckin::count());
        $this->assertSame('Ana Paula', CerimonialCheckin::first()->nome);
        $this->assertSame(1, RegistroAtividade::where('tipo', TipoRegistro::CerimonialCheckin)->count());
    }

    public function test_cracha_de_quem_ja_entrou_avisa_sem_gravar_de_novo(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();
        $codigo = $this->codigoDoAluno($projeto, 'Ana Paula');

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), ['codigo' => $codigo])->assertOk();
        $this->travel(20)->minutes();

        $resposta = $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), ['codigo' => $codigo])->assertOk();

        $this->assertTrue($resposta->json('data.ja_presente'));
        // A hora é a da primeira entrada, não a desta leitura.
        $this->assertSame(now()->subMinutes(20)->format('H:i'), $resposta->json('data.checkin_em'));
        $this->assertSame(1, CerimonialCheckin::count());
        $this->assertSame(1, RegistroAtividade::where('tipo', TipoRegistro::CerimonialCheckin)->count());
    }

    public function test_cracha_de_cpf_trocado_e_recusado(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        // Mesmo id, CPF de outra pessoa: é o crachá trocado entre colegas.
        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoAluno($projeto, 'Ana Paula', '99988877766'),
        ])->assertStatus(422)->assertJsonValidationErrors('codigo');

        $this->assertSame(0, CerimonialCheckin::count());
    }

    public function test_cracha_de_projeto_fora_da_lista_e_recusado(): void
    {
        $finalista = $this->projeto();
        $fora = $this->projeto('Fora da lista');
        $this->publicar([$finalista]);
        $this->comoAdmin();

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoAluno($fora, 'Ana Paula'),
        ])->assertStatus(422)->assertJsonValidationErrors('codigo');

        $this->assertSame(0, CerimonialCheckin::count());
    }

    public function test_cracha_fora_do_periodo_do_evento_diz_o_motivo(): void
    {
        $this->edicao->update(['evento_de' => now()->subDays(3), 'evento_ate' => now()->subDay()]);
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoAluno($projeto, 'Ana Paula'),
        ])->assertStatus(422)->assertJsonPath('errors.codigo.0', 'O check-in do cerimonial só acontece durante o período do evento.');

        $this->assertSame(0, CerimonialCheckin::count());
    }

    public function test_conta_temporaria_da_porta_registra_pelo_cracha(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $conta = $this->contaTemporaria();
        Sanctum::actingAs($conta->user);

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoAluno($projeto, 'Bruno Lima'),
        ])->assertOk();

        $this->assertSame($conta->user_id, CerimonialCheckin::first()->registrado_por);
    }

    public function test_modo_de_teste_registra_so_no_ensaio(): void
    {
        $oficial = $this->projeto();
        $ensaio = $this->projeto('Projeto de ensaio');
        $this->publicar([$oficial]);
        $this->publicar([$ensaio], demo: true);
        $this->comoAdmin(['is_demo' => true]);

        // Finalista de verdade não é alcançado pelo ensaio.
        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo?teste=1'), [
            'codigo' => $this->codigoDoAluno($oficial, 'Ana Paula'),
        ])->assertStatus(422);

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo?teste=1'), [
            'codigo' => $this->codigoDoAluno($ensaio, 'Ana Paula'),
        ])->assertOk();

        $this->assertTrue(CerimonialCheckin::sole()->demo);
    }

    public function test_busca_por_nome_e_por_cpf(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $porNome = $this->getJson($this->comEvento('/api/v1/admin/cerimonial/busca?busca=ana'))->assertOk();
        $this->assertSame('Ana Paula', $porNome->json('data.0.nome'));

        // Sem acento e com o CPF: os dois caminhos da fila.
        $this->assertNotEmpty($this->getJson($this->comEvento('/api/v1/admin/cerimonial/busca?busca=marta'))->json('data'));
        $cpfDaAna = $projeto->alunos->firstWhere('nome', 'Ana Paula')->cpf;
        $porCpf = $this->getJson($this->comEvento('/api/v1/admin/cerimonial/busca?busca=').$cpfDaAna)->assertOk();
        $this->assertSame('Ana Paula', $porCpf->json('data.0.nome'));
    }

    public function test_busca_nao_alcanca_quem_nao_e_finalista(): void
    {
        $finalista = $this->projeto();
        $this->projeto('Projeto de fora');
        $this->publicar([$finalista]);
        $this->comoAdmin();

        $resultados = $this->getJson($this->comEvento('/api/v1/admin/cerimonial/busca?busca=ana'))->json('data');

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

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), ['participantes' => [$chave]])->assertOk();

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/desfazer"), [
            'participante' => $chave,
        ])->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $resposta = $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/desfazer"), [
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
        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$premiado->id}/checkin"), [
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

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), [
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

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), [
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

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), [
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
        $this->getJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$oficial->id}?teste=1"))->assertNotFound();

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$ensaio->id}/checkin?teste=1"), [
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
        $this->getJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$ensaio->id}?teste=1"))->assertNotFound();
        $this->getJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$oficial->id}?teste=1"))->assertOk();
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

        $this->getJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}"))->assertOk();
        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin"), [
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

    // ------------------------------------------------------------------ //
    // Eventos de cerimônia (Sprint 207)                                   //
    // ------------------------------------------------------------------ //

    /** As duas premiações da feira: FETECMS numa, FETEC Jr + FUNDECT na outra. */
    private function duasCerimonias(): array
    {
        $this->evento->delete();

        return [
            CerimonialEvento::create(['edicao_id' => $this->edicao->id, 'nome' => 'Premiação FETECMS', 'categorias' => ['fetecms']]),
            CerimonialEvento::create(['edicao_id' => $this->edicao->id, 'nome' => 'Premiação FETEC Jr e FUNDECT', 'categorias' => ['fetec_jr', 'fetecms_fundect']]),
        ];
    }

    public function test_admin_cria_edita_e_exclui_evento_com_o_publico(): void
    {
        $this->comoAdmin();

        $criado = $this->postJson('/api/v1/admin/cerimonial/eventos', [
            'nome' => 'Premiação FETEC Jr e FUNDECT',
            'categorias' => ['fetecms_fundect', 'fetec_jr'],
        ])->assertCreated();

        $this->assertSame(['fetec_jr', 'fetecms_fundect'], $criado->json('data.categorias'));
        $this->assertSame('FETEC Jr e FETECMS FUNDECT', $criado->json('data.publico'));

        $id = $criado->json('data.id');
        $this->putJson("/api/v1/admin/cerimonial/eventos/{$id}", [
            'nome' => 'Premiação Jr',
            'categorias' => ['fetec_jr'],
        ])->assertOk()->assertJsonPath('data.publico', 'FETEC Jr');

        // O nome é o que a porta escolhe: não pode repetir na edição.
        $this->postJson('/api/v1/admin/cerimonial/eventos', [
            'nome' => 'Premiação Jr', 'categorias' => ['fetecms'],
        ])->assertStatus(422)->assertJsonValidationErrors('nome');

        $this->postJson('/api/v1/admin/cerimonial/eventos', ['nome' => 'Sem público', 'categorias' => []])
            ->assertStatus(422)->assertJsonValidationErrors('categorias');
        $this->postJson('/api/v1/admin/cerimonial/eventos', ['nome' => 'Inventada', 'categorias' => ['pictec']])
            ->assertStatus(422)->assertJsonValidationErrors('categorias.0');

        $this->deleteJson("/api/v1/admin/cerimonial/eventos/{$id}")->assertOk();
        $this->assertNull(CerimonialEvento::find($id));

        // A config leva os eventos para a tela.
        $this->assertSame(['Cerimônia de premiação'], array_column(
            $this->getJson('/api/v1/admin/cerimonial/config')->json('data.eventos'),
            'nome',
        ));
    }

    public function test_uma_categoria_pode_estar_em_mais_de_um_evento(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/v1/admin/cerimonial/eventos', [
            'nome' => 'Premiação FETECMS', 'categorias' => ['fetecms'],
        ])->assertCreated();

        $this->assertSame(2, CerimonialEvento::count());
    }

    public function test_evento_com_check_in_nao_e_excluido_mas_o_do_ensaio_vai_junto(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $admin = $this->comoAdmin();

        CerimonialCheckin::create([
            'edicao_id' => $this->edicao->id, 'evento_id' => $this->evento->id, 'projeto_id' => $projeto->id,
            'papel' => 'A', 'participante_id' => $projeto->alunos->first()->id, 'nome' => 'Ana Paula',
            'checkin_em' => now(), 'registrado_por' => $admin->id, 'demo' => true,
        ]);
        $outro = CerimonialEvento::create(['edicao_id' => $this->edicao->id, 'nome' => 'Outro', 'categorias' => ['fetecms']]);
        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", $outro), [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertOk();

        $this->deleteJson("/api/v1/admin/cerimonial/eventos/{$outro->id}")
            ->assertStatus(422)->assertJsonValidationErrors('evento');

        // Só ensaio: sai, e o ensaio sai com ele.
        $this->deleteJson("/api/v1/admin/cerimonial/eventos/{$this->evento->id}")->assertOk();
        $this->assertSame(1, CerimonialCheckin::count());
    }

    public function test_conta_temporaria_ve_os_eventos_mas_nao_os_cadastra(): void
    {
        $conta = $this->contaTemporaria();
        Sanctum::actingAs($conta->user);

        $this->getJson('/api/v1/admin/cerimonial/eventos')->assertOk()->assertJsonPath('data.0.nome', 'Cerimônia de premiação');
        $this->postJson('/api/v1/admin/cerimonial/eventos', ['nome' => 'Minha', 'categorias' => ['fetecms']])->assertForbidden();
        $this->deleteJson("/api/v1/admin/cerimonial/eventos/{$this->evento->id}")->assertForbidden();
    }

    public function test_check_in_sem_evento_pede_o_evento_e_evento_de_outra_edicao_nao_existe(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $this->postJson("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertStatus(422)->assertJsonPath('errors.evento_id.0', 'Escolha o evento de cerimônia.');

        $outraEdicao = Edicao::create(['nome' => 'XV FETECMS', 'ano' => 2025]);
        $alheio = CerimonialEvento::create(['edicao_id' => $outraEdicao->id, 'nome' => 'Antiga', 'categorias' => ['fetecms']]);

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$projeto->id}/checkin", $alheio), [
            'participantes' => [$this->chaveAluno($projeto, 'Ana Paula')],
        ])->assertNotFound();
    }

    public function test_cada_cerimonia_recebe_so_o_seu_publico(): void
    {
        [$fetecms, $jr] = $this->duasCerimonias();
        $daFetecms = $this->projeto('Bioplástico de mandioca');
        $daJr = $this->projeto('Robô seguidor de linha', Categoria::FetecJr);
        $this->publicar([$daFetecms, $daJr]);
        $this->comoAdmin();

        // O crachá certo na porta errada diz de quem é.
        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo', $fetecms), [
            'codigo' => $this->codigoDoAluno($daJr, 'Ana Paula'),
        ])->assertStatus(422)
            ->assertJsonPath('errors.codigo.0', 'Ana Paula é de um projeto da FETEC Jr, que não está no público de Premiação FETECMS.');

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$daJr->id}/checkin", $fetecms), [
            'participantes' => [$this->chaveAluno($daJr, 'Ana Paula')],
        ])->assertStatus(422)->assertJsonValidationErrors('participantes');

        $this->getJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$daJr->id}", $fetecms))->assertNotFound();

        // A busca de uma porta só acha o público dela.
        $busca = $this->getJson($this->comEvento('/api/v1/admin/cerimonial/busca?busca=ana', $fetecms))->assertOk();
        $this->assertSame([$daFetecms->id], array_values(array_unique(array_column($busca->json('data'), 'projeto_id'))));

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo', $jr), [
            'codigo' => $this->codigoDoAluno($daJr, 'Ana Paula'),
        ])->assertOk();

        $this->assertSame($jr->id, CerimonialCheckin::sole()->evento_id);
    }

    public function test_quem_vai_as_duas_cerimonias_entra_nas_duas_e_conta_uma_vez_em_todos(): void
    {
        [$fetecms] = $this->duasCerimonias();
        $geral = CerimonialEvento::create(['edicao_id' => $this->edicao->id, 'nome' => 'Cerimônia geral', 'categorias' => ['fetecms', 'fetec_jr', 'fetecms_fundect']]);
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();
        $codigo = $this->codigoDoAluno($projeto, 'Ana Paula');

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo', $fetecms), ['codigo' => $codigo])
            ->assertOk()->assertJsonPath('data.ja_presente', false);
        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo', $geral), ['codigo' => $codigo])
            ->assertOk()->assertJsonPath('data.ja_presente', false);

        $this->assertSame(2, CerimonialCheckin::count());
        $this->assertSame(1, $this->getJson($this->comEvento('/api/v1/admin/cerimonial/visao-geral', $geral))->json('data.pessoas.presentes'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/cerimonial/visao-geral')->json('data.pessoas.presentes'));

        $registro = RegistroAtividade::where('tipo', TipoRegistro::CerimonialCheckin)->latest('id')->firstOrFail();
        $this->assertSame('Cerimônia geral', $registro->detalhes['evento']);
    }

    public function test_visao_geral_por_evento_e_somando_todos(): void
    {
        [$fetecms, $jr] = $this->duasCerimonias();
        $daFetecms = $this->projeto('Bioplástico de mandioca');
        $daJr = $this->projeto('Robô seguidor de linha', Categoria::FetecJr);
        $this->publicar([$daFetecms, $daJr]);
        $this->premiar($daJr);
        $this->comoAdmin();

        $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$daJr->id}/checkin", $jr), [
            'participantes' => [$this->chaveAluno($daJr, 'Ana Paula'), $this->chaveAluno($daJr, 'Bruno Lima')],
        ])->assertOk();

        // Cada projeto tem quatro pessoas: dois alunos, orientador e coorientador.
        $doJr = $this->getJson($this->comEvento('/api/v1/admin/cerimonial/visao-geral', $jr))->json('data');
        $this->assertSame([2, 4], [$doJr['pessoas']['presentes'], $doJr['pessoas']['total']]);
        $this->assertSame(2, $doJr['medalhas']['separar']);

        $daFet = $this->getJson($this->comEvento('/api/v1/admin/cerimonial/visao-geral', $fetecms))->json('data');
        $this->assertSame([0, 4], [$daFet['pessoas']['presentes'], $daFet['pessoas']['total']]);
        $this->assertSame(0, $daFet['premiados']['total']);

        $todos = $this->getJson('/api/v1/admin/cerimonial/visao-geral')->json('data');
        $this->assertSame([2, 8], [$todos['pessoas']['presentes'], $todos['pessoas']['total']]);

        $this->assertCount(1, $this->getJson($this->comEvento('/api/v1/admin/cerimonial/premiados', $jr))->json('data'));
        $this->assertCount(0, $this->getJson($this->comEvento('/api/v1/admin/cerimonial/premiados', $fetecms))->json('data'));
        $this->assertCount(4, $this->getJson($this->comEvento('/api/v1/admin/cerimonial/visao-geral/detalhe?card=pessoas', $fetecms))->json('data.faltantes'));
        $this->assertCount(0, $this->getJson($this->comEvento('/api/v1/admin/cerimonial/visao-geral/detalhe?card=credenciais', $fetecms))->json('data.faltantes'));
    }

    public function test_sem_evento_cadastrado_a_visao_geral_fica_vazia(): void
    {
        $this->evento->delete();
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $this->assertSame(0, $this->getJson('/api/v1/admin/cerimonial/visao-geral')->json('data.pessoas.total'));
        $this->assertSame([], $this->getJson('/api/v1/admin/cerimonial/config')->json('data.eventos'));
    }

    // ------------------------------------------------------------------ //
    // A mesma pessoa em mais de um papel (Sprint 210)                      //
    // ------------------------------------------------------------------ //

    /** O código do crachá do orientador do projeto. */
    private function codigoDoOrientador(Projeto $projeto): string
    {
        return CodigoParticipante::montar(
            2026, $projeto->id, $projeto->user->orientadorProfile->cpf, CodigoParticipante::PAPEL_ORIENTADOR, $projeto->user_id,
        );
    }

    /** A professora orienta o primeiro projeto e coorienta o segundo (mesmo CPF). */
    private function professoraEmDoisPapeis(Categoria $categoriaDoSegundo = Categoria::Fetecms): array
    {
        $orientado = $this->projeto('Bioplástico de mandioca');
        $coorientado = $this->projeto('Robô seguidor de linha', $categoriaDoSegundo);
        $orientado->user->update(['name' => 'Marta Orientadora']);
        $coorientado->coorientador->update([
            'nome' => 'Marta Orientadora',
            'cpf' => $orientado->user->orientadorProfile->cpf,
        ]);

        return [$orientado->fresh(), $coorientado->fresh()];
    }

    public function test_cracha_de_um_papel_marca_a_mesma_pessoa_nos_outros_papeis(): void
    {
        [$orientado, $coorientado] = $this->professoraEmDoisPapeis();
        $this->publicar([$orientado, $coorientado]);
        $this->comoAdmin();

        $resposta = $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoOrientador($orientado),
        ])->assertOk();

        $this->assertSame([[
            'projeto_id' => $coorientado->id,
            'projeto_titulo' => 'Robô seguidor de linha',
            'chave' => 'C'.$coorientado->coorientador->id,
            'nome' => 'Marta Orientadora',
            'papel_label' => CodigoParticipante::papelLabel('C'),
            'ja_presente' => false,
        ]], $resposta->json('data.vinculos'));

        $this->assertSame(2, CerimonialCheckin::count());
        $this->assertTrue(CerimonialCheckin::where('projeto_id', $coorientado->id)->where('papel', 'C')->exists());

        $junto = RegistroAtividade::where('tipo', TipoRegistro::CerimonialCheckin)->where('projeto_id', $coorientado->id)->firstOrFail();
        $this->assertStringContainsString('Bioplástico de mandioca', $junto->detalhes['junto_de']);

        // A segunda leitura não duplica nada: os dois já constam.
        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoOrientador($orientado),
        ])->assertOk()->assertJsonPath('data.ja_presente', true)->assertJsonPath('data.vinculos.0.ja_presente', true);
        $this->assertSame(2, CerimonialCheckin::count());
    }

    public function test_orientador_de_dois_projetos_entra_nos_dois_pela_ficha(): void
    {
        $primeiro = $this->projeto('Bioplástico de mandioca');
        $segundo = $this->projeto('Robô seguidor de linha');
        $segundo->update(['user_id' => $primeiro->user_id]);
        $this->publicar([$primeiro, $segundo->fresh()]);
        $this->comoAdmin();

        $resposta = $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$primeiro->id}/checkin"), [
            'participantes' => ['O'.$primeiro->user_id],
        ])->assertOk();

        $this->assertSame([$segundo->id], array_column($resposta->json('data.vinculados'), 'projeto_id'));
        $this->assertSame(2, CerimonialCheckin::where('papel', 'O')->count());
    }

    public function test_o_vinculo_so_e_marcado_dentro_do_publico_do_evento(): void
    {
        [$fetecms] = $this->duasCerimonias();
        [$orientado, $coorientado] = $this->professoraEmDoisPapeis(Categoria::FetecJr);
        $this->publicar([$orientado, $coorientado]);
        $this->comoAdmin();

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo', $fetecms), [
            'codigo' => $this->codigoDoOrientador($orientado),
        ])->assertOk()->assertJsonPath('data.vinculos', []);

        // Na cerimônia da FETEC Jr ela ainda não entrou.
        $this->assertSame(1, CerimonialCheckin::count());
    }

    public function test_sem_cpf_vale_o_mesmo_email_com_o_mesmo_nome(): void
    {
        [$orientado, $coorientado] = $this->professoraEmDoisPapeis();
        $coorientado->coorientador->update(['cpf' => null, 'email' => strtoupper($orientado->user->email)]);
        $outro = $this->projeto('Horta vertical');
        // Mesmo e-mail, outro nome: é o estudante cadastrado com o e-mail da professora.
        $outro->alunos->first()->update(['cpf' => null, 'email' => $orientado->user->email, 'nome' => 'Pedro Aluno']);
        $this->publicar([$orientado, $coorientado->fresh(), $outro->fresh()]);
        $this->comoAdmin();

        $resposta = $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoOrientador($orientado),
        ])->assertOk();

        $this->assertSame([$coorientado->id], array_column($resposta->json('data.vinculos'), 'projeto_id'));
    }

    public function test_cpf_diferente_nao_liga_mesmo_com_o_mesmo_nome(): void
    {
        $um = $this->projeto('Bioplástico de mandioca');
        $dois = $this->projeto('Robô seguidor de linha');
        // Os dois orientadores se chamam "Marta Orientadora", com CPFs diferentes.
        $this->publicar([$um, $dois]);
        $this->comoAdmin();

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoOrientador($um),
        ])->assertOk()->assertJsonPath('data.vinculos', []);
    }

    public function test_desfazer_um_papel_desfaz_os_outros_da_mesma_pessoa(): void
    {
        [$orientado, $coorientado] = $this->professoraEmDoisPapeis();
        $this->publicar([$orientado, $coorientado]);
        $this->comoAdmin();

        $this->postJson($this->comEvento('/api/v1/admin/cerimonial/checkin-codigo'), [
            'codigo' => $this->codigoDoOrientador($orientado),
        ])->assertOk();
        $this->assertSame(2, CerimonialCheckin::count());

        $resposta = $this->postJson($this->comEvento("/api/v1/admin/cerimonial/projetos/{$coorientado->id}/desfazer"), [
            'participante' => 'C'.$coorientado->coorientador->id,
            'justificativa' => 'Saiu antes da cerimônia.',
        ])->assertOk();

        $this->assertSame(0, CerimonialCheckin::count());
        $this->assertSame([$orientado->id], array_column($resposta->json('data.desfeitos_juntos'), 'projeto_id'));
        $this->assertSame(2, RegistroAtividade::where('tipo', TipoRegistro::CerimonialCheckinDesfeito)->count());
    }

    public function test_detalhe_das_pessoas_traz_a_chave_para_marcar_a_chegada(): void
    {
        $projeto = $this->projeto();
        $this->publicar([$projeto]);
        $this->comoAdmin();

        $faltantes = $this->getJson($this->comEvento('/api/v1/admin/cerimonial/visao-geral/detalhe?card=pessoas'))->json('data.faltantes');

        $this->assertContains('O'.$projeto->user_id, array_column($faltantes, 'chave'));
    }
}
