<?php

namespace Tests\Feature;

use App\Mail\MalaDiretaMensagem;
use App\Models\MalaDireta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As views do e-mail da mala direta renderizam mesmo com um payload incompleto.
 *
 * Em produção o disparo de 242 destinatários falhou com "Undefined variable
 * $html": o worker da fila estava com a classe `MalaDiretaMensagem` ANTIGA em
 * memória (ela só passou a mandar `html`/`textoSimples` na v1.18) enquanto a
 * view NOVA era lida do disco a cada envio. Não dá para impedir um worker
 * desatualizado pelo código, mas dá para a view não depender de chave nenhuma
 * do `with`: `$mala` e `$corpo` são propriedades públicas do Mailable e chegam
 * sempre.
 */
class MalaDiretaViewTest extends TestCase
{
    use RefreshDatabase;

    private function mala(array $over = []): MalaDireta
    {
        return MalaDireta::create(array_merge([
            'nome' => 'Comunicado',
            'justificativa' => 'Divulgação da programação.',
            'assunto' => 'Programação da feira',
            'corpo' => 'Olá, Ana!',
            'formato' => 'texto',
            'publicos' => ['todos'],
            'status' => 'enviando',
        ], $over));
    }

    public function test_view_html_renderiza_sem_as_chaves_novas_do_mailable(): void
    {
        $mala = $this->mala();

        // Exatamente o que a classe antiga mandava: só `paragrafos`.
        $html = view('emails.mala-direta', [
            'mala' => $mala,
            'corpo' => $mala->corpo,
            'paragrafos' => ['Olá, Ana!', 'Até lá.'],
        ])->render();

        $this->assertStringContainsString('Olá, Ana!', $html);
        $this->assertStringContainsString('Até lá.', $html);
    }

    public function test_view_html_cai_para_o_corpo_quando_nem_paragrafos_vem(): void
    {
        $mala = $this->mala(['corpo' => "Primeiro parágrafo.\n\nSegundo parágrafo."]);

        $html = view('emails.mala-direta', ['mala' => $mala, 'corpo' => $mala->corpo])->render();

        $this->assertStringContainsString('Primeiro parágrafo.', $html);
        $this->assertStringContainsString('Segundo parágrafo.', $html);
    }

    public function test_view_texto_renderiza_sem_texto_simples(): void
    {
        $mala = $this->mala();

        $texto = view('emails.mala-direta-texto', ['mala' => $mala, 'corpo' => 'Olá, Ana!'])->render();

        $this->assertStringContainsString('Olá, Ana!', $texto);
    }

    public function test_mensagem_atual_continua_montando_o_corpo_em_texto(): void
    {
        $mala = $this->mala(['corpo' => "Linha um.\n\nLinha dois."]);
        $mensagem = new MalaDiretaMensagem($mala, $mala->corpo);

        $dados = $mensagem->content()->with;

        $this->assertFalse($dados['html']);
        $this->assertSame(['Linha um.', 'Linha dois.'], $dados['paragrafos']);

        $html = view('emails.mala-direta', array_merge($dados, [
            'mala' => $mala,
            'corpo' => $mala->corpo,
        ]))->render();

        $this->assertStringContainsString('Linha um.', $html);
    }
}
