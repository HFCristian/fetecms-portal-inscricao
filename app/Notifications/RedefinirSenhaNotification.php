<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail com o link temporário de redefinição de senha (token de uso único).
 * O link aponta para a tela do SPA (/redefinir-senha) e expira conforme
 * config/auth.php ('passwords.users.expire' = 30 min).
 */
class RedefinirSenhaNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 30);

        return (new MailMessage)
            ->subject('Redefinição de senha — Portal XVI FETECMS')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos um pedido para redefinir a senha da sua conta no Portal da XVI FETECMS.')
            ->action('Redefinir minha senha', $this->urlDeRedefinicao($notifiable))
            ->line('Este link é válido por '.$minutos.' minutos e só pode ser usado uma vez.')
            ->line('Se você não solicitou a redefinição, ignore este e-mail — sua senha continua a mesma.')
            ->salutation("Equipe FETECMS\nfetecms@gmail.com");
    }

    /** Monta o link do SPA com token e e-mail (o front os reenvia ao confirmar). */
    private function urlDeRedefinicao(object $notifiable): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base.'/redefinir-senha?token='.$this->token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());
    }
}
