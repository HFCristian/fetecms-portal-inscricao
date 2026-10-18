<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comitê especial → Acompanhamento das avaliações: cada avaliador da comissão
 * com o que está avaliando, e o mapa do evento só com as avaliações do comitê.
 */
class ComiteAcompanhamentoTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/comite/acompanhamento';

    private Edicao $edicao;

    private ListaFinal $lista;

    private Area $agr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'tipo' => 'final', 'vigente' => true, 'versao' => 1,
        ]);
        $this->agr = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);
    }

    private function finalista(string $titulo, int $estande, string $turno = 'A', ?string $codigo = null): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id,
        ]);
        $this->lista->projetos()->attach($projeto->id, ['codigo' => $codigo]);
        EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno, 'numero' => $estande]);

        return $projeto;
    }

    private function avaliador(string $nome, bool $comissao = true, bool $demo = false): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome, 'is_demo' => $demo]);
        AvaliadorProfile::factory()->create([
            'user_id' => $user->id, 'presencial' => true, 'comissao_especial' => $comissao, 'area_id' => $this->agr->id,
        ]);

        return $user;
    }

    private function avaliacao(User $avaliador, Projeto $projeto, string $status, array $extra = []): AvaliacaoPresencial
    {
        return AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::from($status), 'vaga' => 'comite',
            'iniciada_em' => $status === 'designada' ? null : now()->subMinutes(20),
            'concluida_em' => $status === 'concluida' ? now()->subMinutes(5) : null,
            'nota' => $status === 'concluida' ? 8.5 : null,
            ...$extra,
        ]);
    }

    public function test_lista_cada_avaliador_da_comissao_com_o_que_esta_avaliando(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42, codigo: 'FET.AGR-001');
        $horta = $this->finalista('Horta vertical', 43);
        $ana = $this->avaliador('Ana Comissão');
        $bruno = $this->avaliador('Bruno Comissão');
        $this->avaliador('Carla Livre');
        $comum = $this->avaliador('Davi Comum', comissao: false);

        $this->avaliacao($ana, $abelhas, 'em_andamento');
        $this->avaliacao($ana, $horta, 'concluida');
        $this->avaliacao($bruno, $horta, 'designada');
        $this->avaliacao($comum, $abelhas, 'em_andamento');

        $avaliadores = collect($this->getJson(self::URL)->assertOk()->json('data.avaliadores'))->keyBy('nome');

        // Só a comissão — inclusive quem ainda não fez nada.
        $this->assertSame(['Ana Comissão', 'Bruno Comissão', 'Carla Livre'], $avaliadores->keys()->all());

        $this->assertSame('avaliando', $avaliadores['Ana Comissão']['situacao']);
        $this->assertSame('Abelhas nativas', $avaliadores['Ana Comissão']['em_avaliacao'][0]['titulo']);
        $this->assertSame('FET.AGR-001', $avaliadores['Ana Comissão']['em_avaliacao'][0]['codigo']);
        $this->assertSame(42, $avaliadores['Ana Comissão']['em_avaliacao'][0]['estande']);
        $this->assertSame('A', $avaliadores['Ana Comissão']['em_avaliacao'][0]['turno']);
        $this->assertCount(1, $avaliadores['Ana Comissão']['concluidas']);

        $this->assertSame('designado', $avaliadores['Bruno Comissão']['situacao']);
        $this->assertSame('livre', $avaliadores['Carla Livre']['situacao']);
    }

    public function test_o_mapa_so_enxerga_as_avaliacoes_do_comite(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $horta = $this->finalista('Horta vertical', 43);
        $solo = $this->finalista('Solo vivo', 44);
        $this->finalista('Da tarde', 42, 'B');
        $ana = $this->avaliador('Ana Comissão');
        $bruno = $this->avaliador('Bruno Comissão');
        $comum = $this->avaliador('Davi Comum', comissao: false);

        $this->avaliacao($ana, $abelhas, 'concluida');
        $this->avaliacao($bruno, $abelhas, 'em_andamento');
        $this->avaliacao($ana, $horta, 'concluida');
        // A avaliação de quem não é do comitê não pinta nada.
        $this->avaliacao($comum, $solo, 'concluida');

        $mapa = $this->getJson(self::URL.'?turno=A')->assertOk()->json('data.mapa');
        $estandes = $mapa['estandes'];

        $this->assertSame('em_avaliacao', $estandes['42']['situacao']);
        $this->assertSame(1, $estandes['42']['concluidas']);
        $this->assertSame(1, $estandes['42']['em_avaliacao']);
        $this->assertEqualsCanonicalizing(['Ana Comissão', 'Bruno Comissão'], array_column($estandes['42']['avaliacoes'], 'avaliador'));
        $this->assertSame('avaliado', $estandes['43']['situacao']);
        $this->assertSame('pendente', $estandes['44']['situacao']);
        $this->assertSame(0, $estandes['44']['concluidas']);
        $this->assertNotEmpty($mapa['layout']);

        // O turno B mostra o outro projeto do mesmo estande.
        $this->getJson(self::URL.'?turno=B')
            ->assertJsonPath('data.turno', 'B')
            ->assertJsonPath('data.mapa.estandes.42.titulo', 'Da tarde')
            ->assertJsonPath('data.mapa.estandes.42.situacao', 'pendente');
    }

    public function test_avaliacao_aberta_com_o_prazo_vencido_nao_conta_como_em_curso(): void
    {
        $ontem = now()->subDay()->toDateString();
        $this->edicao->update([
            'evento_de' => $ontem,
            'evento_ate' => now()->toDateString(),
            'agenda_presencial' => [$ontem => ['A' => ['inicio' => '08:00', 'fim' => '12:00', 'avaliacao_inicio' => '', 'avaliacao_fim' => '']]],
        ]);
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $ana = $this->avaliador('Ana Comissão');
        $this->avaliacao($ana, $abelhas, 'em_andamento', ['dia' => $ontem, 'turno' => 'A']);

        $dados = $this->getJson(self::URL.'?turno=A')->assertOk()->json('data');

        $this->assertSame('livre', $dados['avaliadores'][0]['situacao']);
        $this->assertSame(1, $dados['avaliadores'][0]['expiradas']);
        $this->assertSame('pendente', $dados['mapa']['estandes']['42']['situacao']);
    }

    public function test_conta_de_ensaio_fica_de_fora(): void
    {
        $abelhas = $this->finalista('Abelhas nativas', 42);
        $demo = $this->avaliador('Ensaio', demo: true);
        $this->avaliacao($demo, $abelhas, 'em_andamento');

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data.avaliadores')
            ->assertJsonPath('data.mapa.estandes.42.situacao', 'pendente');
    }

    public function test_turno_invalido_e_recusado(): void
    {
        $this->getJson(self::URL.'?turno=C')->assertStatus(422)->assertJsonValidationErrors('turno');
    }

    public function test_quem_nao_tem_a_aba_comite_nao_entra(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson(self::URL)->assertForbidden();
    }
}
