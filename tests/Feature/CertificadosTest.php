<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 163 — aba Certificados: as fases separadas no cadastro do avaliador,
 * a declaração nominal de cada avaliador e o CPF do admin. A lista de
 * certificados (Sprints 259–260) está em {@see CertificadoListaTest}.
 */
class CertificadosTest extends TestCase
{
    use RefreshDatabase;

    private Edicao $edicao;

    private User $ana;

    private User $bruno;

    private Projeto $finalista;

    private Projeto $naoFinalista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edicao = Edicao::create(['nome' => 'XVI FETECMS', 'ano' => 2026, 'padrao' => true, 'inscricoes_abertas' => true]);

        $this->finalista = Projeto::factory()->submetido()->create(['titulo' => 'Biofiltro', 'edicao_id' => $this->edicao->id]);
        $this->naoFinalista = Projeto::factory()->submetido()->create(['titulo' => 'Compostagem', 'edicao_id' => $this->edicao->id]);
        Aluno::factory()->create(['projeto_id' => $this->finalista->id, 'nome' => 'Zuleica Nunes', 'cpf' => '11144477735']);
        Aluno::factory()->create(['projeto_id' => $this->naoFinalista->id, 'nome' => 'Wagner Lima', 'cpf' => '22233344405']);

        $lista = ListaFinal::create(['edicao_id' => $this->edicao->id, 'nome' => 'Oficial', 'vigente' => true, 'demo' => false, 'versao' => 1]);
        $lista->projetos()->attach($this->finalista->id);

        $this->ana = $this->avaliador('Ana Avaliadora', '52998224725', comissao: true);
        $this->bruno = $this->avaliador('Bruno Avaliador', '11144477735');

        // Ana: duas online e uma presencial. Bruno: só presencial.
        $this->concluida($this->ana, $this->finalista);
        $this->concluida($this->ana, $this->naoFinalista);
        AvaliacaoPresencial::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $this->finalista->id, 'avaliador_id' => $this->ana->id, 'status' => StatusAvaliacao::Concluida, 'concluida_em' => now()]);
        AvaliacaoPresencial::create(['edicao_id' => $this->edicao->id, 'projeto_id' => $this->finalista->id, 'avaliador_id' => $this->bruno->id, 'status' => StatusAvaliacao::Concluida, 'concluida_em' => now()]);
        // Em andamento não conta.
        Avaliacao::create(['projeto_id' => $this->finalista->id, 'avaliador_id' => $this->bruno->id, 'status' => StatusAvaliacao::EmAndamento]);

        Sanctum::actingAs(User::factory()->admin()->create(['name' => 'Olga Organizadora', 'cpf' => '33344455566']));
    }

    private function avaliador(string $nome, string $cpf, bool $comissao = false): User
    {
        $user = User::factory()->create(['role' => Role::Avaliador, 'name' => $nome]);
        AvaliadorProfile::factory()->create(['user_id' => $user->id, 'cpf' => $cpf, 'comissao_especial' => $comissao]);

        return $user;
    }

    private function concluida(User $avaliador, Projeto $projeto): void
    {
        Avaliacao::create([
            'projeto_id' => $projeto->id, 'avaliador_id' => $avaliador->id,
            'status' => StatusAvaliacao::Concluida, 'nota' => 8, 'concluida_em' => now(),
        ]);
    }

    public function test_a_tabela_de_avaliadores_online_mostra_a_fase_presencial_no_mesmo_cadastro(): void
    {
        $linhas = collect($this->getJson('/api/v1/admin/avaliacao/avaliadores')->assertOk()->json('data'));

        $bruno = $linhas->firstWhere('nome', 'Bruno Avaliador');
        $this->assertSame(0, $bruno['avaliou']);
        $this->assertSame(1, $bruno['presenciais']);
    }

    public function test_declaracao_nominal_lista_os_titulos_por_fase(): void
    {
        $this->getJson("/api/v1/admin/certificados/avaliadores/{$this->ana->id}/projetos")
            ->assertOk()
            ->assertJsonCount(2, 'data.online')
            ->assertJsonCount(1, 'data.presencial')
            ->assertJsonPath('data.presencial.0.titulo', 'Biofiltro');

        $pdf = $this->get("/api/v1/admin/certificados/avaliadores/{$this->ana->id}/declaracao")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

    }

    public function test_cpf_do_admin_e_opcional_no_cadastro(): void
    {
        $this->postJson('/api/v1/admin/admins', [
            'name' => 'Paula', 'email' => 'paula@fetec.test', 'cpf' => '529.982.247-25',
            'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1',
        ])->assertCreated()->assertJsonPath('data.cpf', '52998224725');

        $this->postJson('/api/v1/admin/admins', [
            'name' => 'Rui', 'email' => 'rui@fetec.test', 'cpf' => '123',
            'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1',
        ])->assertStatus(422)->assertJsonValidationErrors('cpf');
    }
}
