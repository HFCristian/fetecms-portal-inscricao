<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\TipoRegistro;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\Cidade;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\FaixaEstandes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 119 — Mapa do Evento → **Estandes dos Projetos**.
 *
 * A faixa de cada categoria ("1-4, 7-9"), a distribuição turno a turno, o que
 * acontece quando a faixa é pequena demais e a troca de lugar entre dois
 * projetos.
 */
class EstandesProjetosTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Cidade $cidade;

    protected function setUp(): void
    {
        parent::setUp();

        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $this->cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $estado->id, 'capital' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'turnos_config' => ['capacidade' => ['A' => 10, 'B' => 10], 'regras' => []],
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Um projeto já com turno definido — o insumo desta tela. */
    private function comTurno(string $titulo, Categoria $categoria, Turno $turno): Projeto
    {
        $instituicao = Instituicao::create(['nome' => 'Escola '.$titulo, 'cidade_id' => $this->cidade->id]);

        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'categoria' => $categoria,
            'area_id' => $this->area->id,
            'instituicao_id' => $instituicao->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);

        TurnoApresentacao::create([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'turno' => $turno->value,
            'regra' => 'equilibrio',
        ]);

        return $projeto;
    }

    private function regras(array $por = []): array
    {
        $regras = [];

        foreach (Categoria::cases() as $c) {
            $regras[$c->value] = $por[$c->value] ?? ['ativa' => false, 'faixa' => ''];
        }

        return ['regras' => $regras];
    }

    private function numeroDe(Projeto $projeto): int
    {
        return EstandeProjeto::where('projeto_id', $projeto->id)->firstOrFail()->numero;
    }

    // --- A leitura da faixa ----------------------------------------------

    public function test_faixa_entende_numeros_avulsos_e_intervalos(): void
    {
        $this->assertSame([1, 2, 3, 4, 7, 8, 9], FaixaEstandes::expandir('1-4, 7, 8, 9'));
        // Repetido não duplica, invertido é lido do jeito que se quis dizer.
        $this->assertSame([5, 6, 7], FaixaEstandes::expandir('7-5, 5'));
        $this->assertSame([], FaixaEstandes::expandir('  '));
        // O caminho de volta agrupa os consecutivos.
        $this->assertSame('1-4, 7-9', FaixaEstandes::comprimir([1, 2, 3, 4, 7, 8, 9]));
    }

    // --- As travas -------------------------------------------------------

    public function test_sem_turnos_gerados_a_distribuicao_e_recusada(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras())
            ->assertStatus(422)
            ->assertJsonValidationErrors('turnos');
    }

    public function test_o_mesmo_estande_em_duas_categorias_e_recusado(): void
    {
        $this->admin();
        $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);

        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras([
            'fetecms' => ['ativa' => true, 'faixa' => '1-10'],
            'fetec_jr' => ['ativa' => true, 'faixa' => '8-12'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('regras');
    }

    public function test_regra_ligada_sem_faixa_e_recusada(): void
    {
        $this->admin();
        $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);

        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras([
            'fetecms' => ['ativa' => true, 'faixa' => ''],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('regras');
    }

    // --- A distribuição ---------------------------------------------------

    public function test_cada_categoria_fica_dentro_da_propria_faixa(): void
    {
        $this->admin();
        $fet = $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);
        $jr = $this->comTurno('Beta', Categoria::FetecJr, Turno::A);

        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras([
            'fetecms' => ['ativa' => true, 'faixa' => '1-3'],
            'fetec_jr' => ['ativa' => true, 'faixa' => '8-10'],
        ]))->assertOk();

        $this->assertSame(1, $this->numeroDe($fet));
        $this->assertSame(8, $this->numeroDe($jr));
    }

    /** Categoria sem regra ocupa o que nenhuma faixa reservou. */
    public function test_categoria_sem_regra_ocupa_os_numeros_livres(): void
    {
        $this->admin();
        $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);
        $semRegra = $this->comTurno('Beta', Categoria::FetecJr, Turno::A);

        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras([
            'fetecms' => ['ativa' => true, 'faixa' => '1-3'],
        ]))->assertOk();

        // 1, 2 e 3 são da FETECMS; o primeiro livre é o 4.
        $this->assertSame(4, $this->numeroDe($semRegra));
    }

    public function test_os_dois_turnos_usam_os_mesmos_numeros(): void
    {
        $this->admin();
        $manha = $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);
        $tarde = $this->comTurno('Beta', Categoria::Fetecms, Turno::B);

        $dados = $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras([
            'fetecms' => ['ativa' => true, 'faixa' => '1-5'],
        ]))->assertOk()->json('data');

        // O estande é reaproveitado: o mesmo 001 de manhã e à tarde.
        $this->assertSame(1, $this->numeroDe($manha));
        $this->assertSame(1, $this->numeroDe($tarde));
        $this->assertSame(1, $dados['turnos']['A']['total']);
        $this->assertSame(1, $dados['turnos']['B']['total']);
        $this->assertSame('001', $dados['turnos']['A']['estandes'][0]['estande']);
    }

    public function test_faixa_pequena_demais_manda_o_excedente_para_os_livres_e_avisa(): void
    {
        $this->admin();
        $primeiro = $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);
        $segundo = $this->comTurno('Beta', Categoria::Fetecms, Turno::A);

        $resposta = $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras([
            'fetecms' => ['ativa' => true, 'faixa' => '5'],
        ]))->assertOk();

        $avisos = $resposta->json('meta.avisos');

        $this->assertSame(5, $this->numeroDe($primeiro));
        $this->assertSame(1, $this->numeroDe($segundo), 'O excedente cai no primeiro número livre.');
        $this->assertCount(1, $avisos);
        $this->assertSame(1, $avisos[0]['sobraram']);
        $this->assertStringContainsString('FETECMS', $avisos[0]['categoria']);
    }

    public function test_gerar_de_novo_substitui_a_distribuicao(): void
    {
        $this->admin();
        $projeto = $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);

        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras([
            'fetecms' => ['ativa' => true, 'faixa' => '7'],
        ]))->assertOk();
        $this->assertSame(7, $this->numeroDe($projeto));

        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras([
            'fetecms' => ['ativa' => true, 'faixa' => '9'],
        ]))->assertOk();

        $this->assertSame(9, $this->numeroDe($projeto));
        $this->assertSame(1, EstandeProjeto::count());
        $this->assertSame(2, RegistroAtividade::where('tipo', TipoRegistro::EstandesGerados)->count());
    }

    // --- A troca manual ---------------------------------------------------

    public function test_mover_para_numero_livre_registra_a_troca(): void
    {
        $admin = $this->admin();
        $projeto = $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);
        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras())->assertOk();

        $this->patchJson('/api/v1/admin/mapa/estandes/mover', [
            'projeto_id' => $projeto->id,
            'numero' => 7,
        ])->assertOk();

        $alocacao = EstandeProjeto::where('projeto_id', $projeto->id)->firstOrFail();
        $this->assertSame(7, $alocacao->numero);
        $this->assertTrue($alocacao->manual);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::EstandeProjetoMovido)->firstOrFail();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame('001', $registro->detalhes['de']);
        $this->assertSame('007', $registro->detalhes['para']);
    }

    /** Mandar para um número ocupado é trocar os dois de lugar. */
    public function test_mover_para_numero_ocupado_troca_os_dois(): void
    {
        $this->admin();
        $primeiro = $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);
        $segundo = $this->comTurno('Beta', Categoria::Fetecms, Turno::A);
        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras())->assertOk();

        $this->assertSame(1, $this->numeroDe($primeiro));
        $this->assertSame(2, $this->numeroDe($segundo));

        $this->patchJson('/api/v1/admin/mapa/estandes/mover', [
            'projeto_id' => $primeiro->id,
            'numero' => 2,
        ])->assertOk();

        $this->assertSame(2, $this->numeroDe($primeiro));
        $this->assertSame(1, $this->numeroDe($segundo), 'Quem estava no destino vai para o lugar de quem saiu.');
        // A trilha conta os dois lados da troca.
        $this->assertSame(2, RegistroAtividade::where('tipo', TipoRegistro::EstandeProjetoMovido)->count());
    }

    /** Projetos de turnos diferentes dividem o número sem conflito. */
    public function test_mover_nao_esbarra_no_projeto_do_outro_turno(): void
    {
        $this->admin();
        $manha = $this->comTurno('Alfa', Categoria::Fetecms, Turno::A);
        $tarde = $this->comTurno('Beta', Categoria::Fetecms, Turno::B);
        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras())->assertOk();

        $this->patchJson('/api/v1/admin/mapa/estandes/mover', [
            'projeto_id' => $manha->id,
            'numero' => 5,
        ])->assertOk();

        $this->assertSame(5, $this->numeroDe($manha));
        $this->assertSame(1, $this->numeroDe($tarde));
    }

    // --- Exportação -------------------------------------------------------

    public function test_exporta_em_txt_csv_e_pdf(): void
    {
        $this->admin();
        $this->comTurno('Horta na escola', Categoria::Fetecms, Turno::A);
        $this->postJson('/api/v1/admin/mapa/estandes/gerar', $this->regras())->assertOk();

        $txt = $this->get('/api/v1/admin/mapa/estandes/exportar/txt')->assertOk();
        $this->assertStringContainsString('001 - Horta na escola', $txt->getContent());

        $csv = $this->get('/api/v1/admin/mapa/estandes/exportar/csv')->assertOk();
        $this->assertStringContainsString('Campo Grande', $csv->getContent());

        $pdf = $this->get('/api/v1/admin/mapa/estandes/exportar/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }
}
