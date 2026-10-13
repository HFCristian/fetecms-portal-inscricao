<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\TipoCredencial;
use App\Models\Area;
use App\Models\AvaliadorProfile;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RecomendacaoPremiacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 196 — o painel da premiação: quanto ainda falta dar e o ranking dos
 * projetos premiados por área, para o admin e para a comissão especial.
 */
class PainelPremiacaoTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    /** @var array<string, Projeto> */
    private array $projetos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
        ]);
        $agrarias = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);
        $biologicas = Area::create(['nome' => 'Ciências Biológicas', 'sigla' => 'BIO']);

        foreach ([
            ['Bioplástico de mandioca', $agrarias, Categoria::Fetecms],
            ['Compostagem acelerada', $agrarias, Categoria::Fetecms],
            ['Abelhas nativas', $biologicas, Categoria::FetecJr],
            ['Microplásticos no rio', $biologicas, Categoria::FetecmsFundect],
        ] as [$titulo, $area, $categoria]) {
            $this->projetos[$titulo] = Projeto::factory()->submetido()->create([
                'titulo' => $titulo, 'area_id' => $area->id, 'categoria' => $categoria, 'edicao_id' => $this->edicao->id,
            ]);
        }

        $lista = ListaFinal::create(['edicao_id' => $this->edicao->id, 'nome' => 'Lista final', 'vigente' => true, 'versao' => 1]);
        $lista->projetos()->attach(collect($this->projetos)->pluck('id'));

        $mostratec = $this->credencial('MOSTRATEC 2027', 3, 1);
        $febrace = $this->credencial('FEBRACE 2027', 2, 2);
        $bolsa = $this->credencial('Bolsa IC Jr', null, 3);
        $destaque = $this->credencial('Destaque', 1, 4, TipoCredencial::Premio);
        $this->credencial('Cancelada', 5, 5, ativa: false);

        $this->premiar($mostratec, 'Bioplástico de mandioca');
        $this->premiar($febrace, 'Bioplástico de mandioca');
        $this->premiar($febrace, 'Abelhas nativas');
        $this->premiar($bolsa, 'Abelhas nativas');
        $this->premiar($bolsa, 'Microplásticos no rio');
        $this->premiar($destaque, 'Bioplástico de mandioca');

        // Uma aprovada ainda sem anexo (contada uma vez com dois avaliadores) e
        // uma aprovada que já foi anexada.
        foreach ([AvaliadorProfile::factory()->create()->user, AvaliadorProfile::factory()->create()->user] as $avaliador) {
            RecomendacaoPremiacao::create([
                'credencial_id' => $mostratec->id, 'projeto_id' => $this->projetos['Compostagem acelerada']->id,
                'avaliador_id' => $avaliador->id, 'status' => 'aprovada',
            ]);
        }
        RecomendacaoPremiacao::create([
            'credencial_id' => $mostratec->id, 'projeto_id' => $this->projetos['Bioplástico de mandioca']->id,
            'avaliador_id' => $avaliador->id, 'status' => 'aprovada',
        ]);
    }

    private function credencial(string $nome, ?int $vagas, int $ordem, TipoCredencial $tipo = TipoCredencial::Credencial, bool $ativa = true): Credencial
    {
        return Credencial::create([
            'edicao_id' => $this->edicao->id, 'nome' => $nome, 'vagas' => $vagas, 'ordem' => $ordem, 'tipo' => $tipo, 'ativa' => $ativa,
        ]);
    }

    private function premiar(Credencial $credencial, string $titulo): void
    {
        $credencial->projetos()->attach($this->projetos[$titulo]->id, ['atribuida_em' => now()]);
    }

    private function adminDoComite(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Comitê', 'abas' => ['comite']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);
    }

    public function test_o_resumo_conta_o_que_falta_so_onde_ha_limite(): void
    {
        $this->adminDoComite();

        $resumo = $this->getJson('/api/v1/admin/premiacao/painel')->assertOk()->json('data.resumo');

        $this->assertSame([
            'credenciais' => 4,              // a desativada fica de fora
            'vagas_total' => 6,              // 3 + 2 + 1 (a bolsa não tem limite)
            'vagas_preenchidas' => 4,        // 1 + 2 + 1
            'faltam' => 2,                   // só a MOSTRATEC tem vaga
            'credenciais_com_vaga' => 1,
            'esgotadas' => 2,                // FEBRACE e Destaque
            'sem_limite' => 1,
            'premiacoes' => 6,
            'projetos_premiados' => 3,
            'aprovadas_aguardando' => 1,
        ], $resumo);
    }

    public function test_cada_credencial_diz_quanto_falta_e_quem_levou(): void
    {
        $this->adminDoComite();

        $credenciais = collect($this->getJson('/api/v1/admin/premiacao/painel')->json('data.credenciais'))->keyBy('nome');

        $this->assertSame(['MOSTRATEC 2027', 'FEBRACE 2027', 'Bolsa IC Jr', 'Destaque'], $credenciais->keys()->all());
        $this->assertSame(2, $credenciais['MOSTRATEC 2027']['faltam']);
        $this->assertSame(1, $credenciais['MOSTRATEC 2027']['aprovadas_aguardando']);
        $this->assertTrue($credenciais['FEBRACE 2027']['esgotada']);
        $this->assertNull($credenciais['Bolsa IC Jr']['faltam']);
        $this->assertFalse($credenciais['Bolsa IC Jr']['esgotada']);
        $this->assertSame(['Abelhas nativas', 'Microplásticos no rio'], array_column($credenciais['Bolsa IC Jr']['premiados'], 'titulo'));
    }

    public function test_ranking_por_area_do_mais_premiado_com_empate_dividindo_a_posicao(): void
    {
        $this->adminDoComite();

        $ranking = $this->getJson('/api/v1/admin/premiacao/painel')->json('data.ranking_por_area');

        // Agrárias tem 3 premiações (todas do bioplástico); Biológicas também 3.
        // Empate entre áreas: ordem alfabética.
        $this->assertSame(['Ciências Agrárias', 'Ciências Biológicas'], array_column($ranking, 'area'));
        $this->assertSame(3, $ranking[0]['premiacoes']);

        $agrarias = $ranking[0]['projetos'];
        $this->assertCount(1, $agrarias, 'Projeto sem premiação não entra no ranking.');
        $this->assertSame(['posicao' => 1, 'titulo' => 'Bioplástico de mandioca', 'total' => 3], [
            'posicao' => $agrarias[0]['posicao'], 'titulo' => $agrarias[0]['titulo'], 'total' => $agrarias[0]['total'],
        ]);
        $this->assertEqualsCanonicalizing(['MOSTRATEC 2027', 'FEBRACE 2027', 'Destaque'], array_column($agrarias[0]['premiacoes'], 'nome'));

        $biologicas = $ranking[1]['projetos'];
        $this->assertSame(['Abelhas nativas', 'Microplásticos no rio'], array_column($biologicas, 'titulo'));
        $this->assertSame([1, 2], array_column($biologicas, 'posicao'));
        $this->assertSame('FETEC Jr', $biologicas[0]['categoria']);
    }

    public function test_empate_de_quantidade_divide_a_posicao(): void
    {
        $this->credencial('Extra', null, 6)->projetos()->attach($this->projetos['Microplásticos no rio']->id, ['atribuida_em' => now()]);
        $this->adminDoComite();

        $biologicas = collect($this->getJson('/api/v1/admin/premiacao/painel')->json('data.ranking_por_area'))
            ->firstWhere('area', 'Ciências Biológicas')['projetos'];

        $this->assertSame([1, 1], array_column($biologicas, 'posicao'));
    }

    public function test_a_comissao_especial_le_o_mesmo_painel_e_os_demais_nao(): void
    {
        $this->adminDoComite();
        $doAdmin = $this->getJson('/api/v1/admin/premiacao/painel')->json('data.resumo');

        Sanctum::actingAs(AvaliadorProfile::factory()->create(['comissao_especial' => true])->user);
        $this->assertSame($doAdmin, $this->getJson('/api/v1/avaliador/comite/painel')->assertOk()->json('data.resumo'));

        Sanctum::actingAs(AvaliadorProfile::factory()->create(['comissao_especial' => false])->user);
        $this->getJson('/api/v1/avaliador/comite/painel')->assertForbidden();
    }

    public function test_quem_nao_tem_as_abas_da_premiacao_nao_ve_o_painel(): void
    {
        $admin = User::factory()->admin()->create();
        $escopo = EscopoAdmin::create(['nome' => 'Só projetos', 'abas' => ['projetos']]);
        $admin->escopos()->attach($escopo->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/premiacao/painel')->assertForbidden();
    }
}
