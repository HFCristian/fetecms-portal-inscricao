<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\AvaliadorService;
use App\Services\DistribuicaoService;
use App\Services\FilaAvaliadorService;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Algoritmo de distribuição (Avaliação Online → Algoritmo de distribuição): o
 * admin escolhe quais categorias entram, em que faixa de avaliações concluídas
 * o projeto ainda aceita avaliador novo, redistribui a rodada e decide se quem
 * acaba de se cadastrar já sai com a fila cheia.
 */
class AlgoritmoDistribuicaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class); // cria a edição atual
    }

    private function avaliador(int $areaId): User
    {
        $user = User::factory()->avaliador()->create();
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $areaId]);

        return $user;
    }

    private function projeto(int $areaId, Categoria $categoria, string $titulo = 'Projeto'): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'user_id' => User::factory()->create()->id,
            'area_id' => $areaId, 'categoria' => $categoria, 'titulo' => $titulo,
        ]);
    }

    /** @return array{ativa:bool, min_concluidas:int, max_concluidas:int|null, designacoes:int|null} */
    private function regra(bool $ativa = true, int $min = 0, ?int $max = null, ?int $designacoes = null): array
    {
        return [
            'ativa' => $ativa, 'min_concluidas' => $min,
            'max_concluidas' => $max, 'designacoes' => $designacoes,
        ];
    }

    private function concluir(Projeto $projeto, int $quantas): void
    {
        for ($i = 0; $i < $quantas; $i++) {
            Avaliacao::create([
                'projeto_id' => $projeto->id,
                'avaliador_id' => User::factory()->avaliador()->create(['is_demo' => true])->id,
                'status' => StatusAvaliacao::Concluida,
                'nota' => 8,
                'concluida_em' => now(),
            ]);
        }
    }

    /** @param  array<string, mixed>  $over */
    private function cadastrarAvaliador(int $areaId, array $over = []): User
    {
        return app(AvaliadorService::class)->register([
            'name' => 'Ana Avaliadora',
            'email' => 'ana@exemplo.test',
            'password' => 'senha-de-teste-123',
            'cpf' => '52998224725',
            'titulacao' => 'Mestrado (concluído)',
            'area_id' => $areaId,
        ] + $over);
    }

    public function test_config_nasce_com_todas_as_categorias_liberadas(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/avaliacao/distribuicao')
            ->assertOk()
            ->assertJsonPath('data.regras.fetecms.ativa', true)
            ->assertJsonPath('data.regras.fetec_jr.min_concluidas', 0)
            ->assertJsonPath('data.regras.fetecms_fundect.max_concluidas', null)
            ->assertJsonPath('data.ao_cadastrar', false)
            ->assertJsonCount(3, 'data.categorias');
    }

    public function test_admin_salva_as_regras_e_a_mudanca_vira_registro(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao', ['regras' => [
            'fetecms' => $this->regra(false),
            'fetec_jr' => $this->regra(true, 0, 0),
            'fetecms_fundect' => $this->regra(true, 1),
        ]])
            ->assertOk()
            ->assertJsonPath('data.regras.fetecms.ativa', false)
            ->assertJsonPath('data.regras.fetec_jr.max_concluidas', 0)
            ->assertJsonPath('data.regras.fetecms_fundect.min_concluidas', 1);

        $regras = Edicao::regrasDistribuicao();
        $this->assertFalse($regras->para(Categoria::Fetecms)['ativa']);
        $this->assertSame(0, $regras->para(Categoria::FetecJr)['max_concluidas']);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoRegraDistribuicao->value)->first();
        $this->assertNotNull($registro);
        $this->assertStringContainsString('FETEC Jr: 0 a 0', $registro->detalhes['para']);
    }

    public function test_faixa_invertida_nao_passa_na_validacao(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao', ['regras' => [
            'fetecms' => $this->regra(true, 3, 1),
            'fetec_jr' => $this->regra(),
            'fetecms_fundect' => $this->regra(),
        ]])->assertStatus(422)->assertJsonValidationErrors('regras.fetecms.max_concluidas');
    }

    public function test_distribuicao_ignora_categoria_desligada(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $this->avaliador($area->id);
        $this->avaliador($area->id);
        $this->avaliador($area->id);

        $dentro = $this->projeto($area->id, Categoria::FetecJr, 'Entra');
        $fora = $this->projeto($area->id, Categoria::Fetecms, 'Fica de fora');

        Edicao::atual()->update(['distribuicao_regras' => [
            'fetecms' => $this->regra(false),
            'fetec_jr' => $this->regra(),
            'fetecms_fundect' => $this->regra(),
        ]]);

        $resultado = app(DistribuicaoService::class)->distribuir();

        $this->assertSame(3, Avaliacao::where('projeto_id', $dentro->id)->count());
        $this->assertSame(0, Avaliacao::where('projeto_id', $fora->id)->count());
        $this->assertSame(1, $resultado['ignorados_pela_regra']);
        // O projeto excluído pela regra não é "sub-coberto": ele nem concorria.
        $this->assertSame([], $resultado['sub_cobertos']);
    }

    public function test_distribuicao_respeita_a_faixa_de_avaliacoes_concluidas(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $this->avaliador($area->id);

        $zerado = $this->projeto($area->id, Categoria::FetecJr, 'Sem avaliação');
        $comUma = $this->projeto($area->id, Categoria::FetecJr, 'Já avaliado');
        $this->concluir($comUma, 1);

        Edicao::atual()->update(['distribuicao_regras' => [
            'fetecms' => $this->regra(),
            'fetec_jr' => $this->regra(true, 0, 0), // só quem ainda não foi avaliado
            'fetecms_fundect' => $this->regra(),
        ]]);

        app(DistribuicaoService::class)->distribuir();

        $this->assertSame(1, Avaliacao::where('projeto_id', $zerado->id)->count());
        $this->assertSame(1, Avaliacao::where('projeto_id', $comUma->id)->count()); // só a que já existia
    }

    public function test_faixa_conta_as_avaliacoes_recebidas_pelo_projeto(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $this->avaliador($area->id);
        $this->avaliador($area->id);

        // "FUNDECT de 0 a 1": entra o projeto com uma avaliação recebida, não o
        // que já recebeu duas — a conta é do projeto, não da carga do avaliador.
        $comUma = $this->projeto($area->id, Categoria::FetecmsFundect, 'Recebeu uma');
        $this->concluir($comUma, 1);

        $comDuas = $this->projeto($area->id, Categoria::FetecmsFundect, 'Recebeu duas');
        $this->concluir($comDuas, 2);

        Edicao::atual()->update(['distribuicao_regras' => [
            'fetecms' => $this->regra(),
            'fetec_jr' => $this->regra(),
            'fetecms_fundect' => $this->regra(true, 0, 1),
        ]]);

        app(DistribuicaoService::class)->distribuir();

        $this->assertSame(2, Avaliacao::where('projeto_id', $comUma->id)
            ->where('status', StatusAvaliacao::Designada->value)->count());
        $this->assertSame(0, Avaliacao::where('projeto_id', $comDuas->id)
            ->where('status', StatusAvaliacao::Designada->value)->count());
    }

    /**
     * Com o piso desligado, a regra manda sozinha: o projeto que ela exclui não
     * entra na fila de ninguém, mesmo que isso deixe o avaliador sem trabalho.
     */
    public function test_reposicao_da_fila_tambem_respeita_a_regra(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $this->projeto($area->id, Categoria::Fetecms, 'Só FETECMS disponível');

        Edicao::atual()->update([
            'piso_fila_avaliador' => null,
            'distribuicao_regras' => [
                'fetecms' => $this->regra(false),
                'fetec_jr' => $this->regra(),
                'fetecms_fundect' => $this->regra(),
            ],
        ]);

        $this->assertSame(0, app(FilaAvaliadorService::class)->repor($avaliador));
        $this->assertSame(0, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    /**
     * Sprint 85 — o piso. A regra continua escolhendo quem entra primeiro; ela
     * só não pode deixar o avaliador parado. Com a regra excluindo o único
     * projeto disponível, a segunda passada o traz assim mesmo.
     */
    public function test_piso_completa_a_fila_ignorando_a_regra(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $projeto = $this->projeto($area->id, Categoria::Fetecms, 'Só FETECMS disponível');

        Edicao::atual()->update([
            'piso_fila_avaliador' => 6,
            'distribuicao_regras' => [
                'fetecms' => $this->regra(false),
                'fetec_jr' => $this->regra(),
                'fetecms_fundect' => $this->regra(),
            ],
        ]);

        $this->assertSame(1, app(FilaAvaliadorService::class)->repor($avaliador));
        $this->assertSame(1, Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('projeto_id', $projeto->id)->count());
    }

    /**
     * A ordem importa: quem a regra aceita entra primeiro, e o piso só completa
     * o que faltou. Com projetos de sobra dentro da regra, ela vale sozinha.
     */
    public function test_piso_nao_entra_quando_a_regra_ja_enche_a_fila(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        // Um excluído pela regra e seis aceitos: a fila fecha nos aceitos.
        $excluido = $this->projeto($area->id, Categoria::Fetecms, 'Excluído pela regra');
        for ($i = 1; $i <= 6; $i++) {
            $this->projeto($area->id, Categoria::FetecJr, "Aceito {$i}");
        }

        Edicao::atual()->update([
            'piso_fila_avaliador' => 6,
            'avaliacoes_min_por_avaliador' => 6,
            'distribuicao_regras' => [
                'fetecms' => $this->regra(false),
                'fetec_jr' => $this->regra(),
                'fetecms_fundect' => $this->regra(),
            ],
        ]);

        app(FilaAvaliadorService::class)->repor($avaliador);

        $this->assertSame(6, Avaliacao::where('avaliador_id', $avaliador->id)->count());
        $this->assertSame(0, Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('projeto_id', $excluido->id)->count());
    }

    /** Sem regra restritiva não há segunda passada: o mínimo já é o tamanho da fila. */
    public function test_piso_nao_muda_nada_com_as_regras_liberadas(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        for ($i = 1; $i <= 5; $i++) {
            $this->projeto($area->id, Categoria::Fetecms, "Projeto {$i}");
        }

        Edicao::atual()->update([
            'piso_fila_avaliador' => 6,
            'avaliacoes_min_por_avaliador' => 3,
        ]);

        app(FilaAvaliadorService::class)->repor($avaliador);

        // Fica no mínimo (3), não no piso: o piso é uma rede, não um alvo.
        $this->assertSame(3, Avaliacao::where('avaliador_id', $avaliador->id)->count());
    }

    /** O botão "Sortear outros projetos" herda o piso, pela mesma reposição. */
    public function test_piso_vale_no_sorteio_do_avaliador(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $aceito = $this->projeto($area->id, Categoria::FetecJr, 'Aceito pela regra');
        $excluido = $this->projeto($area->id, Categoria::Fetecms, 'Excluído pela regra');

        Edicao::atual()->update([
            'piso_fila_avaliador' => 6,
            'distribuicao_regras' => [
                'fetecms' => $this->regra(false),
                'fetec_jr' => $this->regra(),
                'fetecms_fundect' => $this->regra(),
            ],
        ]);

        Avaliacao::create([
            'projeto_id' => $aceito->id,
            'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada,
        ]);

        app(FilaAvaliadorService::class)->roletar($avaliador);

        // Os dois projetos que existem cabem na fila, inclusive o que a regra
        // excluía — o avaliador não fica com menos do que poderia trabalhar.
        $this->assertSame(2, Avaliacao::where('avaliador_id', $avaliador->id)->count());
        $this->assertSame(1, Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('projeto_id', $excluido->id)->count());
    }

    public function test_redistribuicao_troca_designadas_e_preserva_o_resto(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);

        $designado = $this->projeto($area->id, Categoria::Fetecms, 'Designado');
        $emAvaliacao = $this->projeto($area->id, Categoria::Fetecms, 'Em avaliação');
        $manual = $this->projeto($area->id, Categoria::Fetecms, 'Designado pelo admin');
        $novo = $this->projeto($area->id, Categoria::Fetecms, 'Ainda sem ninguém');

        $trocavel = Avaliacao::create([
            'projeto_id' => $designado->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada,
        ]);
        $iniciada = Avaliacao::create([
            'projeto_id' => $emAvaliacao->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::EmAndamento,
        ]);
        $doAdmin = Avaliacao::create([
            'projeto_id' => $manual->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada, 'designacao_manual' => true,
        ]);

        $relatorio = app(DistribuicaoService::class)->redistribuir();

        $this->assertSame(1, $relatorio['devolvidas']);
        // A designada saiu; a iniciada e a do admin continuam onde estavam.
        $this->assertDatabaseMissing('avaliacoes', ['id' => $trocavel->id]);
        $this->assertDatabaseHas('avaliacoes', ['id' => $iniciada->id, 'projeto_id' => $emAvaliacao->id]);
        $this->assertDatabaseHas('avaliacoes', ['id' => $doAdmin->id, 'projeto_id' => $manual->id]);
        // E entrou outro projeto no lugar do que foi devolvido.
        $this->assertSame(1, Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('projeto_id', $novo->id)->count());
    }

    public function test_redistribuicao_devolve_o_mesmo_projeto_quando_nao_ha_alternativa(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $avaliador = $this->avaliador($area->id);
        $unico = $this->projeto($area->id, Categoria::Fetecms, 'Único da área');

        Avaliacao::create([
            'projeto_id' => $unico->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Designada,
        ]);

        app(DistribuicaoService::class)->redistribuir();

        // A fila não encolhe: sem outro projeto, o mesmo volta.
        $this->assertSame(1, Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('projeto_id', $unico->id)->count());
    }

    public function test_toggle_designa_projetos_no_cadastro_do_avaliador(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $this->projeto($area->id, Categoria::Fetecms, 'Projeto da área');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson('/api/v1/admin/avaliacao/distribuicao/ao-cadastrar', ['ao_cadastrar' => true])
            ->assertOk()
            ->assertJsonPath('data.ao_cadastrar', true);

        $novo = $this->cadastrarAvaliador($area->id);

        $this->assertSame(1, Avaliacao::where('avaliador_id', $novo->id)->count());
        $this->assertDatabaseHas('registros_atividade', [
            'tipo' => TipoRegistro::AvaliacaoDesignacaoAoCadastrar->value,
        ]);
    }

    public function test_sem_o_toggle_o_avaliador_novo_entra_com_a_fila_vazia(): void
    {
        $area = Area::create(['nome' => 'Área A']);
        $this->projeto($area->id, Categoria::Fetecms, 'Projeto da área');

        $novo = $this->cadastrarAvaliador($area->id, ['email' => 'bruno@exemplo.test']);

        $this->assertSame(0, Avaliacao::where('avaliador_id', $novo->id)->count());
    }

    // ------------------------------------------------------------------ //
    // Sprint 106 — designações por projeto                                //
    // ------------------------------------------------------------------ //

    public function test_sem_configurar_a_distribuicao_continua_parando_no_minimo(): void
    {
        $area = Area::first();
        $projeto = $this->projeto($area->id, Categoria::Fetecms);
        // Gente de sobra: o que limita é o alvo, não a oferta.
        for ($i = 0; $i < 6; $i++) {
            $this->avaliador($area->id);
        }

        app(DistribuicaoService::class)->distribuir();

        // O padrão da edição é 3, e é onde ele para.
        $this->assertSame(3, Avaliacao::where('projeto_id', $projeto->id)->count());
    }

    public function test_designacoes_por_projeto_faz_a_distribuicao_ir_alem_do_minimo(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $area = Area::first();
        $projeto = $this->projeto($area->id, Categoria::Fetecms);
        for ($i = 0; $i < 6; $i++) {
            $this->avaliador($area->id);
        }

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao/designacoes', [
            'designacoes_por_projeto' => 5,
        ])->assertOk()->assertJsonPath('data.designacoes_por_projeto', 5);

        app(DistribuicaoService::class)->distribuir();

        $this->assertSame(5, Avaliacao::where('projeto_id', $projeto->id)->count());

        // A mudança de parâmetro entra na trilha.
        $registro = RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoDesignacoesProjeto)->firstOrFail();
        $this->assertSame('segue o mínimo por projeto', $registro->detalhes['de']);
        $this->assertSame('5 avaliador(es)', $registro->detalhes['para']);
    }

    public function test_o_maximo_por_projeto_continua_sendo_o_teto_duro(): void
    {
        $area = Area::first();
        $projeto = $this->projeto($area->id, Categoria::Fetecms);
        for ($i = 0; $i < 10; $i++) {
            $this->avaliador($area->id);
        }

        // Pedir 8 designações com teto de 4 entrega 4.
        Edicao::atual()->update([
            'designacoes_por_projeto' => 8,
            'avaliacoes_max_por_projeto' => 4,
        ]);

        app(DistribuicaoService::class)->distribuir();

        $this->assertSame(4, Avaliacao::where('projeto_id', $projeto->id)->count());
    }

    public function test_designacoes_podem_ser_definidas_por_categoria(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $area = Area::first();
        $fetec = $this->projeto($area->id, Categoria::Fetecms, 'Da FETECMS');
        $jr = $this->projeto($area->id, Categoria::FetecJr, 'Da Jr');
        for ($i = 0; $i < 12; $i++) {
            $this->avaliador($area->id);
        }

        $this->patchJson('/api/v1/admin/avaliacao/distribuicao', [
            'regras' => [
                Categoria::Fetecms->value => $this->regra(designacoes: 5),
                Categoria::FetecJr->value => $this->regra(),
                Categoria::FetecmsFundect->value => $this->regra(),
            ],
        ])->assertOk();

        app(DistribuicaoService::class)->distribuir();

        $this->assertSame(5, Avaliacao::where('projeto_id', $fetec->id)->count());
        // A categoria sem número próprio segue o geral, que segue o mínimo.
        $this->assertSame(3, Avaliacao::where('projeto_id', $jr->id)->count());
    }

    public function test_designacoes_abaixo_do_minimo_nao_deixam_o_projeto_sub_coberto(): void
    {
        $area = Area::first();
        $projeto = $this->projeto($area->id, Categoria::Fetecms);
        for ($i = 0; $i < 6; $i++) {
            $this->avaliador($area->id);
        }

        // Pedir menos que o mínimo é contraditório: a cobertura ganha.
        Edicao::atual()->update(['designacoes_por_projeto' => 1]);

        app(DistribuicaoService::class)->distribuir();

        $this->assertSame(3, Avaliacao::where('projeto_id', $projeto->id)->count());
    }

    public function test_projeto_com_cobertura_fechada_nao_entra_como_sub_coberto(): void
    {
        $area = Area::first();
        $this->projeto($area->id, Categoria::Fetecms);
        // Só 4 avaliadores para um alvo de 6: fecha o mínimo (3), não o alvo.
        for ($i = 0; $i < 4; $i++) {
            $this->avaliador($area->id);
        }

        Edicao::atual()->update(['designacoes_por_projeto' => 6]);

        $resultado = app(DistribuicaoService::class)->distribuir();

        $this->assertSame(4, Avaliacao::count());
        // Ter menos designações que o alvo não é falta de cobertura.
        $this->assertSame([], $resultado['sub_cobertos']);
    }

    public function test_a_fila_do_avaliador_persegue_o_mesmo_alvo(): void
    {
        $area = Area::first();
        $projeto = $this->projeto($area->id, Categoria::Fetecms);
        Edicao::atual()->update(['designacoes_por_projeto' => 5]);

        // Um avaliador de cada vez: a reposição é que enche a fila dele.
        for ($i = 0; $i < 5; $i++) {
            app(FilaAvaliadorService::class)->repor($this->avaliador($area->id));
        }

        $this->assertSame(5, Avaliacao::where('projeto_id', $projeto->id)->count());
    }
}
