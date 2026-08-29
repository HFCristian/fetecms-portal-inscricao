<?php

namespace Tests\Feature\Auth;

use App\Mail\MensagemTransacional;
use App\Models\Area;
use App\Models\CadastroPendente;
use App\Models\User;
use App\Services\ConfirmacaoCadastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConfirmacaoCadastroTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'João da Silva Santos',
            'email' => 'joao@escola.ms.gov.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'telefone' => '(67) 99999-1234',
            'data_nascimento' => '1985-03-15',
            'genero' => 'M',
            'camiseta' => 'G',
        ], $overrides);
    }

    /** Abre o cadastro pendente e devolve [token, código]. */
    private function iniciar(array $overrides = []): array
    {
        Mail::fake();

        $token = $this->postJson('/api/v1/orientadores', $this->payload($overrides))
            ->assertStatus(202)
            ->json('data.token');

        return [$token, $this->codigoEnviado()];
    }

    public function test_cadastro_nao_cria_conta_antes_da_confirmacao(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/orientadores', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('data.email', 'joao@escola.ms.gov.br')
            ->assertJsonPath('data.validade_minutos', ConfirmacaoCadastroService::VALIDADE_MINUTOS)
            // O token é o único segredo que a tela guarda; código nenhum sai na resposta.
            ->assertJsonMissingPath('data.codigo');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orientador_profiles', 0);
        $this->assertDatabaseHas('cadastros_pendentes', ['email' => 'joao@escola.ms.gov.br', 'papel' => 'orientador']);

        Mail::assertSent(MensagemTransacional::class, fn (MensagemTransacional $m) => $m->hasTo('joao@escola.ms.gov.br'));
    }

    public function test_codigo_correto_cria_a_conta_e_ja_loga(): void
    {
        [$token, $codigo] = $this->iniciar();

        $this->postJson("/api/v1/cadastros/{$token}/confirmar", ['codigo' => $codigo])
            ->assertCreated()
            ->assertJsonPath('data.email', 'joao@escola.ms.gov.br')
            ->assertJsonPath('data.role', 'orientador');

        $this->assertDatabaseHas('users', ['email' => 'joao@escola.ms.gov.br']);
        $this->assertDatabaseHas('orientador_profiles', ['cpf' => '52998224725']);
        // O pendente sai da tabela assim que vira conta.
        $this->assertDatabaseCount('cadastros_pendentes', 0);

        // A senha continua sendo a que a pessoa digitou (o payload guarda o hash).
        $this->assertTrue(password_verify('Senha@123', User::first()->password));
    }

    public function test_codigo_errado_nao_cria_conta_e_esgota_as_tentativas(): void
    {
        [$token] = $this->iniciar();

        for ($i = 0; $i < ConfirmacaoCadastroService::MAX_TENTATIVAS; $i++) {
            $this->postJson("/api/v1/cadastros/{$token}/confirmar", ['codigo' => '000000'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('codigo');
        }

        $this->assertDatabaseCount('users', 0);

        // Esgotado, nem o código certo passa: tem de pedir um novo.
        $this->postJson("/api/v1/cadastros/{$token}/confirmar", ['codigo' => CadastroPendente::first()->token])
            ->assertStatus(422);
    }

    public function test_codigo_expira_em_15_minutos(): void
    {
        [$token, $codigo] = $this->iniciar();

        $this->travel(ConfirmacaoCadastroService::VALIDADE_MINUTOS + 1)->minutes();

        $this->postJson("/api/v1/cadastros/{$token}/confirmar", ['codigo' => $codigo])
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_mesmo_cpf_pode_ser_cadastrado_de_novo_enquanto_nao_confirma(): void
    {
        // Errou o e-mail no primeiro cadastro e recomeçou do zero com o mesmo CPF.
        $this->iniciar(['email' => 'errado@escola.ms.gov.br']);

        [$token, $codigo] = $this->iniciar(['email' => 'certo@escola.ms.gov.br']);

        $this->postJson("/api/v1/cadastros/{$token}/confirmar", ['codigo' => $codigo])
            ->assertCreated()
            ->assertJsonPath('data.email', 'certo@escola.ms.gov.br');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_pessoa_corrige_o_email_e_recebe_o_codigo_no_endereco_novo(): void
    {
        [$token] = $this->iniciar();

        $this->travel(ConfirmacaoCadastroService::INTERVALO_REENVIO_SEGUNDOS + 1)->seconds();

        $this->patchJson("/api/v1/cadastros/{$token}/email", ['email' => 'certo@escola.ms.gov.br'])
            ->assertOk()
            ->assertJsonPath('data.email', 'certo@escola.ms.gov.br');

        Mail::assertSent(MensagemTransacional::class, fn (MensagemTransacional $m) => $m->hasTo('certo@escola.ms.gov.br'));

        $this->postJson("/api/v1/cadastros/{$token}/confirmar", ['codigo' => $this->codigoEnviado()])
            ->assertCreated()
            ->assertJsonPath('data.email', 'certo@escola.ms.gov.br');
    }

    public function test_reenvio_respeita_o_intervalo_minimo(): void
    {
        [$token] = $this->iniciar();

        $this->postJson("/api/v1/cadastros/{$token}/reenviar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');

        $this->travel(ConfirmacaoCadastroService::INTERVALO_REENVIO_SEGUNDOS + 1)->seconds();

        $this->postJson("/api/v1/cadastros/{$token}/reenviar")->assertOk();
    }

    public function test_email_com_espacos_e_limpo_antes_de_gravar(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/orientadores', $this->payload(['email' => "  joao @escola. ms.gov.br \u{00A0}"]))
            ->assertStatus(202)
            ->assertJsonPath('data.email', 'joao@escola.ms.gov.br');
    }

    public function test_avaliador_passa_pela_mesma_confirmacao(): void
    {
        Mail::fake();

        $area = Area::create(['nome' => 'Ciências Exatas']);

        $inicio = $this->postJson('/api/v1/avaliadores', [
            'name' => 'Ana Avaliadora',
            'email' => 'ana@uems.br',
            'password' => 'Senha@123',
            'password_confirmation' => 'Senha@123',
            'cpf' => '529.982.247-25',
            'titulacao' => 'Mestrado (concluído)',
            'area_id' => $area->id,
        ])->assertStatus(202);

        $this->assertDatabaseCount('users', 0);

        $this->postJson('/api/v1/cadastros/'.$inicio->json('data.token').'/confirmar', ['codigo' => $this->codigoEnviado()])
            ->assertCreated()
            ->assertJsonPath('data.role', 'avaliador');

        $this->assertDatabaseHas('avaliador_profiles', ['cpf' => '52998224725']);
    }

    public function test_token_desconhecido_da_404(): void
    {
        $this->postJson('/api/v1/cadastros/nao-existe/confirmar', ['codigo' => '123456'])->assertNotFound();
    }
}
