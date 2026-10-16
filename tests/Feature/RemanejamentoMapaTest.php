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
use App\Models\ListaFinal;
use App\Models\MapaLayout;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\TurnoApresentacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprints 212–213 — Mapa do Evento: **remanejar sem refazer**.
 *
 * O finalista que entrou na lista final depois da geração ganha lugar sem
 * regerar nada; o estande ocupado oferece três saídas (trocar, empurrar e
 * mandar o atual para o fim); e quem saiu da lista sai do mapa.
 */
class RemanejamentoMapaTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Cidade $cidade;

    private Edicao $edicao;

    private ListaFinal $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $estado = Estado::create(['nome' => 'Mato Grosso do Sul', 'uf' => 'MS']);
        $this->cidade = Cidade::create(['nome' => 'Campo Grande', 'estado_id' => $estado->id, 'capital' => true]);
        $this->area = Area::create(['nome' => 'Ciências Agrárias', 'sigla' => 'AGR']);

        $this->edicao = Edicao::create([
            'nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true,
            'turnos_config' => ['capacidade' => ['A' => 10, 'B' => 10], 'regras' => []],
            'turnos_gerados_em' => now(),
            'estandes_gerados_em' => now(),
        ]);

        // Uma planta pequena: 1 a 6. "Ao lado" e "fim" andam nela.
        MapaLayout::create([
            'edicao_id' => $this->edicao->id,
            'versao' => 1,
            'nome' => 'Teste',
            'vigente' => true,
            'dados' => ['nome' => 'Teste', 'estandes' => array_map(
                fn (int $n) => ['numero' => $n, 'x' => $n, 'y' => 0],
                range(1, 6),
            )],
        ]);

        $this->lista = ListaFinal::create([
            'edicao_id' => $this->edicao->id,
            'nome' => 'Lista oficial',
            'vigente' => true,
            'demo' => false,
            'versao' => 1,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function finalista(string $titulo, Categoria $categoria = Categoria::Fetecms): Projeto
    {
        $instituicao = Instituicao::create(['nome' => 'Escola '.$titulo, 'cidade_id' => $this->cidade->id]);

        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo,
            'categoria' => $categoria,
            'area_id' => $this->area->id,
            'instituicao_id' => $instituicao->id,
            'edicao_id' => $this->edicao->id,
        ]);

        $this->lista->projetos()->attach($projeto->id);

        return $projeto;
    }

    /** Um finalista já com lugar: turno e estande. */
    private function noLugar(string $titulo, Turno $turno, ?int $numero): Projeto
    {
        $projeto = $this->finalista($titulo);

        TurnoApresentacao::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id,
            'turno' => $turno->value, 'regra' => 'equilibrio',
        ]);

        if ($numero !== null) {
            EstandeProjeto::create([
                'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id,
                'turno' => $turno->value, 'numero' => $numero,
            ]);
        }

        return $projeto;
    }

    /** @return array{0: ?string, 1: ?int} */
    private function lugarDe(Projeto $projeto): array
    {
        return [
            TurnoApresentacao::where('projeto_id', $projeto->id)->first()?->turno?->value,
            EstandeProjeto::where('projeto_id', $projeto->id)->first()?->numero,
        ];
    }

    private function alocar(Projeto $projeto, string $turno, ?int $numero, ?string $modo = null)
    {
        return $this->postJson('/api/v1/admin/mapa/remanejamento/alocar', array_filter([
            'projeto_id' => $projeto->id,
            'turno' => $turno,
            'numero' => $numero,
            'modo' => $modo,
        ], fn ($v) => $v !== null));
    }

    // --- O que está fora do lugar ----------------------------------------

    public function test_painel_aponta_finalista_sem_turno_sem_estande_e_fora_da_lista(): void
    {
        $this->admin();
        $novo = $this->finalista('Chegou depois');
        $semEstande = $this->noLugar('Sem estande', Turno::A, null);
        $saiu = $this->noLugar('Saiu da lista', Turno::B, 3);
        $this->lista->projetos()->detach($saiu->id);

        $dados = $this->getJson('/api/v1/admin/mapa/remanejamento')->assertOk()->json('data');

        $this->assertSame([$novo->id], array_column($dados['sem_turno'], 'projeto_id'));
        $this->assertSame([$semEstande->id], array_column($dados['sem_estande'], 'projeto_id'));
        $this->assertSame([$saiu->id], array_column($dados['fora_da_lista'], 'projeto_id'));
        $this->assertSame(range(1, 6), $dados['numeros']);
        $this->assertTrue($dados['estandes_gerados']);
    }

    // --- Incluir quem chegou depois --------------------------------------

    public function test_inclui_finalista_novo_num_estande_vazio_e_registra(): void
    {
        $admin = $this->admin();
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'B', 4)->assertOk()
            ->assertJsonPath('data.movimentos.0.para', 'Turno B (vespertino) · Estande 004');

        $this->assertSame(['B', 4], $this->lugarDe($novo));
        $this->assertSame('manual', TurnoApresentacao::where('projeto_id', $novo->id)->first()->regra);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::MapaProjetoIncluido)->firstOrFail();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame('Turno B (vespertino) · Estande 004', $registro->detalhes['para']);
    }

    public function test_sem_estandes_distribuidos_basta_o_turno(): void
    {
        $this->admin();
        $this->edicao->update(['estandes_gerados_em' => null]);
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', null)->assertOk();

        $this->assertSame(['A', null], $this->lugarDe($novo));
    }

    public function test_com_estandes_distribuidos_o_estande_e_obrigatorio(): void
    {
        $this->admin();
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', null)->assertStatus(422)->assertJsonValidationErrors('numero');
    }

    public function test_estande_fora_da_planta_e_recusado(): void
    {
        $this->admin();
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 99)->assertStatus(422)->assertJsonValidationErrors('numero');
    }

    public function test_projeto_fora_da_lista_final_nao_entra(): void
    {
        $this->admin();
        $projeto = $this->finalista('Fora');
        $this->lista->projetos()->detach($projeto->id);

        $this->alocar($projeto, 'A', 1)->assertStatus(422)->assertJsonValidationErrors('projeto_id');
    }

    public function test_turno_lotado_recusa(): void
    {
        $this->admin();
        $this->edicao->update(['turnos_config' => ['capacidade' => ['A' => 1, 'B' => 1], 'regras' => []]]);
        $this->noLugar('Já está', Turno::A, 1);
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 2)->assertStatus(422)->assertJsonValidationErrors('turno');
    }

    // --- Estande ocupado: as três saídas ---------------------------------

    public function test_estande_ocupado_sem_escolha_pede_o_modo(): void
    {
        $this->admin();
        $this->noLugar('Dono', Turno::A, 2);
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 2)->assertStatus(422)->assertJsonValidationErrors('modo');
    }

    public function test_trocar_entre_turnos_troca_estande_e_turno_dos_dois(): void
    {
        $this->admin();
        $x = $this->noLugar('Xis', Turno::A, 1);
        $y = $this->noLugar('Ípsilon', Turno::B, 5);

        $this->alocar($x, 'B', 5, 'trocar')->assertOk();

        $this->assertSame(['B', 5], $this->lugarDe($x));
        $this->assertSame(['A', 1], $this->lugarDe($y));
        $this->assertSame(2, RegistroAtividade::where('tipo', TipoRegistro::TurnosProjetoMovido)->count());
    }

    public function test_trocar_dentro_do_turno(): void
    {
        $this->admin();
        $x = $this->noLugar('Xis', Turno::A, 1);
        $y = $this->noLugar('Ípsilon', Turno::A, 3);

        $this->alocar($x, 'A', 3, 'trocar')->assertOk();

        $this->assertSame(['A', 3], $this->lugarDe($x));
        $this->assertSame(['A', 1], $this->lugarDe($y));
        $this->assertSame(2, RegistroAtividade::where('tipo', TipoRegistro::EstandeProjetoMovido)->count());
    }

    public function test_quem_nao_tem_lugar_nao_troca(): void
    {
        $this->admin();
        $this->noLugar('Dono', Turno::A, 2);
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 2, 'trocar')->assertStatus(422)->assertJsonValidationErrors('modo');
    }

    public function test_empurrar_anda_um_estande_ate_o_primeiro_vazio(): void
    {
        $this->admin();
        $um = $this->noLugar('Um', Turno::A, 1);
        $dois = $this->noLugar('Dois', Turno::A, 2);
        $tres = $this->noLugar('Três', Turno::A, 3);
        $cinco = $this->noLugar('Cinco', Turno::A, 5);
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 2, 'empurrar')->assertOk();

        $this->assertSame(['A', 2], $this->lugarDe($novo));
        $this->assertSame(['A', 1], $this->lugarDe($um), 'Antes do ponto, nada anda.');
        $this->assertSame(['A', 3], $this->lugarDe($dois));
        $this->assertSame(['A', 4], $this->lugarDe($tres));
        $this->assertSame(['A', 5], $this->lugarDe($cinco), 'O empurrão para no primeiro vazio.');
    }

    public function test_empurrar_sem_vazio_ate_o_fim_e_recusado(): void
    {
        $this->admin();
        foreach ([4, 5, 6] as $n) {
            $this->noLugar('Projeto '.$n, Turno::A, $n);
        }
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 4, 'empurrar')->assertStatus(422)->assertJsonValidationErrors('modo');
        $this->assertSame([null, null], $this->lugarDe($novo), 'Recusado, nada muda.');
    }

    public function test_final_manda_o_atual_para_depois_do_ultimo_ocupado(): void
    {
        $this->admin();
        $this->noLugar('Um', Turno::A, 1);
        $dono = $this->noLugar('Dois', Turno::A, 2);
        $this->noLugar('Três', Turno::A, 3);
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 2, 'final')->assertOk();

        $this->assertSame(['A', 2], $this->lugarDe($novo));
        $this->assertSame(['A', 4], $this->lugarDe($dono));
    }

    public function test_final_com_o_ultimo_estande_ocupado_usa_o_primeiro_livre(): void
    {
        $this->admin();
        $dono = $this->noLugar('Dois', Turno::A, 2);
        $this->noLugar('Seis', Turno::A, 6);
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 2, 'final')->assertOk();

        $this->assertSame(['A', 1], $this->lugarDe($dono));
    }

    public function test_mover_de_turno_libera_o_estande_antigo(): void
    {
        $this->admin();
        $x = $this->noLugar('Xis', Turno::A, 1);

        $this->alocar($x, 'B', 1)->assertOk();

        $this->assertSame(['B', 1], $this->lugarDe($x));
        $this->assertSame(0, EstandeProjeto::where('turno', 'A')->count());
    }

    public function test_aviso_quando_o_projeto_sai_da_faixa_da_categoria(): void
    {
        $this->admin();
        $this->edicao->update(['estandes_config' => ['regras' => [
            Categoria::FetecJr->value => ['ativa' => true, 'faixa' => '1-2'],
        ]]]);
        $novo = $this->finalista('Chegou depois');

        $this->alocar($novo, 'A', 1)->assertOk()
            ->assertJsonCount(1, 'data.avisos');
    }

    // --- Sair do mapa ----------------------------------------------------

    public function test_retirar_tira_turno_e_estande_e_registra(): void
    {
        $this->admin();
        $saiu = $this->noLugar('Saiu', Turno::A, 2);
        $this->lista->projetos()->detach($saiu->id);

        $this->postJson('/api/v1/admin/mapa/remanejamento/retirar', ['projeto_id' => $saiu->id])->assertOk();

        $this->assertSame([null, null], $this->lugarDe($saiu));
        $this->assertSame(1, RegistroAtividade::where('tipo', TipoRegistro::MapaProjetoRetirado)->count());
    }

    // --- A troca antiga, da tela de turnos ---------------------------------

    public function test_mover_turno_antigo_pede_estande_quando_ja_ha_estandes(): void
    {
        $this->admin();
        $x = $this->noLugar('Xis', Turno::A, 1);

        $this->patchJson('/api/v1/admin/mapa/turnos/mover', ['projeto_id' => $x->id, 'turno' => 'B'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero');
    }

    public function test_lista_de_turnos_mostra_o_estande_de_cada_projeto(): void
    {
        $this->admin();
        $this->noLugar('Xis', Turno::A, 3);

        $this->getJson('/api/v1/admin/mapa/turnos')->assertOk()
            ->assertJsonPath('data.lista.turnos.A.projetos.0.numero', 3);
    }
}
