<?php

namespace App\Notifications;

use App\Models\Signing\SigningRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SigningRequestCompletedNotification extends Notification
{
    public function __construct(public readonly SigningRequest $signingRequest) {}

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
            ->subject('Färdigsignerat: '.$this->signingRequest->name)
            ->line('Alla undertecknare har signerat "'.$this->signingRequest->name.'". Det signerade dokumentet är sparat i Membrum.')
            ->action('Se ärendet', route('admin.signing.show', $this->signingRequest));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Färdigsignerat: '.$this->signingRequest->name,
            'url' => route('admin.signing.show', $this->signingRequest),
            'icon' => 'pen',
        ];
    }
}
