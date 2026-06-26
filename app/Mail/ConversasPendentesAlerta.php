<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta diário ao suporte: há X mensagens de orientadores/avaliadores ainda
 * não respondidas (status "não visualizada" ou "visualizada").
 */
class ConversasPendentesAlerta extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public int $quantidade) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "FETECMS · {$this->quantidade} mensagem(ns) de suporte sem resposta",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.conversas-pendentes',
            with: ['quantidade' => $this->quantidade],
        );
    }
}
