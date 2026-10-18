<?php

namespace Tests;

use App\Mail\MensagemTransacional;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cadastro completo de orientador: preenche o formulário e digita o código
     * de confirmação que chegou por e-mail.
     */
    protected function cadastrarOrientadorPelaApi(array $payload): TestResponse
    {
        return $this->cadastrarConfirmando('/api/v1/orientadores', $payload);
    }

    /** O mesmo para o avaliador. */
    protected function cadastrarAvaliadorPelaApi(array $payload): TestResponse
    {
        return $this->cadastrarConfirmando('/api/v1/avaliadores', $payload);
    }

    /**
     * Faz as duas pontas do cadastro (POST + confirmação) e devolve a resposta
     * da confirmação — que é onde a conta nasce. Erro de validação no
     * formulário volta como está, para o teste inspecionar.
     */
    protected function cadastrarConfirmando(string $rota, array $payload): TestResponse
    {
        Mail::fake();

        $inicio = $this->postJson($rota, $payload);

        if ($inicio->status() !== 202) {
            return $inicio;
        }

        return $this->postJson(
            '/api/v1/cadastros/'.$inicio->json('data.token').'/confirmar',
            ['codigo' => $this->codigoEnviado()],
        );
    }

    /**
     * A agenda da avaliação presencial (Sprint 224) com o mesmo horário em
     * cada dia pedido — a avaliação em branco vai do início do turno até 30
     * minutos depois do fim.
     *
     * @param  array<string, array<string, string>>  $horarios  turno => {inicio, fim[, avaliacao_inicio, avaliacao_fim]}
     * @return array<string, array<string, array<string, string>>>
     */
    protected static function agendaPresencial(array $horarios, string ...$dias): array
    {
        return array_fill_keys($dias, $horarios);
    }

    /** O código de 6 dígitos do último e-mail de confirmação disparado. */
    protected function codigoEnviado(): string
    {
        $codigo = null;

        Mail::assertSent(MensagemTransacional::class, function (MensagemTransacional $m) use (&$codigo) {
            if ($m->destaque !== null) {
                $codigo = $m->destaque;
            }

            return true;
        });

        return (string) $codigo;
    }
}
