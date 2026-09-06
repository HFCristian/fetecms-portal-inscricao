<?php

namespace Tests\Feature;

use App\Mail\MensagemTransacional;
use Tests\TestCase;

/**
 * As views do e-mail transacional (confirmação de cadastro, projeto submetido,
 * convite de feedback) renderizam com qualquer versão do Mailable.
 *
 * É a mesma armadilha da mala direta: a view é lida do **disco** a cada envio,
 * enquanto o worker da fila carrega a classe **uma vez** e fica com ela em
 * memória. Uma view que dependa das chaves do `with` entrega o corpo escapado
 * quando as chaves não vêm — com `<strong>` à mostra para quem recebe. Aqui o
 * formato sai da propriedade pública `$formato`, que chega sempre.
 */
class TransacionalViewTest extends TestCase
{
    public function test_view_html_preserva_a_formatacao_sem_as_chaves_do_with(): void
    {
        $html = view('emails.transacional', [
            'assunto' => 'Confirme seu cadastro',
            'corpo' => '<p>Olá, <strong>Ana</strong>!</p>',
            'destaque' => null,
            'formato' => 'html',
        ])->render();

        $this->assertStringContainsString('<strong>Ana</strong>', $html);
        $this->assertStringNotContainsString('&lt;strong&gt;', $html);
    }

    public function test_view_html_destaca_o_codigo_sem_as_chaves_do_with(): void
    {
        $html = view('emails.transacional', [
            'assunto' => 'Confirme seu cadastro',
            'corpo' => '<p>Seu código:</p><p>123456</p>',
            'destaque' => '123456',
            'formato' => 'html',
        ])->render();

        // O parágrafo do código vira o bloco grande mesmo sem o corpo pronto.
        $this->assertMatchesRegularExpression('/<p style="[^"]*letter-spacing:8px[^"]*">123456<\/p>/', $html);
    }

    public function test_view_texto_puro_continua_escapando_o_que_o_admin_digitou(): void
    {
        $html = view('emails.transacional', [
            'assunto' => 'Aviso',
            'corpo' => 'Cuidado com <script>alert(1)</script>.',
            'destaque' => null,
            'formato' => 'texto',
        ])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_view_texto_converte_o_html_sem_texto_simples(): void
    {
        $texto = view('emails.transacional-texto', [
            'corpo' => '<p>Olá, <strong>Ana</strong>!</p>',
            'formato' => 'html',
        ])->render();

        $this->assertStringContainsString('Olá, Ana!', $texto);
        $this->assertStringNotContainsString('<strong>', $texto);
    }

    public function test_mensagem_atual_mantem_a_formatacao_do_editor(): void
    {
        $mensagem = new MensagemTransacional(
            assunto: 'Confirme seu cadastro',
            corpo: '<p>Olá, <strong>Ana</strong>! Seu código:</p><p>123456</p>',
            destaque: '123456',
            formato: 'html',
        );

        $html = view('emails.transacional', array_merge($mensagem->content()->with, [
            'assunto' => $mensagem->assunto,
            'corpo' => $mensagem->corpo,
            'destaque' => $mensagem->destaque,
            'formato' => $mensagem->formato,
        ]))->render();

        $this->assertStringContainsString('<strong>Ana</strong>', $html);
        $this->assertStringContainsString('letter-spacing:8px', $html);
    }
}
