<?php

namespace App\Mail;

use App\Models\MalaDireta;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A mensagem de uma mala direta. O corpo chega já personalizado (variáveis
 * trocadas pelo MalaDiretaService); aqui só entra no layout da FETECMS.
 *
 * O "solicitante" da mala é metadado interno: não aparece para o destinatário.
 */
class MalaDiretaMensagem extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MalaDireta $mala,
        public string $corpo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mala->assunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mala-direta',
            text: 'emails.mala-direta-texto',
            with: ['paragrafos' => $this->paragrafos()],
        );
    }

    /**
     * Quebra o texto em parágrafos (linha em branco separa) para o HTML não
     * sair como um bloco só.
     *
     * @return array<int, string>
     */
    public function paragrafos(): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", trim($this->corpo));

        return array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}/', $texto) ?: []),
            fn (string $p) => $p !== '',
        ));
    }
}
