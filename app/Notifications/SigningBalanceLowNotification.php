<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SigningBalanceLowNotification extends Notification
{
    public function __construct(public readonly int $balanceOre, public readonly int $thresholdOre) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->wantsEmailForNotifications()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Lågt saldo hos DocSign: '.format_ore($this->balanceOre))
            ->line('Saldot för e-signering hos DocSign är '.format_ore($this->balanceOre).', under gränsen '.format_ore($this->thresholdOre).'.')
            ->line('En signatur kostar 1,25 kr. Fyll på hos Inleed så att dokument kan skickas för signering.')
            ->action('Se e-signering', route('admin.signing.index'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Lågt saldo hos DocSign: '.format_ore($this->balanceOre).'. Fyll på hos Inleed.',
            'url' => route('admin.signing.index'),
            'icon' => 'pen',
        ];
    }
}
