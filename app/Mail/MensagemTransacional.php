<?php

namespace App\Mail;

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
 */
class MensagemTransacional extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $assunto,
        public string $corpo,
        public ?string $destaque = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->assunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transacional',
            text: 'emails.transacional-texto',
            with: [
                'paragrafos' => MensagemEmail::paragrafos($this->corpo),
                'destaque' => $this->destaque,
            ],
        );
    }
}
