<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\EscopoAdmin;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use App\Services\RegistroAtividadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 249 — transferir a avaliação presencial lançada no projeto errado:
 * o avaliador se confundiu de estande.
 *
 * O que estes testes seguram:
 * - a avaliação muda de projeto com tudo o que foi respondido, e passa para o
 *   turno do estande de destino;
 * - a vaga é refeita no destino; sem vaga, entra acima do teto, com aviso;
 * - o mesmo avaliador não fica com duas avaliações do projeto — a designação
 *   que ele não abriu dá lugar à transferida;
 * - justificativa obrigatória, registro em Registros → Notas;
 * - quem abre a aba do Mapa do Evento também transfere.
 */
class TransferenciaAvaliacaoPresencialTest extends TestCase
{
    use RefreshDatabase;

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
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function finalista(string $titulo, int $estande, string $turno = 'A', bool $comEstande = true): Projeto
    {
        $projeto = Projeto::factory()->submetido()->create([
            'titulo' => $titulo, 'edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id,
        ]);
        $this->lista->projetos()->attach($projeto->id);

        if ($comEstande) {
            EstandeProjeto::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'turno' => $turno, 'numero' => $estande]);
        }

        return $projeto;
    }

    private function avaliador(string $nome, bool $comissao = false): User
    {
        $avaliador = User::factory()->create(['role' => Role::Avaliador->value, 'name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $avaliador->id, 'presencial' => true, 'comissao_especial' => $comissao]);

        return $avaliador;
    }

    private function avaliacao(Projeto $projeto, User $avaliador, string $vaga = 'comum', string $status = 'concluida'): AvaliacaoPresencial
    {
        return AvaliacaoPresencial::create([
            'edicao_id' => $this->edicao->id, 'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::from($status), 'nota' => $status === 'concluida' ? 7.25 : null,
            'vaga' => $vaga, 'dia' => '2026-10-20', 'turno' => 'A',
            'iniciada_em' => now()->subHour(), 'concluida_em' => $status === 'concluida' ? now() : null,
            'respostas' => ['2.1' => 'S'], 'comentario' => 'Muito bom.',
        ]);
    }

    private function transferir(AvaliacaoPresencial $avaliacao, Projeto $destino, string $justificativa = 'Avaliou o estande do lado.')
    {
        return $this->postJson("/api/v1/admin/presencial/ranking/avaliacoes/{$avaliacao->id}/transferir", [
            'projeto_id' => $destino->id, 'justificativa' => $justificativa,
        ]);
    }

    public function test_transfere_com_as_respostas_e_passa_para_o_turno_do_destino(): void
    {
        $errado = $this->finalista('Abelhas', 41);
        $certo = $this->finalista('Horta vertical', 42, 'B');
        $ana = $this->avaliador('Ana Souza', comissao: true);
        $avaliacao = $this->avaliacao($errado, $ana, 'comite');

        $this->transferir($avaliacao, $certo, '')->assertStatus(422)->assertJsonValidationErrors('justificativa');

        $this->transferir($avaliacao, $certo)
            ->assertOk()
            // A tela devolvida é a do projeto de origem, já sem a avaliação.
            ->assertJsonPath('data.projeto.id', $errado->id)
            ->assertJsonCount(0, 'data.avaliacoes')
            ->assertJsonPath('meta.aviso', null)
            ->assertJsonPath('meta.destino.projeto_id', $certo->id);

        $avaliacao->refresh();
        $this->assertSame($certo->id, $avaliacao->projeto_id);
        $this->assertSame('B', $avaliacao->turno->value);
        $this->assertSame('2026-10-20', $avaliacao->dia->toDateString());
        $this->assertSame('comite', $avaliacao->vaga->value);
        $this->assertSame(7.25, $avaliacao->nota);
        $this->assertSame(['2.1' => 'S'], $avaliacao->respostas);
        $this->assertSame(StatusAvaliacao::Concluida, $avaliacao->status);

        $registro = RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoPresencialTransferida)->sole();
        $this->assertSame($errado->id, $registro->projeto_id);
        $this->assertSame('Ana Souza', $registro->detalhes['avaliador']);
        $this->assertSame('7,250', $registro->detalhes['nota']);
        // Com o código da lista final, que sai da categoria sorteada pela factory.
        $this->assertMatchesRegularExpression('/^estande 042 · Turno B · [A-Z]+\.AGR-\d{3} · Horta vertical$/u', $registro->detalhes['destino']);
        $this->assertSame('Avaliou o estande do lado.', $registro->detalhes['justificativa']);
        $this->assertStringContainsString('para estande 042', app(RegistroAtividadeService::class)->descreverDetalhes($registro));

        // No destino, ela aparece no Ver avaliações.
        $this->postJson("/api/v1/admin/presencial/ranking/projetos/{$certo->id}/avaliacoes")
            ->assertJsonPath('data.avaliacoes.0.avaliacao_id', $avaliacao->id);
    }

    public function test_a_vaga_e_refeita_no_destino_e_sem_vaga_entra_acima_do_teto(): void
    {
        $errado = $this->finalista('Abelhas', 41);
        $certo = $this->finalista('Horta vertical', 42);
        $this->avaliacao($certo, $this->avaliador('Comitê já lá', comissao: true), 'comite');

        // O da comissão encontra a vaga dela tomada: vai para uma comum.
        $bruno = $this->avaliacao($errado, $this->avaliador('Bruno', comissao: true), 'comite');
        $this->transferir($bruno, $certo)->assertOk()->assertJsonPath('meta.aviso', null);
        $this->assertSame('comum', $bruno->refresh()->vaga->value);

        // Agora o destino tem 1 comum + 1 comitê: uma comum ainda cabe.
        $caio = $this->avaliacao($errado, $this->avaliador('Caio'));
        $this->transferir($caio, $certo)->assertOk()->assertJsonPath('meta.aviso', null);

        // Cheio: a próxima entra acima do teto, com aviso.
        $dora = $this->avaliacao($errado, $this->avaliador('Dora'));
        $resposta = $this->transferir($dora, $certo)->assertOk();
        $this->assertStringContainsString('acima do teto', $resposta->json('meta.aviso'));
        $this->assertSame($certo->id, $dora->refresh()->projeto_id);
        $this->assertSame('comum', $dora->vaga->value);
        $this->assertTrue(RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoPresencialTransferida)->latest('id')->first()->detalhes['acima_do_teto']);
    }

    public function test_o_mesmo_avaliador_nao_fica_com_duas_avaliacoes_do_projeto(): void
    {
        $errado = $this->finalista('Abelhas', 41);
        $certo = $this->finalista('Horta vertical', 42);
        $outro = $this->finalista('Drone', 43);
        $ana = $this->avaliador('Ana Souza');
        $avaliacao = $this->avaliacao($errado, $ana);

        // Já enviou a do destino: recusa.
        $this->avaliacao($certo, $ana);
        $this->transferir($avaliacao, $certo)
            ->assertStatus(422)
            ->assertJsonPath('errors.projeto_id.0', 'Ana Souza já enviou uma avaliação deste projeto. Não dá para transferir esta avaliação para lá.');
        $this->assertSame($errado->id, $avaliacao->refresh()->projeto_id);

        // Só designada no destino: a designação dá lugar à transferida.
        $designada = $this->avaliacao($outro, $ana, status: 'designada');
        $this->transferir($avaliacao, $outro)->assertOk();
        $this->assertModelMissing($designada);
        $this->assertSame($outro->id, $avaliacao->refresh()->projeto_id);
    }

    public function test_recusa_destino_invalido_e_a_so_designada(): void
    {
        $errado = $this->finalista('Abelhas', 41);
        $semEstande = $this->finalista('Sem lugar', 0, comEstande: false);
        $foraDaLista = Projeto::factory()->submetido()->create(['edicao_id' => $this->edicao->id, 'area_id' => $this->agr->id]);
        $ana = $this->avaliador('Ana Souza');
        $avaliacao = $this->avaliacao($errado, $ana);

        $this->transferir($avaliacao, $errado)->assertStatus(422)->assertJsonValidationErrors('projeto_id');
        $this->transferir($avaliacao, $semEstande)->assertStatus(422)
            ->assertJsonPath('errors.projeto_id.0', 'O projeto de destino ainda não tem estande no Mapa do Evento.');
        $this->transferir($avaliacao, $foraDaLista)->assertStatus(422)
            ->assertJsonPath('errors.projeto_id.0', 'O projeto de destino não está na lista final que vale para o evento.');

        $designada = $this->avaliacao($this->finalista('Horta', 42), $this->avaliador('Bruno'), status: 'designada');
        $this->transferir($designada, $this->finalista('Drone', 43))->assertStatus(422)->assertJsonValidationErrors('avaliacao');

        $this->assertSame(0, RegistroAtividade::where('tipo', TipoRegistro::AvaliacaoPresencialTransferida)->count());
    }

    public function test_destinos_trazem_a_vaga_prevista_o_bloqueio_e_aceitam_busca(): void
    {
        $errado = $this->finalista('Abelhas', 41);
        $this->finalista('Horta vertical', 42);
        $this->finalista('Drone agrícola', 7, 'B');
        $jaAvaliado = $this->finalista('Compostagem', 43);
        $ana = $this->avaliador('Ana Souza', comissao: true);
        $avaliacao = $this->avaliacao($errado, $ana, 'comite');
        $this->avaliacao($jaAvaliado, $ana, 'comite');

        $dados = $this->getJson("/api/v1/admin/presencial/ranking/avaliacoes/{$avaliacao->id}/destinos")
            ->assertOk()->json('data');

        // O próprio projeto fica de fora; o turno da avaliação vem primeiro.
        $this->assertSame(['042', '043', '007'], array_column($dados['destinos'], 'estande'));
        $this->assertSame(3, $dados['total']);
        $this->assertSame('comite', $dados['destinos'][0]['vaga']);
        $this->assertNull($dados['destinos'][0]['bloqueio']);
        $this->assertSame('já enviou uma avaliação deste projeto', $dados['destinos'][1]['bloqueio']);

        $busca = fn (string $q) => array_column(
            $this->getJson("/api/v1/admin/presencial/ranking/avaliacoes/{$avaliacao->id}/destinos?q=".urlencode($q))->json('data.destinos'),
            'titulo',
        );
        $this->assertSame(['Horta vertical'], $busca('42'));
        $this->assertSame(['Drone agrícola'], $busca('agricola'));
        $this->assertSame(['Drone agrícola'], $busca('estande 7'));
    }

    public function test_quem_abre_o_mapa_do_evento_tambem_transfere_e_quem_nao_abre_nao(): void
    {
        $errado = $this->finalista('Abelhas', 41);
        $certo = $this->finalista('Horta vertical', 42);
        $avaliacao = $this->avaliacao($errado, $this->avaliador('Ana Souza'));

        $soProjetos = User::factory()->admin()->create();
        $soProjetos->escopos()->attach(EscopoAdmin::create(['nome' => 'Projetos', 'abas' => ['projetos']])->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($soProjetos->fresh());
        $this->postJson("/api/v1/admin/presencial/ranking/projetos/{$errado->id}/avaliacoes")->assertForbidden();
        $this->transferir($avaliacao, $certo)->assertForbidden();

        $soMapa = User::factory()->admin()->create();
        $soMapa->escopos()->attach(EscopoAdmin::create(['nome' => 'Mapa', 'abas' => ['mapa']])->id, ['edicao_id' => $this->edicao->id]);
        Sanctum::actingAs($soMapa->fresh());
        $this->postJson("/api/v1/admin/presencial/ranking/projetos/{$errado->id}/avaliacoes")->assertOk();
        $this->transferir($avaliacao, $certo)->assertOk();
    }
}
