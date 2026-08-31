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

    /** Como o layout desenha o parágrafo destacado (o código de 6 dígitos). */
    private const ESTILO_DESTAQUE = 'margin:0 0 16px;padding:16px;background-color:#f4f1f7;'
        .'border-radius:12px;text-align:center;font-family:\'Courier New\',Courier,monospace;'
        .'font-size:32px;font-weight:700;letter-spacing:8px;color:#43157A;';

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

    public function content(): Content
    {
        $html = $this->ehHtml();

        return new Content(
            view: 'emails.transacional',
            text: 'emails.transacional-texto',
            with: [
                'html' => $html,
                // No corpo em HTML o destaque é aplicado dentro do próprio
                // markup; no texto puro, o layout compara parágrafo a parágrafo.
                'corpoHtml' => $html && $this->destaque !== null
                    ? HtmlEmail::destacar($this->corpo, $this->destaque, self::ESTILO_DESTAQUE)
                    : $this->corpo,
                'paragrafos' => $html ? [] : MensagemEmail::paragrafos($this->corpo),
                'destaque' => $this->destaque,
                'corpo' => $html ? HtmlEmail::paraTexto($this->corpo) : $this->corpo,
            ],
        );
    }
}
