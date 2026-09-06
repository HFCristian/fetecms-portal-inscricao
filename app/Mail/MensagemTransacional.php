<?php

namespace App\Mail;

use App\Support\HtmlEmail;
use App\Support\MensagemEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail transacional do portal (confirmação de cadastro, aviso de projeto
 * submetido). Assunto e corpo chegam prontos — já personalizados por quem
 * chamou —, aqui só entram no layout da FETECMS.
 *
 * `destaque` é o parágrafo que o layout desenha em bloco grande (o código de
 * 6 dígitos). O texto continua sendo do admin: se o corpo não citar o código,
 * nada é destacado.
 *
 * `formato` diz de onde veio o corpo: **`texto`** (puro, quebrado em parágrafos
 * aqui) ou **`html`** (Sprint 91, escrito no editor rico e já sanitizado na
 * gravação). Nos dois casos sai uma versão text/plain junto.
 */
class MensagemTransacional extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $assunto,
        public string $corpo,
        public ?string $destaque = null,
        public string $formato = 'texto',
    ) {}

    public function ehHtml(): bool
    {
        return $this->formato === 'html';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->assunto);
    }

    /**
     * O layout e a versão text/plain.
     *
     * O `with` daqui é só **atalho**: as views sabem se virar sem nenhuma destas
     * chaves, porque um worker de fila com a classe antiga em memória renderiza
     * as views novas do disco. Por isso `corpo` NÃO é sobrescrito aqui — a view
     * precisa do corpo cru para decidir o que fazer com ele.
     */
    public function content(): Content
    {
        $html = $this->ehHtml();

        return new Content(
            view: 'emails.transacional',
            text: 'emails.transacional-texto',
            with: [
                // No corpo em HTML o destaque é aplicado dentro do próprio
                // markup; no texto puro o layout compara parágrafo a parágrafo.
                'corpoHtml' => $html && $this->destaque !== null
                    ? HtmlEmail::destacar($this->corpo, $this->destaque, HtmlEmail::ESTILO_DESTAQUE)
                    : $this->corpo,
                'paragrafos' => $html ? [] : MensagemEmail::paragrafos($this->corpo),
                'textoSimples' => $html ? HtmlEmail::paraTexto($this->corpo) : $this->corpo,
            ],
        );
    }
}
