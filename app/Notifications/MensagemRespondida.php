<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa o orientador/avaliador, por e-mail, que sua mensagem no chat de suporte
 * foi respondida — incluindo o texto da resposta do suporte.
 */
class MensagemRespondida extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resposta) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sua mensagem ao suporte da FETECMS foi respondida')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Respondemos a mensagem que você enviou pelo chat de suporte da FETECMS:')
            ->line('"'.$this->resposta.'"')
            ->action('Abrir o portal', url('/'))
            ->line('Se precisar, é só responder pelo chat dentro do portal. Para assuntos mais complexos, use o e-mail fetecms@gmail.com.')
            ->salutation("Equipe FETECMS\nfetecms@gmail.com");
    }
}
