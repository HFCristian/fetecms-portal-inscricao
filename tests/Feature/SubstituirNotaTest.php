<?php

namespace Tests\Feature;

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
 *
 * Sprint 144 — o terceiro caminho passou a **começar pela rubrica**: a
 * avaliação da organização abre primeiro e a nota antiga só sai da
 * classificação no envio. Enquanto ele preenche, o projeto não perde nada; se
 * desistir, o rascunho espera e a nota continua contando.
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

    /** "Eu mesmo avalio no lugar desta nota": abre a rubrica, sem desconsiderar. */
    private function avaliarNoLugar(Avaliacao $a, string $justificativa = 'Avaliou o projeto errado.')
    {
        return $this->postJson("/api/v1/admin/avaliacao/avaliacoes/{$a->id}/avaliar-no-lugar", [
            'justificativa' => $justificativa,
        ]);
    }

    private function concluir(int $avaliacaoId, int $escala = 6)
    {
        return $this->postJson("/api/v1/admin/avaliacao/avaliacoes/{$avaliacaoId}/formulario/concluir", [
            'respostas' => $this->respostas($escala),
            'area_correta' => true,
        ]);
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

        $dados = $this->avaliarNoLugar($avaliacao)->assertOk()->json('data');

        $this->assertSame('admin', $dados['substituicao']['tipo']);
        $this->assertSame($avaliacao->id, $dados['substituicao']['substitui_avaliacao_id']);
        $minhaId = $dados['substituicao']['avaliacao_id'];

        // Já nasce aberta: a substituição existe para ser preenchida agora.
        $minha = Avaliacao::findOrFail($minhaId);
        $this->assertSame(StatusAvaliacao::EmAndamento, $minha->status);
        $this->assertTrue($minha->pela_organizacao);
        $this->assertSame($admin->id, $minha->avaliador_id);
        $this->assertSame($avaliacao->id, $minha->substitui_avaliacao_id);

        // O formulário é o mesmo do avaliador: rubrica, projeto e preenchimento.
        $formulario = $this->getJson("/api/v1/admin/avaliacao/avaliacoes/{$minhaId}/formulario")
            ->assertOk()->json('data');
        $this->assertTrue($formulario['pode_avaliar']);
        $this->assertSame('Secador solar', $formulario['projeto']['titulo']);
        $this->assertNotEmpty($formulario['rubrica']['secoes']);

        $this->concluir($minhaId)->assertOk();

        $minha->refresh();
        $this->assertSame(StatusAvaliacao::Concluida, $minha->status);
        $this->assertEquals(round(Rubrica::nota($this->respostas(6)), 2), round((float) $minha->nota, 2));

        // A nota entra na classificação do projeto, como qualquer outra — e a
        // que ela substituiu saiu no mesmo ato.
        $notas = $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/notas")->assertOk()->json('data');
        $this->assertSame(1, $notas['consideradas']);
        $this->assertSame(1, $notas['desconsideradas']);
        $this->assertEquals(round((float) $minha->nota, 2), $notas['media']);
    }

    /**
     * O coração da Sprint 144: abrir a rubrica não tira nota nenhuma. Enquanto
     * o admin lê o projeto e preenche, a nota antiga continua valendo em tudo.
     */
    public function test_abrir_a_avaliacao_no_lugar_da_nota_nao_desconsidera_nada(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->avaliar($projeto, $this->avaliador('Ana Souza'));

        $dados = $this->avaliarNoLugar($avaliacao)->assertOk()->json('data');

        $this->assertFalse($avaliacao->fresh()->foiDesconsiderada());
        $this->assertSame(1, $dados['consideradas']);
        $this->assertSame(0, $dados['desconsideradas']);
        $this->assertEquals(round((float) $avaliacao->nota, 2), $dados['media']);

        // Nada na trilha ainda: não houve ato que mudasse a classificação.
        $this->assertSame(0, RegistroAtividade::where('tipo', TipoRegistro::NotaDesconsiderada)->count());

        // E a tela sabe que existe uma substituição a caminho, para oferecer a
        // volta ao formulário — sem isso o rascunho ficaria inalcançável.
        $coluna = collect($dados['avaliadores'])->firstWhere('avaliacao_id', $avaliacao->id);
        $this->assertSame($dados['substituicao']['avaliacao_id'], $coluna['substituicao_em_aberto']['avaliacao_id']);
        $this->assertTrue($coluna['substituicao_em_aberto']['minha']);
    }

    /** O envio é que troca uma nota pela outra — com a justificativa escrita lá atrás. */
    public function test_o_envio_desconsidera_a_nota_substituida_com_a_justificativa_guardada(): void
    {
        $admin = $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->avaliar($projeto, $this->avaliador('Ana Souza'));

        $minhaId = $this->avaliarNoLugar($avaliacao, 'Nota incompatível com o trabalho.')
            ->assertOk()->json('data.substituicao.avaliacao_id');

        $this->concluir($minhaId)->assertOk()
            ->assertJsonPath('meta.substituida.avaliador', 'Ana Souza');

        $avaliacao->refresh();
        $this->assertTrue($avaliacao->foiDesconsiderada());
        $this->assertSame($admin->id, $avaliacao->desconsiderada_por);
        $this->assertSame('Nota incompatível com o trabalho.', $avaliacao->desconsiderada_motivo);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::NotaDesconsiderada)->firstOrFail();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame('Nota incompatível com o trabalho.', $registro->detalhes['justificativa']);
        $this->assertSame('Ana Souza', $registro->detalhes['avaliador']);
    }

    /**
     * Desistir no meio não custa nada ao projeto: a nota antiga segue contando
     * e o rascunho espera por ele.
     */
    public function test_desistir_no_meio_deixa_a_nota_antiga_contando_e_o_rascunho_esperando(): void
    {
        $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->avaliar($projeto, $this->avaliador('Ana Souza'));

        $minhaId = $this->avaliarNoLugar($avaliacao)->assertOk()->json('data.substituicao.avaliacao_id');

        $this->postJson("/api/v1/admin/avaliacao/avaliacoes/{$minhaId}/formulario/rascunho", [
            'respostas' => ['titulo_coerente' => true],
        ])->assertOk();

        // O admin fecha a tela e volta depois: a nota continua na classificação.
        $dados = $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/notas")->assertOk()->json('data');
        $this->assertSame(1, $dados['consideradas']);
        $this->assertSame(0, $dados['desconsideradas']);

        $coluna = collect($dados['avaliadores'])->firstWhere('avaliacao_id', $avaliacao->id);
        $this->assertSame($minhaId, $coluna['substituicao_em_aberto']['avaliacao_id']);

        // Retomar é a mesma porta, e o que ele já respondeu está lá.
        $retomada = $this->avaliarNoLugar($avaliacao)->assertOk()->json('data.substituicao.avaliacao_id');
        $this->assertSame($minhaId, $retomada);
        $this->assertTrue(Avaliacao::findOrFail($minhaId)->respostas['titulo_coerente']);
    }

    /** Nota já desconsiderada não tem o que substituir por este caminho. */
    public function test_nao_abre_substituicao_para_nota_ja_desconsiderada(): void
    {
        $this->admin();
        $avaliacao = $this->avaliar($this->projeto(), $this->avaliador('Ana Souza'));

        $this->desconsiderar($avaliacao, ['tipo' => 'nenhuma'])->assertOk();

        $this->avaliarNoLugar($avaliacao)->assertStatus(422)->assertJsonValidationErrors('avaliacao');
    }

    /** O caminho antigo (desconsiderar já designando a si mesmo) não existe mais. */
    public function test_desconsiderar_nao_aceita_mais_o_tipo_admin(): void
    {
        $this->admin();
        $avaliacao = $this->avaliar($this->projeto(), $this->avaliador('Ana Souza'));

        $this->desconsiderar($avaliacao, ['tipo' => 'admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('substituicao.tipo');

        $this->assertFalse($avaliacao->fresh()->foiDesconsiderada());
    }

    /** Conta para o projeto, não para a pessoa. */
    public function test_a_avaliacao_da_organizacao_fica_fora_do_ranking_e_do_certificado(): void
    {
        $admin = $this->admin();
        $projeto = $this->projeto();
        $ana = $this->avaliador('Ana Souza');
        $avaliacao = $this->avaliar($projeto, $ana);

        $minhaId = $this->avaliarNoLugar($avaliacao)->assertOk()
            ->json('data.substituicao.avaliacao_id');

        $this->concluir($minhaId)->assertOk();

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
        $primeiro = $this->admin();
        $projeto = $this->projeto();
        $avaliacao = $this->avaliar($projeto, $this->avaliador('Ana Souza'));

        $minhaId = $this->avaliarNoLugar($avaliacao)->assertOk()
            ->json('data.substituicao.avaliacao_id');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/avaliacao/avaliacoes/{$minhaId}/formulario")->assertForbidden();
        $this->concluir($minhaId)->assertForbidden();

        // Ele vê que a substituição existe — dois admins não abrem o mesmo
        // buraco —, mas a tela não lhe oferece o formulário.
        $dados = $this->postJson("/api/v1/admin/avaliacao/projetos/{$projeto->id}/notas")->assertOk()->json('data');
        $aberto = collect($dados['avaliadores'])->firstWhere('avaliacao_id', $avaliacao->id)['substituicao_em_aberto'];
        $this->assertSame($primeiro->name, $aberto['por']);
        $this->assertFalse($aberto['minha']);
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
