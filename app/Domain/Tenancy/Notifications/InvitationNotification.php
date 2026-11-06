<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token, private readonly string $tenantName) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/accept-invitation?token='.$this->token);

        return (new MailMessage)
            ->subject('Invitación a '.$this->tenantName)
            ->line('Has sido invitado a unirte al estudio.')
            ->action('Aceptar invitación', $url)
            ->line('Este enlace expira en 7 días.');
    }
}
