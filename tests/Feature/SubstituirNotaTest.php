<?php

namespace Tests\Feature;

use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Services\AvaliadorService;
use App\Support\Rubrica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 142 — o que entra no lugar da nota desconsiderada.
 *
 * Desconsiderar abre um buraco na cobertura. O admin decide na hora: não repor,
 * designar outro avaliador, ou **avaliar ele mesmo** — com a rubrica oficial,
 * em nome da organização.
 */
class SubstituirNotaTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->area = Area::create(['nome' => 'Ciências Exatas', 'sigla' => 'EXA']);
        Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);
    }

    private function admin(): User
    {
        Sanctum::actingAs($admin = User::factory()->admin()->create());

        return $admin;
    }

    private function projeto(): Projeto
    {
        return Projeto::factory()->submetido()->create([
            'titulo' => 'Secador solar',
            'area_id' => $this->area->id,
            'edicao_id' => Edicao::atual()?->id,
        ]);
    }

    private function avaliador(string $nome): User
    {
        $user = User::factory()->avaliador()->create(['name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'area_id' => $this->area->id]);

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function respostas(int $escala = 8): array
    {
        return Rubrica::normalizar(
            collect(Rubrica::perguntas())
                ->mapWithKeys(fn (array $p) => [
                    $p['chave'] => $p['tipo'] === Rubrica::TIPO_SIM_NAO ? true : $escala,
                ])
                ->all()
        );
    }

    private function avaliar(Projeto $projeto, User $avaliador, int $escala = 10): Avaliacao
    {
        $respostas = $this->respostas($escala);

        return Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Concluida,
            'respostas' => $respostas,
            'nota' => Rubrica::nota($respostas),
            'concluida_em' => now(),
        ]);
    }

    private function desconsiderar(Avaliacao $a, ?array $substituicao)
    {
        return $this->postJson("/api/v1/admin/avaliacao/avaliacoes/{$a->id}/desconsiderar", array_filter([
            'justificativa' => 'Avaliou o projeto errado.',
            'substituicao' => $substituicao,
        ]));
    }

    public function test_sem_substituicao_o_projeto_fica_com_um_parecer_a_menos(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->avaliar($projeto, $this->avaliador('Ana Souza'));

        $dados = $this->desconsiderar($avaliacao, ['tipo' => 'nenhuma'])->assertOk()->json('data');

        $this->assertNull($dados['substituicao']);
        $this->assertSame(1, Avaliacao::where('projeto_id', $projeto->id)->count());
    }

    public function test_designa_outro_avaliador_no_lugar(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->avaliar($projeto, $this->avaliador('Ana Souza'));
        $bruno = $this->avaliador('Bruno Lima');

        $dados = $this->desconsiderar($avaliacao, ['tipo' => 'avaliador', 'avaliador_id' => $bruno->id])
            ->assertOk()->json('data');

        $this->assertSame('avaliador', $dados['substituicao']['tipo']);
        $this->assertSame(1, $dados['substituicao']['designadas']);

        $nova = Avaliacao::where('projeto_id', $projeto->id)->where('avaliador_id', $bruno->id)->firstOrFail();
        $this->assertSame(StatusAvaliacao::Designada, $nova->status);
        // Como toda designação manual, ela não é devolvida por rotina automática.
        $this->assertTrue($nova->designacao_manual);
    }

    /** Quem teve a nota descartada não recebe o projeto de volta. */
    public function test_o_dono_da_nota_descartada_nao_e_designado_de_novo(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $ana = $this->avaliador('Ana Souza');
        $avaliacao = $this->avaliar($projeto, $ana);

        $dados = $this->desconsiderar($avaliacao, ['tipo' => 'avaliador', 'avaliador_id' => $ana->id])
            ->assertOk()->json('data');

        $this->assertSame(0, $dados['substituicao']['designadas']);
        $this->assertNotEmpty($dados['substituicao']['ignoradas']);
        $this->assertSame(1, Avaliacao::where('projeto_id', $projeto->id)->count());
    }

    public function test_o_admin_avalia_na_hora_e_a_nota_dele_entra_na_media(): void
    {
        $admin = $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->avaliar($projeto, $this->avaliador('Ana Souza'));

        $dados = $this->desconsiderar($avaliacao, ['tipo' => 'admin'])->assertOk()->json('data');

        $this->assertSame('admin', $dados['substituicao']['tipo']);
        $minhaId = $dados['substituicao']['avaliacao_id'];

        // Já nasce aberta: a substituição existe para ser preenchida agora.
        $minha = Avaliacao::findOrFail($minhaId);
        $this->assertSame(StatusAvaliacao::EmAndamento, $minha->status);
        $this->assertTrue($minha->pela_organizacao);
        $this->assertSame($admin->id, $minha->avaliador_id);

        // O formulário é o mesmo do avaliador: rubrica, projeto e preenchimento.
        $formulario = $this->getJson("/api/v1/admin/avaliacao/avaliacoes/{$minhaId}/formulario")
            ->assertOk()->json('data');
        $this->assertTrue($formulario['pode_avaliar']);
        $this->assertSame('Secador solar', $formulario['projeto']['titulo']);
        $this->assertNotEmpty($formulario['rubrica']['secoes']);

        $this->postJson("/api/v1/admin/avaliacao/avaliacoes/{$minhaId}/formulario/concluir", [
            'respostas' => $this->respostas(6),
            'area_correta' => true,
        ])->assertOk();

        $minha->refresh();
        $this->assertSame(StatusAvaliacao::Concluida, $minha->status);
        $this->assertEquals(round(Rubrica::nota($this->respostas(6)), 2), round((float) $minha->nota, 2));

        // A nota entra na classificação do projeto, como qualquer outra.
        $notas = $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/notas")->assertOk()->json('data');
        $this->assertSame(1, $notas['consideradas']);
        $this->assertEquals(round((float) $minha->nota, 2), $notas['media']);
    }

    /** Conta para o projeto, não para a pessoa. */
    public function test_a_avaliacao_da_organizacao_fica_fora_do_ranking_e_do_certificado(): void
    {
        $admin = $this->admin();
        $projeto = $this->projeto();
        $ana = $this->avaliador('Ana Souza');
        $avaliacao = $this->avaliar($projeto, $ana);

        $minhaId = $this->desconsiderar($avaliacao, ['tipo' => 'admin'])->assertOk()
            ->json('data.substituicao.avaliacao_id');

        $this->postJson("/api/v1/admin/avaliacao/avaliacoes/{$minhaId}/formulario/concluir", [
            'respostas' => $this->respostas(6),
            'area_correta' => true,
        ])->assertOk();

        $doAdmin = app(AvaliadorService::class)->estatisticas($admin->fresh());
        $this->assertSame(0, $doAdmin['avaliacoes_concluidas']);
        $this->assertNull($doAdmin['posicao']);

        // E não mexe no ranking de quem avalia de verdade.
        $daAna = app(AvaliadorService::class)->estatisticas($ana);
        $this->assertSame(1, $daAna['avaliacoes_concluidas']);
        $this->assertSame(1, $daAna['total_no_ranking']);
    }

    public function test_um_admin_nao_preenche_a_avaliacao_aberta_por_outro(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->avaliar($projeto, $this->avaliador('Ana Souza'));

        $minhaId = $this->desconsiderar($avaliacao, ['tipo' => 'admin'])->assertOk()
            ->json('data.substituicao.avaliacao_id');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/avaliacao/avaliacoes/{$minhaId}/formulario")->assertForbidden();
        $this->postJson("/api/v1/admin/avaliacao/avaliacoes/{$minhaId}/formulario/concluir", [
            'respostas' => $this->respostas(6),
            'area_correta' => true,
        ])->assertForbidden();
    }

    /** A rota da organização nunca alcança a avaliação de um avaliador. */
    public function test_a_rota_da_organizacao_nao_edita_avaliacao_de_avaliador(): void
    {
        $this->admin();
        $avaliacao = $this->avaliar($this->projeto(), $this->avaliador('Ana Souza'));

        $this->getJson("/api/v1/admin/avaliacao/avaliacoes/{$avaliacao->id}/formulario")->assertForbidden();
    }

    public function test_avaliador_inativo_nao_pode_ser_escolhido_como_substituto(): void
    {
        $this->admin();
        $avaliacao = $this->avaliar($this->projeto(), $this->avaliador('Ana Souza'));
        $inativo = $this->avaliador('Bruno Lima');
        $inativo->update(['is_active' => false]);

        $this->desconsiderar($avaliacao, ['tipo' => 'avaliador', 'avaliador_id' => $inativo->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('substituicao.avaliador_id');

        // A nota não é desconsiderada se a substituição é inválida.
        $this->assertFalse($avaliacao->fresh()->foiDesconsiderada());
    }
}
